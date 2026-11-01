<?php

declare(strict_types=1);

namespace App\Telemetry;

use App\Client\RedisClient;

/**
 * 客户端前端遥测数据采集与指标聚合服务。
 *
 * 聚合 API 性能（请求量、响应时延、状态码分布）、
 * Core Web Vitals（FCP、LCP、CLS、TTFB、INP）及前端异常日志。
 */
final class TelemetryService
{
    private const TTL_WEEK = 604800; // 7 天
    private const MAX_BATCH_SIZE = 50;

    /** 单日端点聚合键上限；超出的端点归并到 `:other`，避免 hash 无界增长 */
    private const MAX_ENDPOINT_KEYS = 2000;

    /**
     * 接收并聚合客户端上报的遥测指标项。
     *
     * @param array<int, mixed> $items
     * @return int 成功处理条数
     */
    public static function recordBatch(array $items): int
    {
        if ($items === []) {
            return 0;
        }

        $items = array_slice($items, 0, self::MAX_BATCH_SIZE);
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            // Redis 不可用时并没有真正落库，不能谎报“已处理”
            return 0;
        }

        $now = time();
        $day = date('Ymd', $now);
        $hour = date('YmdH', $now);

        $summaryKey = "telemetry:summary:{$day}";
        $hourlyKey = "telemetry:hourly:{$hour}";
        $endpointKey = "telemetry:endpoints:{$day}";
        $vitalsKey = "telemetry:vitals:{$day}";
        $slowListKey = 'telemetry:recent_slow';
        $errorListKey = 'telemetry:recent_errors';

        $processed = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? 'api');

            if ($type === 'api') {
                $endpoint = self::cleanEndpoint((string) ($item['endpoint'] ?? '/'));
                $method = strtoupper((string) ($item['method'] ?? 'GET'));
                $duration = max(0.0, (float) ($item['duration'] ?? 0.0));
                $status = (int) ($item['status'] ?? 200);

                $statusGroup = self::resolveStatusGroup($status);

                // 1. 全天全站概况
                $redis->hIncrBy($summaryKey, 'total_requests', 1);
                $redis->hIncrBy($summaryKey, $statusGroup, 1);
                $redis->hIncrByFloat($summaryKey, 'duration_sum', $duration);
                $redis->hIncrBy($summaryKey, 'duration_count', 1);

                // 2. 小时级趋势
                $redis->hIncrBy($hourlyKey, 'requests', 1);
                $redis->hIncrByFloat($hourlyKey, 'duration_sum', $duration);
                $redis->hIncrBy($hourlyKey, 'duration_count', 1);
                if ($status >= 400) {
                    $redis->hIncrBy($hourlyKey, 'errors', 1);
                }

                // 3. 接口维度排行榜（键数量封顶，超出归并到 :other）
                $epField = "{$method} {$endpoint}";
                if (!self::endpointKeyAllowed($redis, $endpointKey, $epField)) {
                    $epField = "{$method} :other";
                }
                $redis->hIncrBy($endpointKey, "{$epField}:count", 1);
                $redis->hIncrByFloat($endpointKey, "{$epField}:duration_sum", $duration);

                // 4. 慢请求或异常请求入列（>500ms 或 状态码 >= 400）
                if ($duration >= 500.0 || $status >= 400) {
                    $slowEntry = json_encode([
                        'id' => bin2hex(random_bytes(5)),
                        'timestamp' => $now,
                        'datetime' => date('Y-m-d H:i:s', $now),
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'duration' => round($duration, 1),
                        'status' => $status,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($slowEntry !== false) {
                        $redis->lPush($slowListKey, $slowEntry);
                        $redis->lTrim($slowListKey, 0, 99);
                        $redis->expire($slowListKey, self::TTL_WEEK);
                    }
                }

                $processed++;
            } elseif ($type === 'web_vitals') {
                $name = strtoupper((string) ($item['name'] ?? ''));
                if (in_array($name, ['FCP', 'LCP', 'CLS', 'FID', 'INP', 'TTFB'], true)) {
                    $value = max(0.0, (float) ($item['value'] ?? 0.0));
                    $rating = (string) ($item['rating'] ?? 'good');
                    if (!in_array($rating, ['good', 'needs-improvement', 'poor'], true)) {
                        $rating = 'good';
                    }

                    $redis->hIncrBy($vitalsKey, "{$name}:count", 1);
                    $redis->hIncrByFloat($vitalsKey, "{$name}:sum", $value);
                    $redis->hIncrBy($vitalsKey, "{$name}:{$rating}", 1);
                    $redis->hIncrBy($summaryKey, 'total_vitals', 1);
                    $processed++;
                }
            } elseif ($type === 'error') {
                $message = (string) ($item['message'] ?? 'Unknown script error');
                $stack = (string) ($item['stack'] ?? '');
                $url = (string) ($item['url'] ?? '');

                $redis->hIncrBy($summaryKey, 'error_count', 1);
                $redis->hIncrBy($hourlyKey, 'errors', 1);

                $errorEntry = json_encode([
                    'id' => bin2hex(random_bytes(5)),
                    'timestamp' => $now,
                    'datetime' => date('Y-m-d H:i:s', $now),
                    'message' => mb_substr($message, 0, 500),
                    'stack' => mb_substr($stack, 0, 1000),
                    'url' => mb_substr($url, 0, 300),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($errorEntry !== false) {
                    $redis->lPush($errorListKey, $errorEntry);
                    $redis->lTrim($errorListKey, 0, 99);
                    $redis->expire($errorListKey, self::TTL_WEEK);
                }

                $processed++;
            }
        }

        // 统一设置过期时间
        $redis->expire($summaryKey, self::TTL_WEEK);
        $redis->expire($hourlyKey, self::TTL_WEEK);
        $redis->expire($endpointKey, self::TTL_WEEK);
        $redis->expire($vitalsKey, self::TTL_WEEK);

        return $processed;
    }

    /**
     * 获取管理后台遥测统计全景大盘数据。
     *
     * @param ?string $day 日期，格式 Ymd，默认当天
     * @return array<string, mixed>
     */
    public static function getStats(?string $day = null): array
    {
        $day = ($day !== null && preg_match('/^\d{8}$/', $day)) ? $day : date('Ymd');
        $redis = RedisClient::getRedis();

        $emptyOverview = [
            'total_requests' => 0,
            'avg_duration' => 0.0,
            'status_distribution' => [
                '2xx' => 0,
                '3xx' => 0,
                '4xx' => 0,
                '5xx' => 0,
            ],
            'error_count' => 0,
            'total_vitals' => 0,
        ];

        if ($redis === null) {
            return [
                'day' => $day,
                'overview' => $emptyOverview,
                'endpoints' => [],
                'web_vitals' => self::buildDefaultVitals(),
                'hourly_trends' => self::buildEmptyHourlyTrends($day),
                'recent_slow' => [],
                'recent_errors' => [],
            ];
        }

        // 1. Overview
        $summaryKey = "telemetry:summary:{$day}";
        $rawSummary = $redis->hGetAll($summaryKey);
        if (!is_array($rawSummary)) {
            $rawSummary = [];
        }

        $totalRequests = (int) ($rawSummary['total_requests'] ?? 0);
        $durationSum = (float) ($rawSummary['duration_sum'] ?? 0.0);
        $durationCount = (int) ($rawSummary['duration_count'] ?? 0);
        $avgDuration = $durationCount > 0 ? round($durationSum / $durationCount, 1) : 0.0;

        $overview = [
            'total_requests' => $totalRequests,
            'avg_duration' => $avgDuration,
            'status_distribution' => [
                '2xx' => (int) ($rawSummary['status_2xx'] ?? 0),
                '3xx' => (int) ($rawSummary['status_3xx'] ?? 0),
                '4xx' => (int) ($rawSummary['status_4xx'] ?? 0),
                '5xx' => (int) ($rawSummary['status_5xx'] ?? 0),
            ],
            'error_count' => (int) ($rawSummary['error_count'] ?? 0),
            'total_vitals' => (int) ($rawSummary['total_vitals'] ?? 0),
        ];

        // 2. Endpoints Ranking
        $endpointKey = "telemetry:endpoints:{$day}";
        $rawEndpoints = $redis->hGetAll($endpointKey);
        if (!is_array($rawEndpoints)) {
            $rawEndpoints = [];
        }

        $endpointsMap = [];
        foreach ($rawEndpoints as $field => $val) {
            if (str_ends_with((string) $field, ':count')) {
                $base = substr((string) $field, 0, -6);
                $endpointsMap[$base]['count'] = (int) $val;
            } elseif (str_ends_with((string) $field, ':duration_sum')) {
                $base = substr((string) $field, 0, -13);
                $endpointsMap[$base]['duration_sum'] = (float) $val;
            }
        }

        $endpointsList = [];
        foreach ($endpointsMap as $epField => $info) {
            $count = (int) ($info['count'] ?? 0);
            $sum = (float) ($info['duration_sum'] ?? 0.0);
            $avg = $count > 0 ? round($sum / $count, 1) : 0.0;

            [$method, $path] = explode(' ', $epField, 2) + ['GET', '/'];

            $endpointsList[] = [
                'endpoint' => $path,
                'method' => $method,
                'count' => $count,
                'avg_duration' => $avg,
            ];
        }

        // 按调用量倒序，取 Top 20
        usort($endpointsList, static fn($a, $b) => $b['count'] <=> $a['count']);
        $endpointsList = array_slice($endpointsList, 0, 20);

        // 3. Web Vitals
        $vitalsKey = "telemetry:vitals:{$day}";
        $rawVitals = $redis->hGetAll($vitalsKey);
        if (!is_array($rawVitals)) {
            $rawVitals = [];
        }

        $webVitals = [];
        $metricNames = ['FCP', 'LCP', 'CLS', 'TTFB', 'INP'];
        foreach ($metricNames as $name) {
            $count = (int) ($rawVitals["{$name}:count"] ?? 0);
            $sum = (float) ($rawVitals["{$name}:sum"] ?? 0.0);
            $avg = $count > 0 ? round($sum / $count, 2) : 0.0;

            $webVitals[$name] = [
                'name' => $name,
                'count' => $count,
                'avg_value' => $avg,
                'ratings' => [
                    'good' => (int) ($rawVitals["{$name}:good"] ?? 0),
                    'needs_improvement' => (int) ($rawVitals["{$name}:needs-improvement"] ?? 0),
                    'poor' => (int) ($rawVitals["{$name}:poor"] ?? 0),
                ],
            ];
        }

        // 4. Hourly Trends (过去 24 小时)
        $hourlyTrends = [];
        $currentTs = time();
        for ($i = 23; $i >= 0; $i--) {
            $ts = $currentTs - ($i * 3600);
            $hKeyStr = date('YmdH', $ts);
            $label = date('H:00', $ts);

            $hData = $redis->hGetAll("telemetry:hourly:{$hKeyStr}");
            if (!is_array($hData)) {
                $hData = [];
            }

            $reqs = (int) ($hData['requests'] ?? 0);
            $hSum = (float) ($hData['duration_sum'] ?? 0.0);
            $hCount = (int) ($hData['duration_count'] ?? 0);
            $hAvg = $hCount > 0 ? round($hSum / $hCount, 1) : 0.0;
            $errs = (int) ($hData['errors'] ?? 0);

            $hourlyTrends[] = [
                'hour' => $label,
                'requests' => $reqs,
                'avg_duration' => $hAvg,
                'errors' => $errs,
            ];
        }

        // 5. Recent Slow Queries
        $rawSlow = $redis->lRange('telemetry:recent_slow', 0, 19);
        $recentSlow = [];
        if (is_array($rawSlow)) {
            foreach ($rawSlow as $entry) {
                $decoded = json_decode((string) $entry, true);
                if (is_array($decoded)) {
                    $recentSlow[] = $decoded;
                }
            }
        }

        // 6. Recent Frontend Errors
        $rawErrors = $redis->lRange('telemetry:recent_errors', 0, 19);
        $recentErrors = [];
        if (is_array($rawErrors)) {
            foreach ($rawErrors as $entry) {
                $decoded = json_decode((string) $entry, true);
                if (is_array($decoded)) {
                    $recentErrors[] = $decoded;
                }
            }
        }

        return [
            'day' => $day,
            'overview' => $overview,
            'endpoints' => $endpointsList,
            'web_vitals' => $webVitals,
            'hourly_trends' => $hourlyTrends,
            'recent_slow' => $recentSlow,
            'recent_errors' => $recentErrors,
        ];
    }

    /**
     * 清空遥测数据（测试与运维重置）。
     */
    public static function clearStats(): bool
    {
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return false;
        }

        try {
            // SCAN 分批清理，避免 KEYS 阻塞单线程 Redis
            $keys = [];
            $iterator = null;
            do {
                $batch = $redis->scan($iterator, 'telemetry:*', 200);
                if (is_array($batch)) {
                    foreach ($batch as $found) {
                        $keys[] = $found;
                    }
                }
            } while ($iterator !== 0 && $iterator !== null && count($keys) < 50000);

            if ($keys !== []) {
                foreach (array_chunk($keys, 500) as $chunk) {
                    $redis->del(...$chunk);
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 端点路径最大长度：超长路径会成为 Redis hash 的独立 field，可被用来撑爆内存 */
    private const MAX_ENDPOINT_LENGTH = 200;

    /**
     * 端点键配额检查：已存在的键直接放行，新键在达到上限后归并到 `:other`。
     *
     * @param \Redis $redis 运行时保持 object 以兼容测试注入的 RedisMock
     */
    private static function endpointKeyAllowed(object $redis, string $hashKey, string $epField): bool
    {
        if (str_ends_with($epField, ':other')) {
            return true;
        }

        try {
            if ($redis->hExists($hashKey, "{$epField}:count")) {
                return true;
            }
            $size = (int) $redis->hLen($hashKey);
            return $size < self::MAX_ENDPOINT_KEYS * 2; // 每个端点占 count/sum 两个 field
        } catch (\Throwable) {
            // 配额检查失败不应阻断上报
            return true;
        }
    }

    private static function cleanEndpoint(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $uri;
        }

        // 过滤参数与特殊字符，归一化日志 ID 路径，如 /v1/raw/s123456/main -> /v1/raw/:id/main
        $path = preg_replace('#/(?:s|f)[0-9a-zA-Z]{6}(?=/|$)#', '/:id', $path);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        // 公开端点、无鉴权：不截断的话攻击者可用超长路径生成无限多互不相同的
        // hash field（每个都带 :count 与 :duration_sum 两个键）。
        return mb_strcut($path, 0, self::MAX_ENDPOINT_LENGTH, 'UTF-8');
    }

    private static function resolveStatusGroup(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return 'status_2xx';
        }
        if ($status >= 300 && $status < 400) {
            return 'status_3xx';
        }
        if ($status >= 400 && $status < 500) {
            return 'status_4xx';
        }
        if ($status >= 500 && $status < 600) {
            return 'status_5xx';
        }
        return 'status_2xx';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function buildDefaultVitals(): array
    {
        $vitals = [];
        foreach (['FCP', 'LCP', 'CLS', 'TTFB', 'INP'] as $name) {
            $vitals[$name] = [
                'name' => $name,
                'count' => 0,
                'avg_value' => 0.0,
                'ratings' => ['good' => 0, 'needs_improvement' => 0, 'poor' => 0],
            ];
        }
        return $vitals;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function buildEmptyHourlyTrends(string $day): array
    {
        $trends = [];
        for ($h = 0; $h < 24; $h++) {
            $trends[] = [
                'hour' => sprintf('%02d:00', $h),
                'requests' => 0,
                'avg_duration' => 0.0,
                'errors' => 0,
            ];
        }
        return $trends;
    }
}
