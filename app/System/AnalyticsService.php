<?php

declare(strict_types=1);

namespace App\System;

use App\Config;
use Hyperf\DbConnection\Db;

/**
 * 日志生态画像与时序分析服务。
 *
 * 提供来源生态分布、Minecraft 版本与加载器矩阵、时序容量走势等统计分析。
 */
final class AnalyticsService
{
    /** 估算平均单篇体积时的取样条数 */
    private const int AVG_SIZE_SAMPLE_LIMIT = 300;

    /** 窗口内无数据时使用的平均体积兜底值 */
    private const int AVG_SIZE_FALLBACK = 300_000;

    /**
     * 获取指定天数内的客户端来源/生态分布统计。
     *
     * @param int $days 统计天数（1-90）
     * @return array{days: int, total: int, sources: array<int, array{source: string, count: int, percentage: float}>}
     */
    public static function getSourceStats(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $since = time() - ($days * 86400);

        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';

        if ($storageId === 's') {
            return self::getSourceStatsFromMariaDb($days, $since);
        }

        return self::getSourceStatsFromFilesystem($days, $since);
    }

    /**
     * 获取指定天数内的 Minecraft 版本与 Mod Loader 分布。
     *
     * @param int $days 统计天数（1-90）
     * @return array{days: int, versions: array<int, array{name: string, count: int, percentage: float}>, loaders: array<int, array{name: string, count: int, percentage: float}>}
     */
    public static function getVersionStats(int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $since = time() - ($days * 86400);

        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';

        if ($storageId === 's') {
            return self::getVersionStatsFromMariaDb($days, $since);
        }

        return self::getVersionStatsFromFilesystem($days, $since);
    }

    /**
     * 获取指定天数内的日志时序走势（按天统计条数与数据量）。
     *
     * @param int $days 统计天数（1-90）
     * @return array{days: int, total_logs: int, total_bytes: int, trends: array<int, array{date: string, count: int, bytes: int}>}
     */
    public static function getTrends(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        // 起点对齐到首个统计日的本地零点：SQL 分组窗口与预填充日期映射严格重合，
        // 否则窗口首日那半天数据会落在映射之外（MariaDB 追加到序列末尾、文件系统直接丢弃）
        $since = (int) strtotime(date('Y-m-d', time() - (($days - 1) * 86400)) . ' 00:00:00');

        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';

        if ($storageId === 's') {
            return self::getTrendsFromMariaDb($days, $since);
        }

        return self::getTrendsFromFilesystem($days, $since);
    }

    // ── MariaDB 实现 ──

    /**
     * @return array{days: int, total: int, sources: array<int, array{source: string, count: int, percentage: float}>}
     */
    private static function getSourceStatsFromMariaDb(int $days, int $since): array
    {
        try {
            $rows = Db::table('logs')
                ->select([
                    Db::raw("COALESCE(NULLIF(source, ''), '未指定') as src"),
                    Db::raw('COUNT(*) as cnt'),
                ])
                ->where('created', '>=', $since)
                ->groupBy('src')
                ->orderByDesc('cnt')
                ->get();

            $total = 0;
            $items = [];
            foreach ($rows as $row) {
                $c = (int) ($row->cnt ?? 0);
                $total += $c;
                $items[] = [
                    'source' => (string) ($row->src ?? '未指定'),
                    'count' => $c,
                    'percentage' => 0.0,
                ];
            }

            if ($total > 0) {
                foreach ($items as &$item) {
                    $item['percentage'] = round(($item['count'] / $total) * 100, 1);
                }
                unset($item);
            }

            return [
                'days' => $days,
                'total' => $total,
                'sources' => $items,
            ];
        } catch (\Throwable $e) {
            \App\Syslog::error('Analytics', 'MariaDB getSourceStats failed: ' . $e->getMessage());
            return ['days' => $days, 'total' => 0, 'sources' => []];
        }
    }

    /**
     * @return array{days: int, versions: array<int, array{name: string, count: int, percentage: float}>, loaders: array<int, array{name: string, count: int, percentage: float}>}
     */
    private static function getVersionStatsFromMariaDb(int $days, int $since): array
    {
        try {
            // 1. 版本分布（优先查元数据显式标注）
            // 子表主键为 (log_id, key, created)，必须连 created 一起关联：
            // 父表分区被丢弃而子表残留时，同 ID 的新日志会继承孤儿子行。
            $versionRows = Db::table('log_metadata as m')
                ->join('logs as l', function ($join) {
                    $join->on('m.log_id', '=', 'l.id')->on('m.created', '=', 'l.created');
                })
                ->select(['m.value as ver', Db::raw('COUNT(*) as cnt')])
                ->where('l.created', '>=', $since)
                ->where('m.key', 'version')
                ->whereNotNull('m.value')
                ->where('m.value', '!=', '')
                ->groupBy('m.value')
                ->orderByDesc('cnt')
                ->limit(20)
                ->get();

            $versionTotal = 0;
            $versions = [];
            /** @var \stdClass $r */
            foreach ($versionRows as $r) {
                $c = (int) $r->cnt;
                $versionTotal += $c;
                $versions[] = ['name' => (string) $r->ver, 'count' => $c, 'percentage' => 0.0];
            }

            // 2. 加载器分布（优先查元数据显式标注）
            $loaderRows = Db::table('log_metadata as m')
                ->join('logs as l', function ($join) {
                    $join->on('m.log_id', '=', 'l.id')->on('m.created', '=', 'l.created');
                })
                ->select(['m.value as ldr', Db::raw('COUNT(*) as cnt')])
                ->where('l.created', '>=', $since)
                ->whereIn('m.key', ['type', 'loader', 'modloader'])
                ->whereNotNull('m.value')
                ->where('m.value', '!=', '')
                ->groupBy('m.value')
                ->orderByDesc('cnt')
                ->limit(20)
                ->get();

            $loaderTotal = 0;
            $loaders = [];
            /** @var \stdClass $r */
            foreach ($loaderRows as $r) {
                $c = (int) $r->cnt;
                $loaderTotal += $c;
                $loaders[] = ['name' => (string) $r->ldr, 'count' => $c, 'percentage' => 0.0];
            }

            // 若元数据中缺失版本或加载器，启动启发式采样识别（扫描时间窗内最近 100 条日志头部与模组列表）
            // 取样必须按 created 倒序走 idx_created：logs.id 是随机短 ID，按其倒序既不代表
            // 「最近日志」，又要回表逐行过滤 created，代价随窗口占比不可控
            if (empty($versions) || empty($loaders)) {
                $sampleLogs = Db::table('logs')
                    ->select([Db::raw('SUBSTRING(data, 1, 2048) as header')])
                    ->where('created', '>=', $since)
                    ->orderByDesc('created')
                    ->limit(100)
                    ->get();

                if (empty($versions)) {
                    $vCounts = [];
                    foreach ($sampleLogs as $s) {
                        $h = (string) ($s->header ?? '');
                        if (preg_match('/Minecraft(?: Version)?[:\s]+v?([12]\.\d+(?:\.\d+)?)/i', $h, $m)) {
                            $v = $m[1];
                            $vCounts[$v] = ($vCounts[$v] ?? 0) + 1;
                        }
                    }

                    if (count($vCounts) < 3) {
                        // 关联 logs 并带上时间窗：matched_mods 是历史累积的元数据表，
                        // 不带窗口取样会把已过期周期的版本算进当前图表
                        $mods = Db::table('log_metadata as m')
                            ->join('logs as l', function ($join) {
                                $join->on('m.log_id', '=', 'l.id')->on('m.created', '=', 'l.created');
                            })
                            ->where('m.key', 'matched_mods')
                            ->where('l.created', '>=', $since)
                            ->whereNotNull('m.value')
                            ->where('m.value', '!=', '')
                            ->orderByDesc('l.created')
                            ->limit(50)
                            ->pluck('m.value');
                        foreach ($mods as $modStr) {
                            if (is_string($modStr) && preg_match_all('/(?:mc)?(1\.\d+(?:\.\d+)?)/i', $modStr, $matches)) {
                                foreach ($matches[1] as $mv) {
                                    if (version_compare($mv, '1.7.0', '>=')) {
                                        $vCounts[$mv] = ($vCounts[$mv] ?? 0) + 1;
                                    }
                                }
                            }
                        }
                    }

                    arsort($vCounts);
                    $vTotal = array_sum($vCounts);
                    foreach (array_slice($vCounts, 0, 20, true) as $vName => $cnt) {
                        $versions[] = [
                            'name' => (string) $vName,
                            'count' => (int) $cnt,
                            'percentage' => $vTotal > 0 ? round(($cnt / $vTotal) * 100, 1) : 0.0,
                        ];
                    }
                }

                if (empty($loaders)) {
                    $lCounts = [];
                    foreach ($sampleLogs as $s) {
                        $h = (string) ($s->header ?? '');
                        if (stripos($h, 'NeoForge') !== false) {
                            $lCounts['NeoForge'] = ($lCounts['NeoForge'] ?? 0) + 1;
                        } elseif (stripos($h, 'Fabric') !== false) {
                            $lCounts['Fabric'] = ($lCounts['Fabric'] ?? 0) + 1;
                        } elseif (stripos($h, 'Forge') !== false) {
                            $lCounts['Forge'] = ($lCounts['Forge'] ?? 0) + 1;
                        } elseif (stripos($h, 'Quilt') !== false) {
                            $lCounts['Quilt'] = ($lCounts['Quilt'] ?? 0) + 1;
                        }
                    }

                    arsort($lCounts);
                    $lTotal = array_sum($lCounts);
                    foreach ($lCounts as $lName => $cnt) {
                        $loaders[] = [
                            'name' => (string) $lName,
                            'count' => (int) $cnt,
                            'percentage' => $lTotal > 0 ? round(($cnt / $lTotal) * 100, 1) : 0.0,
                        ];
                    }
                }
            }

            if ($versionTotal > 0 && !empty($versions)) {
                foreach ($versions as &$v) {
                    if ($v['percentage'] == 0.0) {
                        $v['percentage'] = round(($v['count'] / $versionTotal) * 100, 1);
                    }
                }
                unset($v);
            }

            if ($loaderTotal > 0 && !empty($loaders)) {
                foreach ($loaders as &$l) {
                    if ($l['percentage'] == 0.0) {
                        $l['percentage'] = round(($l['count'] / $loaderTotal) * 100, 1);
                    }
                }
                unset($l);
            }

            return [
                'days' => $days,
                'versions' => $versions,
                'loaders' => $loaders,
            ];
        } catch (\Throwable $e) {
            \App\Syslog::error('Analytics', 'MariaDB getVersionStats failed: ' . $e->getMessage());
            return ['days' => $days, 'versions' => [], 'loaders' => []];
        }
    }

    /**
     * @return array{days: int, total_logs: int, total_bytes: int, trends: array<int, array{date: string, count: int, bytes: int}>}
     */
    private static function getTrendsFromMariaDb(int $days, int $since): array
    {
        try {
            $rows = Db::table('logs')
                ->select([
                    Db::raw("DATE(FROM_UNIXTIME(created)) as dt"),
                    Db::raw('COUNT(*) as cnt'),
                ])
                ->where('created', '>=', $since)
                ->groupBy('dt')
                ->orderBy('dt', 'asc')
                ->get();

            // 预估单篇日志平均体积，彻底杜绝全表扫描大字段 SUM(LENGTH(data)) 导致的慢查询卡死。
            // 取样限定在当前时间窗内：log_metadata 的 size 键由客户端自报、覆盖率极低且与
            // 主日志体积无关，用它估算会系统性偏离真实数据量。
            $sample = Db::table('logs')
                ->select('id')
                ->where('created', '>=', $since)
                ->orderByDesc('created')
                ->limit(self::AVG_SIZE_SAMPLE_LIMIT);
            $avgSizeRow = Db::table('logs as l')
                ->joinSub($sample, 's', 'l.id', '=', 's.id')
                ->selectRaw('AVG(LENGTH(l.data)) as avg_sz')
                ->first();
            $avgSize = (int) ($avgSizeRow->avg_sz ?? 0);
            if ($avgSize <= 0) {
                $avgSize = self::AVG_SIZE_FALLBACK;
            }

            $totalLogs = 0;
            $totalBytes = 0;

            // 预填充完整日期
            $dateMap = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', time() - ($i * 86400));
                $dateMap[$d] = ['date' => $d, 'count' => 0, 'bytes' => 0];
            }

            /** @var \stdClass $r */
            foreach ($rows as $r) {
                $d = (string) $r->dt;
                $c = (int) $r->cnt;
                $b = (int) ($c * $avgSize);
                $totalLogs += $c;
                $totalBytes += $b;
                if (isset($dateMap[$d])) {
                    $dateMap[$d]['count'] = $c;
                    $dateMap[$d]['bytes'] = $b;
                } else {
                    $dateMap[$d] = ['date' => $d, 'count' => $c, 'bytes' => $b];
                }
            }

            // 日期为 Y-m-d 定宽格式，字典序即时序；映射键可能含窗口外日期（数据库时区与
            // PHP 时区不一致时），排序保证序列始终按时间递增
            ksort($dateMap);
            $trends = array_values($dateMap);

            return [
                'days' => $days,
                'total_logs' => $totalLogs,
                'total_bytes' => $totalBytes,
                'trends' => $trends,
            ];
        } catch (\Throwable $e) {
            \App\Syslog::error('Analytics', 'MariaDB getTrends failed: ' . $e->getMessage());
            return ['days' => $days, 'total_logs' => 0, 'total_bytes' => 0, 'trends' => []];
        }
    }


    // ── Filesystem 实现 ──

    /**
     * @return array{days: int, total: int, sources: array<int, array{source: string, count: int, percentage: float}>}
     */
    private static function getSourceStatsFromFilesystem(int $days, int $since): array
    {
        $config = Config::Get('filesystem');
        $basePath = CORE_PATH . ($config['path'] ?? '/storage/logs/');
        if (!is_dir($basePath)) {
            return ['days' => $days, 'total' => 0, 'sources' => []];
        }

        $counts = [];
        $total = 0;
        $files = glob($basePath . '*.meta.json') ?: [];
        foreach ($files as $metaFile) {
            $content = @file_get_contents($metaFile);
            if (!$content) {
                continue;
            }
            $meta = json_decode($content, true);
            if (!is_array($meta)) {
                continue;
            }
            $created = (int) ($meta['created'] ?? 0);
            if ($created < $since) {
                continue;
            }
            $source = (string) ($meta['source'] ?? '未指定');
            if (trim($source) === '') {
                $source = '未指定';
            }
            $counts[$source] = ($counts[$source] ?? 0) + 1;
            $total++;
        }

        arsort($counts);
        $items = [];
        foreach ($counts as $source => $c) {
            $items[] = [
                'source' => $source,
                'count' => $c,
                'percentage' => $total > 0 ? round(($c / $total) * 100, 1) : 0.0,
            ];
        }

        return [
            'days' => $days,
            'total' => $total,
            'sources' => $items,
        ];
    }

    /**
     * @return array{days: int, versions: array<int, array{name: string, count: int, percentage: float}>, loaders: array<int, array{name: string, count: int, percentage: float}>}
     */
    private static function getVersionStatsFromFilesystem(int $days, int $since): array
    {
        $config = Config::Get('filesystem');
        $basePath = CORE_PATH . ($config['path'] ?? '/storage/logs/');
        if (!is_dir($basePath)) {
            return ['days' => $days, 'versions' => [], 'loaders' => []];
        }

        $versionCounts = [];
        $loaderCounts = [];
        $files = glob($basePath . '*.meta.json') ?: [];
        $sampled = 0;
        $maxSample = 300;

        foreach ($files as $metaFile) {
            if ($sampled >= $maxSample) {
                break;
            }
            $content = @file_get_contents($metaFile);
            if (!$content) {
                continue;
            }
            $meta = json_decode($content, true);
            if (!is_array($meta) || (int) ($meta['created'] ?? 0) < $since) {
                continue;
            }

            // 读取对应主日志
            $rawId = substr(basename($metaFile), 0, -10);
            $logFile = $basePath . $rawId;
            if (!is_file($logFile)) {
                continue;
            }
            $logData = @json_decode((string) file_get_contents($logFile), true);
            if (!is_array($logData)) {
                continue;
            }

            $sampled++;
            $entries = $logData['metadata'] ?? [];
            foreach ($entries as $entry) {
                $k = (string) ($entry['key'] ?? '');
                $v = (string) ($entry['value'] ?? '');
                if ($k === 'version' && $v !== '') {
                    $versionCounts[$v] = ($versionCounts[$v] ?? 0) + 1;
                } elseif (in_array($k, ['type', 'loader', 'modloader'], true) && $v !== '') {
                    $loaderCounts[$v] = ($loaderCounts[$v] ?? 0) + 1;
                }
            }
        }

        arsort($versionCounts);
        arsort($loaderCounts);

        $vTotal = array_sum($versionCounts);
        $versions = [];
        foreach (array_slice($versionCounts, 0, 20, true) as $k => $c) {
            $versions[] = ['name' => (string) $k, 'count' => $c, 'percentage' => $vTotal > 0 ? round(($c / $vTotal) * 100, 1) : 0.0];
        }

        $lTotal = array_sum($loaderCounts);
        $loaders = [];
        foreach (array_slice($loaderCounts, 0, 20, true) as $k => $c) {
            $loaders[] = ['name' => (string) $k, 'count' => $c, 'percentage' => $lTotal > 0 ? round(($c / $lTotal) * 100, 1) : 0.0];
        }

        return ['days' => $days, 'versions' => $versions, 'loaders' => $loaders];
    }

    /**
     * @return array{days: int, total_logs: int, total_bytes: int, trends: array<int, array{date: string, count: int, bytes: int}>}
     */
    private static function getTrendsFromFilesystem(int $days, int $since): array
    {
        $config = Config::Get('filesystem');
        $basePath = CORE_PATH . ($config['path'] ?? '/storage/logs/');

        $dateMap = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - ($i * 86400));
            $dateMap[$d] = ['date' => $d, 'count' => 0, 'bytes' => 0];
        }

        if (!is_dir($basePath)) {
            return ['days' => $days, 'total_logs' => 0, 'total_bytes' => 0, 'trends' => array_values($dateMap)];
        }

        $files = glob($basePath . '*.meta.json') ?: [];
        $totalLogs = 0;
        $totalBytes = 0;

        foreach ($files as $metaFile) {
            $content = @file_get_contents($metaFile);
            if (!$content) {
                continue;
            }
            $meta = json_decode($content, true);
            if (!is_array($meta)) {
                continue;
            }
            $created = (int) ($meta['created'] ?? 0);
            if ($created < $since) {
                continue;
            }

            $d = date('Y-m-d', $created);
            $rawId = substr(basename($metaFile), 0, -10);
            $logFile = $basePath . $rawId;
            $size = is_file($logFile) ? (filesize($logFile) ?: 0) : 0;

            $totalLogs++;
            $totalBytes += $size;

            if (isset($dateMap[$d])) {
                $dateMap[$d]['count']++;
                $dateMap[$d]['bytes'] += $size;
            }
        }

        return [
            'days' => $days,
            'total_logs' => $totalLogs,
            'total_bytes' => $totalBytes,
            'trends' => array_values($dateMap),
        ];
    }
}
