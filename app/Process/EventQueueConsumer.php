<?php

declare(strict_types=1);

namespace App\Process;

use App\Client\RedisClient;
use App\Client\RedisStreams;
use App\Queue\DeadLetterQueue;
use App\Queue\EventQueue;
use App\Queue\QueueEvent;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;

/**
 * 统一日志异步事件队列常驻消费者进程。
 *
 * 负责消费 events:log:stream 中的异步解耦任务。
 * 具备 Pipeline 调度、重试决策、死信归档、优雅排空与内存自回收机制。
 */
#[Process(name: 'event-queue-consumer')]
class EventQueueConsumer extends AbstractProcess
{
    public string $name = 'event-queue-consumer';

    /** 空闲多久后自回收（秒） */
    private const RESTART_IDLE_SECONDS = 180;
    /** 本生命周期至少处理过多少任务才允许空闲自回收 */
    private const RESTART_MIN_PROCESSED = 50;
    /** 本生命周期处理任务上限：达到后一旦当前无在途任务立即退出，由 Swoole manager 自动拉起释放 RSS */
    private const RESTART_MAX_PROCESSED = 1000;
    /** 进程占用物理常驻内存达到此阈值（256MB）且无在途任务时自回收 */
    private const RESTART_MAX_MEMORY_BYTES = 268435456;

    /** 在途任务数 */
    private static int $busyJobs = 0;
    /** 本进程累计处理事件数 */
    private static int $processedJobs = 0;

    private bool $running = true;
    private bool $draining = false;
    private int $lastBusyAt = 0;

    /**
     * 判断进程是否启动。
     *
     * @param mixed $server
     */
    public function isEnable($server): bool
    {
        return extension_loaded('redis') && EventQueue::config()['enabled'];
    }

    public function handle(): void
    {
        $cfg = EventQueue::config();
        $stream = $cfg['stream'];
        $group = $cfg['group'];
        $this->lastBusyAt = time();


        try {
            RedisStreams::xGroupCreate($stream, $group);
        } catch (\Throwable $e) {
            \App\Syslog::error('EventQueue', 'Group create failed: ' . $e->getMessage());
        }

        $activeConsumers = ['reclaimer'];
        $workerCount = 2;
        for ($i = 0; $i < $workerCount; $i++) {
            $consumer = 'worker-' . $i;
            $activeConsumers[] = $consumer;
            Coroutine::create(function () use ($stream, $group, $consumer): void {
                $this->consumeLoop($stream, $group, $consumer);
            });
        }

        $this->cleanStaleConsumers($stream, $group, $activeConsumers);

        Coroutine::create(function () use ($stream, $group): void {
            $this->reclaimLoop($stream, $group);
        });

        $drainDeadline = null;
        while ($this->running) {
            sleep(5);
            $this->maybeRecycle($stream, $group);
            if ($this->draining) {
                if ($drainDeadline === null) {
                    $drainDeadline = time() + 30; // 30 秒排空宽限期
                } elseif (time() > $drainDeadline) {
                    \App\Syslog::error(
                        'EventQueue',
                        sprintf('Consumer drain timeout after 30s with %d in-flight jobs, forcing exit for restart', self::$busyJobs)
                    );
                    $this->running = false;
                    exit(0);
                }
            }
        }
    }

    /**
     * 消费循环。
     */
    private function consumeLoop(string $stream, string $group, string $consumer): void
    {
        while ($this->running && !$this->draining) {
            try {
                $entries = RedisStreams::xReadGroup($group, $consumer, $stream, 2000, 10);
                if (empty($entries)) {
                    continue;
                }

                foreach ($entries as [$id, $fields]) {
                    $this->processEntry($stream, $group, (string) $id, $fields);
                    if ($this->draining) {
                        break;
                    }
                }

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                if (function_exists('gc_mem_caches')) {
                    gc_mem_caches();
                }
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'NOGROUP')) {
                    try {
                        RedisStreams::xGroupCreate($stream, $group);
                    } catch (\Throwable) {
                    }
                } else {
                    \App\Syslog::error('EventQueue', "Consume loop error on {$consumer}: " . $e->getMessage());
                }
                sleep(1);
            }
        }
    }

    /**
     * 处理单条消息，支持 Pipeline 流转、重试决策与死信归档。
     *
     * @param array<string, mixed> $fields
     */
    private function processEntry(string $stream, string $group, string $id, array $fields): void
    {
        self::$busyJobs++;
        $this->lastBusyAt = time();

        $event = QueueEvent::fromStreamData($fields, $id);
        $attemptKey = "events:attempts:{$id}";
        $redis = RedisClient::getRedis();
        if ($redis) {
            try {
                $attempts = (int) $redis->incr($attemptKey);
                $redis->expire($attemptKey, 86400);
                $event->setAttempts($attempts);
            } catch (\Throwable) {
                // ignore
            }
        }

        $success = false;

        try {
            $success = EventQueue::executePipeline($event);
        } catch (\Throwable $e) {
            $event->setLastError($e->getMessage());
            \App\Syslog::error('EventQueue', "Fatal execution error for event {$event->getName()} [{$id}]: " . $e->getMessage());
        } finally {
            try {
                // 1. 成功处理，或者业务上已被阻断退出（例如命中违规安全过滤，无需重试）
                if ($success || $event->isPropagationStopped()) {
                    RedisStreams::xAck($stream, $group, $id);
                    RedisStreams::xDel($stream, $id);
                    if ($redis) {
                        try {
                            $redis->del($attemptKey);
                        } catch (\Throwable) {
                            // ignore
                        }
                    }
                } else {
                    // 2. 失败处理：检查重试上限
                    if (!$event->canRetry()) {
                        // 重试耗尽，进入死信流保护并出队
                        $dlqId = DeadLetterQueue::push($event, $event->getLastError() ?? 'Max retries exhausted');
                        if ($dlqId !== null) {
                            RedisStreams::xAck($stream, $group, $id);
                            RedisStreams::xDel($stream, $id);
                            if ($redis) {
                                try {
                                    $redis->del($attemptKey);
                                } catch (\Throwable) {
                                    // ignore
                                }
                            }
                            \App\Syslog::error('EventQueue', "Event {$event->getName()} [{$id}] moved to DLQ after {$event->getAttempts()} attempts");
                        } else {
                            \App\Syslog::error('EventQueue', "CRITICAL: Failed to push event {$event->getName()} [{$id}] to DLQ, message kept pending in stream");
                        }
                    } else {
                        // 尚可重试：记录警告，保留 pending 由 reclaim 协程在退避后拉取重试
                        \App\Syslog::error('EventQueue', "Event {$event->getName()} [{$id}] failed, will retry (attempt {$event->getAttempts()}/{$event->getMaxAttempts()}): " . ($event->getLastError() ?? 'unknown error'));
                    }
                }
            } catch (\Throwable $e) {
                \App\Syslog::error('EventQueue', "Failed to ACK/DLQ entry {$id}: " . $e->getMessage());
            }

            self::$busyJobs--;
            self::$processedJobs++;
            $this->lastBusyAt = time();
        }
    }

    /**
     * 崩溃未决与重试任务回收循环（Dead Letter / Reclaim）。
     */
    private function reclaimLoop(string $stream, string $group): void
    {
        $cursor = '0-0';
        while ($this->running && !$this->draining) {
            sleep(30);
            try {
                // 认领超过 60 秒未 ACK 的待决条目
                $claimed = RedisStreams::xAutoClaim($stream, $group, 'reclaimer', 60000, 10, $cursor);
                foreach ($claimed as [$id, $fields]) {
                    $this->processEntry($stream, $group, (string) $id, $fields);
                }
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'NOGROUP')) {
                    \App\Syslog::error('EventQueue', 'Reclaim loop error: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * 清理组内遗留的僵尸消费者。
     *
     * @param array<int, string> $activeConsumers
     */
    private function cleanStaleConsumers(string $stream, string $group, array $activeConsumers): void
    {
        try {
            $consumers = RedisStreams::xInfoConsumers($stream, $group);
            foreach ($consumers as $info) {
                $name = (string) ($info['name'] ?? '');
                $pending = (int) ($info['pending'] ?? 0);
                if ($name === '' || in_array($name, $activeConsumers, true)) {
                    continue;
                }
                if ($pending === 0) {
                    RedisStreams::xGroupDelConsumer($stream, $group, $name);
                }
            }
        } catch (\Throwable $e) {
            \App\Syslog::error('EventQueue', 'Clean stale consumers failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取常驻物理内存大小。
     */
    public static function getRssMemoryBytes(): int
    {
        $status = @file_get_contents('/proc/self/status');
        if ($status !== false && preg_match('/VmRSS:\s+(\d+)\s+kB/i', $status, $m)) {
            return ((int) $m[1]) * 1024;
        }
        return memory_get_usage(true);
    }

    /**
     * 周期空闲与内存自回收检测。
     */
    private function maybeRecycle(string $stream, string $group): void
    {
        if (!$this->draining) {
            $reason = null;
            $rssBytes = self::getRssMemoryBytes();

            if (self::$processedJobs >= self::RESTART_MAX_PROCESSED) {
                $reason = sprintf('processed=%d >= %d (max requests limit)', self::$processedJobs, self::RESTART_MAX_PROCESSED);
            } elseif (self::$processedJobs >= self::RESTART_MIN_PROCESSED && $rssBytes >= self::RESTART_MAX_MEMORY_BYTES) {
                $reason = sprintf('rss=%dMB >= %dMB, processed=%d (memory limit)', intdiv($rssBytes, 1048576), intdiv(self::RESTART_MAX_MEMORY_BYTES, 1048576), self::$processedJobs);
            } elseif (self::$processedJobs >= self::RESTART_MIN_PROCESSED && (time() - $this->lastBusyAt >= self::RESTART_IDLE_SECONDS)) {
                try {
                    $pending = RedisStreams::xPending($stream, $group);
                    $pendingCount = (int) ($pending[0] ?? 0);
                    if ($pendingCount === 0) {
                        $reason = sprintf('idle>=%ds, processed=%d (idle timeout)', self::RESTART_IDLE_SECONDS, self::$processedJobs);
                    } else {
                        $this->lastBusyAt = time();
                    }
                } catch (\Throwable) {
                }
            }

            if ($reason !== null) {
                $this->draining = true;
                \App\Syslog::error('EventQueue', sprintf('Consumer initiating graceful drain for recycle: %s, activeBusyJobs=%d', $reason, self::$busyJobs));
            }
        }

        if ($this->draining && self::$busyJobs === 0) {
            \App\Syslog::error(
                'EventQueue',
                sprintf('Consumer drain complete: processed=%d, rss=%dMB, exiting for manager restart', self::$processedJobs, intdiv(self::getRssMemoryBytes(), 1048576))
            );
            $this->running = false;
            exit(0);
        }
    }
}
