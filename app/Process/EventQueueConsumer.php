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
 * 日志异步事件队列常驻消费者。
 *
 * 单消费协程负责新消息，单 reclaim 协程负责崩溃/超时 pending 消息。
 * 每处理一定数量后优雅退出，由 Swoole manager 拉起以释放 RSS。
 */
#[Process(name: 'event-queue-consumer')]
class EventQueueConsumer extends AbstractProcess
{
    public string $name = 'event-queue-consumer';

    /** 本生命周期处理任务上限：达到后停止拉取，等待在途任务结束再退出 */
    private const MAX_PROCESSED = 1000;

    /** pending 条目空闲多久后才允许 reclaim，避免长任务被重复处理 */
    private const RECLAIM_IDLE_MS = 300000;

    /** 单轮 reclaim 最多认领的条目数 */
    private const RECLAIM_BATCH = 50;

    /** reclaim 扫描间隔（秒） */
    private const RECLAIM_INTERVAL_SECONDS = 30;

    /** 在途任务数 */
    private static int $busyJobs = 0;

    /** 本进程累计处理事件数 */
    private static int $processedJobs = 0;

    private bool $running = true;
    private bool $stopping = false;

    /**
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

        self::$busyJobs = 0;
        self::$processedJobs = 0;
        $this->running = true;
        $this->stopping = false;

        try {
            RedisStreams::xGroupCreate($stream, $group);
        } catch (\Throwable $e) {
            \App\Syslog::error('EventQueue', 'Group create failed: ' . $e->getMessage());
        }

        Coroutine::create(function () use ($stream, $group): void {
            $this->consumeLoop($stream, $group);
        });
        Coroutine::create(function () use ($stream, $group): void {
            $this->reclaimLoop($stream, $group);
        });

        while ($this->running) {
            Coroutine::sleep(5);

            if (!$this->stopping && self::$processedJobs >= self::MAX_PROCESSED) {
                $this->stopping = true;
                \App\Syslog::error('EventQueue', sprintf(
                    'Consumer reached max processed=%d, stopping intake with %d in-flight jobs',
                    self::$processedJobs,
                    self::$busyJobs
                ));
            }

            if ($this->stopping && self::$busyJobs === 0) {
                $this->running = false;
                \App\Syslog::error('EventQueue', sprintf(
                    'Consumer drain complete: processed=%d, restarting for RSS release',
                    self::$processedJobs
                ));
            }
        }
    }

    private function consumeLoop(string $stream, string $group): void
    {
        while ($this->running && !$this->stopping) {
            try {
                $entries = RedisStreams::xReadGroup($group, 'worker', $stream, 2000, 10);
                foreach ($entries as [$id, $fields]) {
                    $this->processEntry($stream, $group, (string) $id, $fields);
                    if ($this->stopping) {
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
                    \App\Syslog::error('EventQueue', 'Consume loop error: ' . $e->getMessage());
                }
                Coroutine::sleep(1);
            }
        }
    }

    /**
     * 处理单条消息：成功 ACK，失败保留 pending 等待 reclaim，超过上限进入死信。
     *
     * @param array<string, mixed> $fields
     */
    protected function processEntry(string $stream, string $group, string $id, array $fields): void
    {
        self::$busyJobs++;

        try {
            $event = QueueEvent::fromStreamData($fields, $id);
            $attempts = $this->incrementAttempts($id);
            $maxAttempts = max(1, EventQueue::config()['maxAttempts']);

            $success = false;
            try {
                $success = EventQueue::executePipeline($event);
            } catch (\Throwable $e) {
                $event->setLastError($e->getMessage());
                \App\Syslog::error('EventQueue', "Fatal execution error for event {$event->getName()} [{$id}]: " . $e->getMessage());
            }

            if ($success || $event->isPropagationStopped()) {
                $this->ackAndDelete($stream, $group, $id);
                return;
            }

            if ($attempts >= $maxAttempts) {
                $reason = $event->getLastError() ?? 'Max retries exhausted';
                if (DeadLetterQueue::push($event, $reason) !== null) {
                    $this->ackAndDelete($stream, $group, $id);
                    \App\Syslog::error('EventQueue', "Event {$event->getName()} [{$id}] moved to DLQ after {$attempts} attempts");
                } else {
                    \App\Syslog::error('EventQueue', "CRITICAL: failed to push event {$event->getName()} [{$id}] to DLQ; keeping pending");
                }
                return;
            }

            \App\Syslog::error('EventQueue', "Event {$event->getName()} [{$id}] failed, will retry (attempt {$attempts}/{$maxAttempts}): " . ($event->getLastError() ?? 'unknown error'));
        } finally {
            self::$busyJobs--;
            self::$processedJobs++;
        }
    }

    private function incrementAttempts(string $streamId): int
    {
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return 1;
        }

        $key = "events:attempts:{$streamId}";
        try {
            $attempts = (int) $redis->incr($key);
            $redis->expire($key, 604800);
            return $attempts;
        } catch (\Throwable) {
            return 1;
        }
    }

    private function ackAndDelete(string $stream, string $group, string $id): void
    {
        try {
            RedisStreams::xAck($stream, $group, $id);
            RedisStreams::xDel($stream, $id);
        } catch (\Throwable $e) {
            \App\Syslog::error('EventQueue', "Failed to ACK/DEL entry {$id}: " . $e->getMessage());
        }

        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $redis->del("events:attempts:{$id}");
            } catch (\Throwable) {
            }
        }
    }

    /**
     * 回收崩溃或超时未 ACK 的 pending 消息。
     */
    private function reclaimLoop(string $stream, string $group): void
    {
        while ($this->running && !$this->stopping) {
            try {
                $cursor = '0-0';
                do {
                    $claimed = RedisStreams::xAutoClaim(
                        $stream,
                        $group,
                        'reclaimer',
                        self::RECLAIM_IDLE_MS,
                        self::RECLAIM_BATCH,
                        $cursor
                    );
                    foreach ($claimed as [$id, $fields]) {
                        $this->processEntry($stream, $group, (string) $id, $fields);
                        if ($this->stopping) {
                            break;
                        }
                    }
                } while ($this->running && !$this->stopping && $claimed !== [] && $cursor !== '0-0');
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'NOGROUP')) {
                    \App\Syslog::error('EventQueue', 'Reclaim loop error: ' . $e->getMessage());
                }
            }

            Coroutine::sleep(self::RECLAIM_INTERVAL_SECONDS);
        }
    }
}
