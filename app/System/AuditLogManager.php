<?php

declare(strict_types=1);

namespace App\System;

use App\Client\RedisClient;

/**
 * 管理后台高危操作安全审计日志服务。
 *
 * 记录敏感运维操作（日志删除、IP 封禁、规则变更、配置更新、缓存与队列清理等），
 * 采用 Redis Ring Buffer 优先 + 本地 JSON Lines 追加落盘双层持久化。
 */
final class AuditLogManager
{
    private const LOG_FILE = '/runtime/logs/audit.log';
    private const REDIS_KEY = 'audit:recent';
    private const MAX_RING_BUFFER = 1000;

    /** 单个审计文件上限；超出后轮转，避免运营期单调增长 */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** 轮转保留份数 */
    private const MAX_ROTATED_FILES = 5;

    /** 从文件回退读取时最多扫描的行数（避免整文件读入内存） */
    private const MAX_FILE_READ_BYTES = 2 * 1024 * 1024;

    /**
     * 记录一条管理操作审计日志。
     *
     * @param string $action 操作类型代号 (例如: log.delete, security.ban)
     * @param string $target 操作目标 (例如: 日志 ID, IP, 模块名)
     * @param array<string, mixed> $details 详细参数或上下文
     * @param bool $success 操作是否成功
     * @param string|null $operator 操作人，缺省为 admin
     * @param string|null $ip 客户端 IP
     */
    public static function record(
        string $action,
        string $target,
        array $details = [],
        bool $success = true,
        ?string $operator = null,
        ?string $ip = null
    ): void {
        $entry = [
            'id' => uniqid('aud_', true),
            'time' => time(),
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'success' => $success,
            'operator' => $operator ?? 'admin',
            'ip' => $ip ?? '127.0.0.1',
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        // 1. 写入 Redis 环形缓冲区
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $redis->lPush(self::REDIS_KEY, $json);
                $redis->lTrim(self::REDIS_KEY, 0, self::MAX_RING_BUFFER - 1);
            } catch (\Throwable $e) {
                \App\Syslog::error('AuditLog', 'Redis write failed: ' . $e->getMessage());
            }
        }

        // 2. 本地持久化文件（按行追加 + 超限轮转）
        $filePath = CORE_PATH . self::LOG_FILE;
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        self::rotateIfOversized($filePath);
        @file_put_contents($filePath, $json . "\n", FILE_APPEND | LOCK_EX);
        // 审计日志包含操作者 IP 与目标 ID，不允许组/其他用户读取
        @chmod($filePath, 0600);
    }

    /**
     * 审计文件超过上限时轮转，并只保留最近 N 份。
     */
    private static function rotateIfOversized(string $filePath): void
    {
        clearstatcache(true, $filePath);
        if (!is_file($filePath) || (int) @filesize($filePath) < self::MAX_FILE_SIZE) {
            return;
        }

        $rotated = $filePath . '.' . date('YmdHis');
        if (!@rename($filePath, $rotated)) {
            return;
        }

        $existing = glob($filePath . '.*') ?: [];
        usort($existing, static fn(string $a, string $b): int => strcmp($b, $a));
        foreach (array_slice($existing, self::MAX_ROTATED_FILES) as $stale) {
            @unlink($stale);
        }
    }

    /**
     * 分页查询操作审计日志。
     *
     * @param int $page 当前页码
     * @param int $pageSize 每页大小
     * @param string|null $action 按动作类型过滤
     * @param string|null $keyword 关键词搜索
     * @param int|null $since 起始时间戳
     * @param int|null $until 截止时间戳
     * @return array{total: int, page: int, pageSize: int, items: array<int, mixed>}
     */
    public static function getLogs(
        int $page = 1,
        int $pageSize = 20,
        ?string $action = null,
        ?string $keyword = null,
        ?int $since = null,
        ?int $until = null
    ): array {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));

        $allEntries = [];
        $redis = RedisClient::getRedis();

        // 1. 尝试从 Redis 读取
        if ($redis !== null) {
            try {
                $rawList = $redis->lRange(self::REDIS_KEY, 0, self::MAX_RING_BUFFER - 1);
                if (is_array($rawList) && !empty($rawList)) {
                    foreach ($rawList as $item) {
                        $dec = json_decode((string) $item, true);
                        if (is_array($dec) && isset($dec['action'])) {
                            $allEntries[] = $dec;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        // 2. 若 Redis 无数据，回退读取本地日志文件（只读尾部窗口）
        if (empty($allEntries)) {
            $filePath = CORE_PATH . self::LOG_FILE;
            $lines = self::readTailLines($filePath);
            foreach (array_reverse($lines) as $line) {
                $dec = json_decode(trim($line), true);
                if (is_array($dec) && isset($dec['action'])) {
                    $allEntries[] = $dec;
                }
            }
        }

        // 3. 过滤条件
        $filtered = [];
        $kw = $keyword !== null ? trim($keyword) : null;
        foreach ($allEntries as $entry) {
            if ($action !== null && $action !== '' && $entry['action'] !== $action) {
                continue;
            }
            if ($since !== null && ($entry['time'] ?? 0) < $since) {
                continue;
            }
            if ($until !== null && ($entry['time'] ?? 0) > $until) {
                continue;
            }
            if ($kw !== null && $kw !== '') {
                $strRep = json_encode($entry, JSON_UNESCAPED_UNICODE);
                if ($strRep === false || stripos($strRep, $kw) === false) {
                    continue;
                }
            }
            $filtered[] = $entry;
        }

        $total = count($filtered);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($filtered, $offset, $pageSize);

        return [
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'items' => $items,
        ];
    }

    /**
     * 从文件尾部读取若干行，避免把整个审计文件读进内存。
     *
     * @return string[]
     */
    private static function readTailLines(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        $size = (int) @filesize($filePath);
        if ($size <= 0) {
            return [];
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $readBytes = min($size, self::MAX_FILE_READ_BYTES);
            fseek($handle, $size - $readBytes);
            $chunk = (string) fread($handle, $readBytes);
        } finally {
            fclose($handle);
        }

        $lines = preg_split('/\R/', $chunk) ?: [];
        // 若读到的是窗口起点截断的半行，丢弃它
        if ($readBytes < $size) {
            array_shift($lines);
        }

        return array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));
    }

    /**
     * 清空审计日志（供开发或重置）。
     */
    public static function clearLogs(): int
    {
        $count = 0;
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $count = (int) $redis->lLen(self::REDIS_KEY);
                $redis->del(self::REDIS_KEY);
            } catch (\Throwable) {
            }
        }

        $filePath = CORE_PATH . self::LOG_FILE;
        if (is_file($filePath)) {
            if ($count === 0) {
                $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $count = count($lines);
            }
            @unlink($filePath);
        }

        return $count;
    }
}
