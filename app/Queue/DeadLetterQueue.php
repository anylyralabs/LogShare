<?php

declare(strict_types=1);

namespace App\Queue;

use App\Client\RedisClient;
use App\Client\RedisStreams;

/**
 * 统一事件队列死信管理（Dead Letter Queue）。
 *
 * 记录处理重试耗尽或致命异常的事件条目，供故障排查。
 */
class DeadLetterQueue
{
    public const DEAD_STREAM = 'events:log:dead';

    /**
     * 将失败事件录入死信队列。
     */
    public static function push(QueueEvent $event, string $reason): ?string
    {
        if (RedisClient::getRedis() === null) {
            \App\Syslog::error('DeadLetterQueue', "Redis unavailable, dead event dropped: {$event->getName()} [{$event->getId()}], reason: {$reason}");
            return null;
        }

        try {
            $data = [
                'id' => $event->getId(),
                'event' => $event->getName(),
                'payload' => json_encode($event->getPayload(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                'error' => $reason,
                'failedAt' => (string) microtime(true),
            ];

            // 约保留最近 2000 条死信条目
            return RedisStreams::xAdd(self::DEAD_STREAM, $data, 2000);
        } catch (\Throwable $e) {
            \App\Syslog::error('DeadLetterQueue', "Failed to push dead letter: " . $e->getMessage());
            return null;
        }
    }


}
