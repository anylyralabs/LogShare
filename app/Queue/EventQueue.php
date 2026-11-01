<?php

declare(strict_types=1);

namespace App\Queue;

use App\Client\RedisClient;
use App\Client\RedisStreams;
use App\Config;
use App\Queue\Handler\DeobfuscateHandler;
use App\Queue\Handler\EventHandlerInterface;
use App\Queue\Handler\SecurityAuditHandler;
use Hyperf\Coroutine\Coroutine;

/**
 * 统一日志异步事件队列调度中心（Event Dispatcher & Queue Engine）。
 *
 * 负责管理事件监听器注册、优先级管道流转、Redis Stream 异步解耦、
 * 失败重试决策、死信归档以及运行监控指标探测。
 */
final class EventQueue
{
    public const EVENT_LOG_UPLOADED = 'log.uploaded';
    public const EVENT_DEOBFUSCATE = 'log.deobfuscate';
    public const EVENT_SECURITY_AUDIT = 'log.security_audit';

    public const DEFAULT_STREAM = 'events:log:stream';
    public const DEFAULT_GROUP = 'log-event-workers';

    /**
     * 事件监听器注册表：[eventName => [[handler, priority], ...]]
     *
     * @var array<string, array<int, array{handler: EventHandlerInterface|callable|string, priority: int}>>
     */
    private static array $listeners = [];

    /**
     * 是否已加载默认监听器。
     */
    private static bool $bootstrapped = false;

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
     * 初始化并注册系统内置默认事件订阅流水线。
     */
    public static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;

        // 1. log.uploaded 上传后复合流水线：先进行安全审核，通过后再进行反混淆
        self::register(self::EVENT_LOG_UPLOADED, SecurityAuditHandler::class, 100);
        self::register(self::EVENT_LOG_UPLOADED, DeobfuscateHandler::class, 50);

        // 2. 单项独立事件订阅
        self::register(self::EVENT_SECURITY_AUDIT, SecurityAuditHandler::class, 100);
        self::register(self::EVENT_DEOBFUSCATE, DeobfuscateHandler::class, 100);
    }

    /**
     * 注册事件处理器（支持按优先级降序排列）。
     *
     * @param string $eventName 事件代号
     * @param EventHandlerInterface|callable|string $handler 处理器实例、可调用对象或类名
     * @param int $priority 优先级（数值越大越优先执行）
     */
    /**
     * 判断注册的处理器是否指向某个类（支持类名字符串、实例与可调用数组）。
     *
     * @param EventHandlerInterface|callable|string $handler
     * @param class-string $class
     */
    private static function handlerIs(EventHandlerInterface|callable|string $handler, string $class): bool
    {
        if (is_string($handler)) {
            return is_a($handler, $class, true);
        }
        if (is_object($handler)) {
            return $handler instanceof $class;
        }
        // callable 数组 [$objectOrClass, 'method']：按类部分判断
        if (!is_array($handler)) {
            return false;
        }
        return is_string($handler[0])
            ? is_a($handler[0], $class, true)
            : $handler[0] instanceof $class;
    }

    public static function register(string $eventName, EventHandlerInterface|callable|string $handler, int $priority = 0): void
    {
        if (!isset(self::$listeners[$eventName])) {
            self::$listeners[$eventName] = [];
        }

        self::$listeners[$eventName][] = [
            'handler' => $handler,
            'priority' => $priority,
        ];

        // 按优先级降序排序
        usort(self::$listeners[$eventName], static fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * 清空或重置监听器（测试隔离专用）。
     */
    public static function resetListeners(): void
    {
        self::$listeners = [];
        self::$bootstrapped = false;
    }

    /**
     * 获取所有已注册的事件及处理器元数据。
     *
     * @return array<string, array<int, array{name: string, priority: int}>>
     */
    public static function getRegisteredEvents(): array
    {
        self::bootstrap();
        $result = [];

        foreach (self::$listeners as $eventName => $entries) {
            $result[$eventName] = [];
            foreach ($entries as $entry) {
                $handler = $entry['handler'];
                $name = is_string($handler)
                    ? $handler
                    : ($handler instanceof EventHandlerInterface ? $handler->getName() : 'Closure');

                $result[$eventName][] = [
                    'name' => $name,
                    'priority' => $entry['priority'],
                ];
            }
        }

        return $result;
    }

    /**
     * 分发异步事件。
     *
     * @param string $event 事件名称
     * @param array<string, mixed> $payload 事件数据
     * @param array{maxAttempts?: int} $options 派发选项
     * @return bool 派发是否成功接收
     */
    public static function dispatch(string $event, array $payload, array $options = []): bool
    {
        self::bootstrap();
        $cfg = self::config();

        $maxAttempts = $options['maxAttempts'] ?? $cfg['maxAttempts'];
        $queueEvent = new QueueEvent($event, $payload, null, 1, $maxAttempts);

        // 未开启队列时同步执行
        if (!$cfg['enabled']) {
            return self::executePipeline($queueEvent);
        }

        // 1. 优先尝试投递到 Redis Stream
        if (RedisClient::getRedis() !== null) {
            try {
                $streamKey = $cfg['stream'];
                $streamData = $queueEvent->toStreamData();

                // 近似保留最近 10000 条
                RedisStreams::xAdd($streamKey, $streamData, 10000);
                return true;
            } catch (\Throwable $e) {
                \App\Syslog::error('EventQueue', 'Redis Stream dispatch failed, falling back: ' . $e->getMessage());
            }
        }

        // 2. Redis 不可用或未启动时平滑降级为 Swoole 协程
        if (class_exists(Coroutine::class) && Coroutine::inCoroutine()) {
            Coroutine::create(static function () use ($queueEvent) {
                try {
                    self::executePipeline($queueEvent);
                } catch (\Throwable $e) {
                    \App\Syslog::error('EventQueue', "Async event {$queueEvent->getName()} failed: " . $e->getMessage());
                }
            });
            return true;
        }

        // 3. 非协程环境（CLI/单测）同步降级执行
        $success = self::executePipeline($queueEvent);
        return $success;
    }

    /**
     * 统一执行事件处理器流水线（Pipeline）。
     */
    public static function executePipeline(QueueEvent $event): bool
    {
        self::bootstrap();
        $eventName = $event->getName();
        $cfg = self::config();

        // 针对配置关闭子项的处理
        if ($eventName === self::EVENT_LOG_UPLOADED) {
            if (!$cfg['asyncSecurityAudit'] && !$cfg['asyncDeobfuscate']) {
                return true;
            }
        }

        $handlers = self::$listeners[$eventName] ?? [];
        if (empty($handlers)) {
            \App\Syslog::error('EventQueue', "No listeners registered for event: {$eventName}");
            return false;
        }

        $allSuccess = true;

        foreach ($handlers as $entry) {
            if ($event->isPropagationStopped()) {
                break;
            }

            $handler = $entry['handler'];

            // 动态根据开关跳过对应处理器
            if ($eventName === self::EVENT_LOG_UPLOADED) {
                if (!$cfg['asyncSecurityAudit'] && self::handlerIs($handler, SecurityAuditHandler::class)) {
                    continue;
                }
                if (!$cfg['asyncDeobfuscate'] && self::handlerIs($handler, DeobfuscateHandler::class)) {
                    continue;
                }
            }

            try {
                $instance = self::resolveHandler($handler);
                $passed = $instance->handle($event);
                if (!$passed) {
                    $allSuccess = false;
                }
            } catch (\Throwable $e) {
                $allSuccess = false;
                $event->setLastError($e->getMessage());
                \App\Syslog::error('EventQueue', "Handler exception in {$eventName}: " . $e->getMessage());
            }
        }

        if ($allSuccess) {
        } elseif ($event->isPropagationStopped()) {
            // 处理器主动阻断流转（异步安全审核命中违规，日志已物理删除并封禁）：
            // 事件按业务预期终结，计入拦截而非失败，否则失败率会被治理动作系统性抬高
        } else {
        }

        return $allSuccess;
    }

    /**
     * 解析处理器实例。
     */
    private static function resolveHandler(EventHandlerInterface|callable|string $handler): EventHandlerInterface
    {
        if ($handler instanceof EventHandlerInterface) {
            return $handler;
        }

        if (is_string($handler) && class_exists($handler)) {
            $instance = new $handler();
            if ($instance instanceof EventHandlerInterface) {
                return $instance;
            }
        }

        if (is_callable($handler)) {
            return new class($handler) implements EventHandlerInterface {
                /** @var callable */
                private $callable;

                public function __construct(callable $callable)
                {
                    $this->callable = $callable;
                }

                public function handle(QueueEvent $event): bool
                {
                    return (bool) ($this->callable)($event);
                }

                public function getName(): string
                {
                    return 'callable_handler';
                }
            };
        }

        throw new \InvalidArgumentException("Invalid event handler provided");
    }

    /**
     * 兼容老接口：处理单个事件。
     *
     * @param string $event
     * @param array<string, mixed> $payload
     */
    public static function handleEvent(string $event, array $payload): bool
    {
        $queueEvent = new QueueEvent($event, $payload);
        return self::executePipeline($queueEvent);
    }


}
