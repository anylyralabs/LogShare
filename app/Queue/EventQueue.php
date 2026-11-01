<?php

declare(strict_types=1);

namespace App\Queue;

use App\Client\RedisClient;
use App\Client\RedisStreams;
use App\Config;
use App\Queue\Handler\DeobfuscateHandler;
use App\Queue\Handler\SecurityAuditHandler;

/**
 * 日志异步事件队列调度器。
 *
 * 生产只有一个固定流水线：上传事件依次执行安全审核与反混淆。
 * Redis 可用时投递到 Stream；队列关闭或 Redis 不可用时同步执行。
 */
final class EventQueue
{
    public const EVENT_LOG_UPLOADED = 'log.uploaded';

    public const DEFAULT_STREAM = 'events:log:stream';
    public const DEFAULT_GROUP = 'log-event-workers';

    /**
     * 获取队列配置。
     *
     * @return array{enabled: bool, stream: string, group: string, maxAttempts: int, asyncDeobfuscate: bool, asyncSecurityAudit: bool}
     */
    public static function config(): array
    {
        $cfg = Config::Get('eventQueue');
        return [
            'enabled' => (bool) ($cfg['enabled'] ?? true),
            'stream' => (string) ($cfg['stream'] ?? self::DEFAULT_STREAM),
            'group' => (string) ($cfg['group'] ?? self::DEFAULT_GROUP),
            'maxAttempts' => (int) ($cfg['maxAttempts'] ?? 3),
            'asyncDeobfuscate' => (bool) ($cfg['asyncDeobfuscate'] ?? true),
            'asyncSecurityAudit' => (bool) ($cfg['asyncSecurityAudit'] ?? true),
        ];
    }

    /**
     * 分发上传事件。
     *
     * Redis 不可用时同步执行，宁可让本次上传变慢，也不把事件交给不可持久化的协程。
     *
     * @param array<string, mixed> $payload
     */
    public static function dispatch(string $event, array $payload): bool
    {
        if ($event !== self::EVENT_LOG_UPLOADED) {
            \App\Syslog::error('EventQueue', "Unsupported event: {$event}");
            return false;
        }

        $cfg = self::config();
        $queueEvent = new QueueEvent($event, $payload, null, 1, $cfg['maxAttempts']);

        if (!$cfg['enabled']) {
            return self::executePipeline($queueEvent);
        }

        if (RedisClient::getRedis() !== null) {
            try {
                RedisStreams::xAdd($cfg['stream'], $queueEvent->toStreamData(), 10000);
                return true;
            } catch (\Throwable $e) {
                \App\Syslog::error('EventQueue', 'Redis Stream dispatch failed, executing synchronously: ' . $e->getMessage());
            }
        }

        return self::executePipeline($queueEvent);
    }

    /**
     * 执行固定流水线。
     */
    public static function executePipeline(QueueEvent $event): bool
    {
        $eventName = $event->getName();
        $handlers = self::handlersFor($eventName);

        if ($handlers === [] && $eventName === self::EVENT_LOG_UPLOADED) {
            return true;
        }
        if ($handlers === []) {
            \App\Syslog::error('EventQueue', "No pipeline registered for event: {$eventName}");
            return false;
        }

        $allSuccess = true;
        foreach ($handlers as $handler) {
            if ($event->isPropagationStopped()) {
                break;
            }

            try {
                if (!$handler->handle($event)) {
                    $allSuccess = false;
                }
            } catch (\Throwable $e) {
                $allSuccess = false;
                $event->setLastError($e->getMessage());
                \App\Syslog::error('EventQueue', "Handler exception in {$eventName}: " . $e->getMessage());
            }
        }

        return $allSuccess;
    }

    /**
     * @return array<int, SecurityAuditHandler|DeobfuscateHandler>
     */
    private static function handlersFor(string $eventName): array
    {
        if ($eventName !== self::EVENT_LOG_UPLOADED) {
            return [];
        }

        $cfg = self::config();
        $handlers = [];
        if ($cfg['asyncSecurityAudit']) {
            $handlers[] = new SecurityAuditHandler();
        }
        if ($cfg['asyncDeobfuscate']) {
            $handlers[] = new DeobfuscateHandler();
        }

        return $handlers;
    }
}
