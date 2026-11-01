<?php

namespace App\Client;

/**
 * Redis connection facade with coroutine-safe connection handling.
 *
 * Swoole 5+/6+ hooks ext-redis via SWOOLE_HOOK_ALL, so ext-redis is already
 * coroutine-aware (non-blocking). The remaining hazard is sharing a single
 * process-level connection across concurrent coroutines, which can interleave
 * request/response frames. We therefore store the connection in the
 * coroutine-scoped Hyperf Context so each request gets its own connection that
 * is released when the coroutine ends.
 *
 * Outside coroutines (CLI / tests) we fall back to a process-level singleton
 * with ping-based reconnection, and throw a catchable Exception when ext-redis
 * is unavailable so callers can degrade gracefully.
 */
class RedisClient
{
    private const CONNECT_TIMEOUT = 1.5;
    private const CONTEXT_CONN_KEY = 'logshare_redis_connection';

    /**
     * ext-redis 进程级单例（仅非协程环境使用）。
     *
     * @var ?\Redis
     */
    protected static ?\Redis $connection = null;

    /** @var ?object 测试专用注入连接（如 RedisMock），非 \Redis 实例 */
    protected static ?object $testConnection = null;

    public static function setTestConnection(?object $conn): void
    {
        self::$testConnection = $conn;
    }

    protected static function inCoroutine(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * Return a usable Redis connection for the current context.
     *
     * 原生返回类型必须保持宽松的 object：PHP 在运行时强制校验原生类型，而单测经
     * setTestConnection() 注入的 Mockery/RedisMock 并非 \Redis 子类（CI 装有 ext-redis
     * 时 RedisMock 不会被 class_alias 成 Redis），收窄为 \Redis 会直接抛 TypeError。
     * 精确类型交由 @return \Redis 提供给静态分析。
     *
     * @return \Redis
     * @throws \Exception When Redis is unreachable or unavailable
     */
    protected static function connection(): object
    {
        if (self::$testConnection !== null) {
            // 测试注入的连接是 \Redis 的鸭子类型替身（RedisMock / Mockery），
            // 运行时只能声明 object，静态分析按 \Redis 处理以便调用方获得补全。
            /** @var \Redis $testConnection */
            $testConnection = self::$testConnection;
            return $testConnection;
        }

        $config = \App\Config::Get('cache');
        $redisConfig = $config['redis'] ?? ['host' => 'mclogs-redis', 'port' => 6379];
        $host = (string) ($redisConfig['host'] ?? 'mclogs-redis');
        $port = (int) ($redisConfig['port'] ?? 6379);
        $timeout = (float) ($redisConfig['timeout'] ?? self::CONNECT_TIMEOUT);

        if (self::inCoroutine()) {
            // 协程级连接存 Context，协程结束时由 ext-redis 析构自动关闭底层 socket
            // （无需显式 close/defer）。
            $conn = \Hyperf\Context\Context::get(self::CONTEXT_CONN_KEY);
            if ($conn instanceof \Redis && $conn->isConnected()) {
                return $conn;
            }

            $conn = self::createConnection($redisConfig);
            \Hyperf\Context\Context::set(self::CONTEXT_CONN_KEY, $conn);
            return $conn;
        }

        if (self::$connection !== null) {
            try {
                if (@self::$connection->ping()) {
                    return self::$connection;
                }
            } catch (\Throwable $e) {
                // 连接失效（如 Redis 重启）→ 重连
            }
            self::$connection = null;
        }

        self::$connection = self::createConnection($redisConfig);
        return self::$connection;
    }

    /**
     * @param array<string, mixed> $redisConfig cache.redis 配置节（host/port/timeout/password/database）
     */
    private static function createConnection(array $redisConfig): \Redis
    {
        if (!class_exists('Redis')) {
            // 缺少 ext-redis（如本地开发 / Termux）。抛出可捕获的异常，
            // 避免 `new Redis()` 触发致命 Error。
            throw new \Exception('Redis extension is not installed');
        }

        $host = (string) ($redisConfig['host'] ?? 'mclogs-redis');
        $port = (int) ($redisConfig['port'] ?? 6379);
        $timeout = (float) ($redisConfig['timeout'] ?? self::CONNECT_TIMEOUT);
        $readTimeout = (float) ($redisConfig['read_timeout'] ?? 10.0);

        $conn = new \Redis();
        if (!$conn->connect($host, $port, $timeout)) {
            throw new \Exception('Redis connection failed: ' . $host . ':' . $port);
        }

        // 显式设置读取超时（默认 10 秒，远大于消费者 2s/5s 阻塞等待），杜绝周期性超时断连
        if (defined('Redis::OPT_READ_TIMEOUT')) {
            $conn->setOption(\Redis::OPT_READ_TIMEOUT, $readTimeout);
        }

        $password = $redisConfig['password'] ?? null;
        if (is_string($password) && $password !== '') {
            $conn->auth($password);
        }
        $database = $redisConfig['database'] ?? null;
        // is_numeric 兼容环境变量覆盖后传入的数字字符串（如 "1"），否则会静默跳过 select
        if (is_numeric($database) && (int) $database > 0) {
            $conn->select((int) $database);
        }
        return $conn;
    }

    /**
     * 获取当前上下文可用的 Redis 实例（在 ext-redis 未安装或连接失败时安全返回 null）。
     *
     * @return \Redis|null
     */
    public static function getRedis(): ?object
    {
        try {
            return self::connection();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 统一操作封装。
     */

    protected static function opSet(string $key, string $value, ?int $ttl = null): void
    {
        $conn = self::connection();
        if ($ttl) {
            $conn->setEx($key, $ttl, $value);
        } else {
            $conn->set($key, $value);
        }
    }

    /**
     * 仅当 key 不存在时写入 0 并附带 TTL（SET NX EX）。
     *
     * 用于计数器初始化：与后续 INCR 配合可保证计数 key 永远携带 TTL，
     * 避免「INCR 后进程崩溃、EXPIRE 未执行」留下的永久限流 key。
     */
    protected static function opSetNxEx(string $key, int $ttl): bool
    {
        return (bool) self::connection()->set($key, 0, ['nx', 'ex' => $ttl]);
    }

    protected static function opGet(string $key): ?string
    {
        $value = self::connection()->get($key);
        return $value === false ? null : $value;
    }

    protected static function opExists(string $key): bool
    {
        return (bool) self::connection()->exists($key);
    }

    protected static function opDel(string $key): bool
    {
        return (bool) self::connection()->del($key);
    }

    protected static function opIncr(string $key): int
    {
        return (int) self::connection()->incr($key);
    }

    protected static function opExpire(string $key, int $seconds): bool
    {
        return (bool) self::connection()->expire($key, $seconds);
    }
}
