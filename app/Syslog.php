<?php

declare(strict_types=1);

namespace App;

/**
 * 进程级错误日志统一入口。
 *
 * 常驻进程中所有诊断输出都应经过此处，保证 `[组件] 消息` 格式一致，
 * 并为未来切换到 Hyperf StdoutLoggerInterface 保留单一改动点。
 * （App\Log 已被 Codex 日志包装占用，故命名 Syslog。）
 */
final class Syslog
{
    private const LOG_DIR = '/runtime/logs';
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    public static function info(string $component, string $message): void
    {
        self::record('info', $component, $message);
    }

    public static function warning(string $component, string $message): void
    {
        self::record('warning', $component, $message);
    }

    public static function error(string $component, string $message): void
    {
        self::record('error', $component, $message);
    }

    private static function record(string $level, string $component, string $message): void
    {
        $now = time();
        $datetime = date('Y-m-d H:i:s', $now);
        $formatted = sprintf('[%s] [%s] [%s] %s', $datetime, strtoupper($level), $component, $message);

        // 1. Output to PHP error_log (stdout/stderr for Docker & Swoole console)
        error_log($formatted);

        // 2. Append to persistent file in runtime/logs
        try {
            $baseDir = defined('CORE_PATH') ? CORE_PATH : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__));
            $dir = $baseDir . self::LOG_DIR;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . '/system.log';

            // Rotate if oversized
            if (is_file($file) && (int) @filesize($file) > self::MAX_FILE_SIZE) {
                @rename($file, $dir . '/system.log.' . date('Ymd_His'));
            }

            @file_put_contents($file, $formatted . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Suppress filesystem errors
        }
    }
}
