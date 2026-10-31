<?php

declare(strict_types=1);

namespace App\Client;

/**
 * Redis Streams 命令面封装（统一事件队列使用）。
 *
 * 复用 RedisClient 的协程级连接隔离；与 RedisCache 不同，本类的键形态是
 * Stream 而非普通缓存，不实现 CacheInterface（架构测试约束 app/Cache 下
 * 的类必须实现该接口，故置于 app/Client）。
 */
class RedisStreams extends RedisClient
{
    /**
     * XADD：'*' 由 Redis 生成 id；maxLen > 0 时按 MAXLEN ~ 近似裁剪。
     *
     * @return string 新条目 id
     */
    public static function xAdd(string $key, array $fields, int $maxLen = 0): string
    {
        $conn = self::connection();
        if ($maxLen > 0) {
            return (string) $conn->xadd($key, '*', $fields, $maxLen, true);
        }
        return (string) $conn->xadd($key, '*', $fields);
    }

    /**
     * XGROUP CREATE ... MKSTREAM；组已存在（BUSYGROUP）视为成功。
     *
     * 捕获 \Throwable 而非 \RedisException：测试环境以 RedisMock 别名 \Redis，
     * ext-redis 缺席时 RedisException 类根本不存在。
     */
    public static function xGroupCreate(string $key, string $group): void
    {
        try {
            self::connection()->xgroup('CREATE', $key, $group, '0', true);
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * XREADGROUP 读取新消息（'>'）。
     *
     * @return array<int, array{0: string, 1: array}> [id, fields] 列表
     */
    public static function xReadGroup(string $group, string $consumer, string $key, int $blockMs, int $count = 1): array
    {
        $reply = self::connection()->xreadgroup($group, $consumer, [$key => '>'], $count, $blockMs);
        return self::flattenStreamReply($reply, $key);
    }

    /**
     * XACK 确认条目。
     */
    public static function xAck(string $key, string $group, string $id): int
    {
        return (int) self::connection()->xack($key, $group, [$id]);
    }

    /**
     * XDEL 从 Stream 物理移除已确认或失效的条目。
     */
    public static function xDel(string $key, string $id): int
    {
        return (int) self::connection()->xdel($key, [$id]);
    }

    /**
     * XPENDING 查询待确认条目列表或汇总。
     *
     * @return array<int, mixed>
     */
    public static function xPending(string $key, string $group, ?string $start = null, ?string $end = null, int $count = 10): array
    {
        try {
            $conn = self::connection();
            if ($start !== null && $end !== null) {
                $reply = $conn->xpending($key, $group, $start, $end, $count);
            } else {
                $reply = $conn->xpending($key, $group);
            }
            return is_array($reply) ? $reply : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * XLEN：Stream 累计条目数（注意：XACK 不移除条目，此值不是「排队深度」）。
     */
    public static function xLen(string $key): int
    {
        return (int) self::connection()->xlen($key);
    }

    /**
     * XINFO GROUPS 归一化为组信息 map 列表（field => value）。
     *
     * phpredis 不同版本/不同组数下可能返回 map 或扁平 [k,v,k,v] 数组，
     * 统一展开；单组时兼容直接返回该组的 map。
     * 注意：phpredis 对组不存在的 XPENDING 不一定抛异常（可能返回 false），
     * 「组是否存在」必须以本命令的返回列表判定，不能依赖 xpending 的异常。
     *
     * @return array<int, array<string, mixed>>
     * @throws \Throwable key 不存在等 Redis 错误
     */
    public static function xInfoGroups(string $key): array
    {
        $reply = self::connection()->xinfo('GROUPS', $key);
        if (!is_array($reply)) {
            return [];
        }
        // 单组时 phpredis 可能直接返回该组的 field=>value map
        $lists = array_is_list($reply) ? $reply : [$reply];
        $out = [];
        foreach ($lists as $g) {
            if (!is_array($g)) {
                continue;
            }
            if (!array_is_list($g)) {
                $out[] = $g;
                continue;
            }
            // RESP 扁平数组 [field, value, field, value...]
            $pairs = [];
            for ($i = 0; $i + 1 < count($g); $i += 2) {
                if (is_string($g[$i])) {
                    $pairs[$g[$i]] = $g[$i + 1];
                }
            }
            if ($pairs !== []) {
                $out[] = $pairs;
            }
        }
        return $out;
    }

    /**
     * XINFO CONSUMERS 归一化为组内消费者信息 map 列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function xInfoConsumers(string $key, string $group): array
    {
        try {
            $reply = self::connection()->xinfo('CONSUMERS', $key, $group);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($reply)) {
            return [];
        }
        $lists = array_is_list($reply) ? $reply : [$reply];
        $out = [];
        foreach ($lists as $c) {
            if (!is_array($c)) {
                continue;
            }
            if (!array_is_list($c)) {
                $out[] = $c;
                continue;
            }
            $pairs = [];
            for ($i = 0; $i + 1 < count($c); $i += 2) {
                if (is_string($c[$i])) {
                    $pairs[$c[$i]] = $c[$i + 1];
                }
            }
            if ($pairs !== []) {
                $out[] = $pairs;
            }
        }
        return $out;
    }

    /**
     * XGROUP DELCONSUMER 注销闲置的历史僵尸消费者。
     */
    public static function xGroupDelConsumer(string $key, string $group, string $consumer): int
    {
        try {
            return (int) self::connection()->xgroup('DELCONSUMER', $key, $group, $consumer);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * XREAD（非组读）：中继端从 $fromId 之后阻塞读取事件流。
     *
     * @return array<int, array{0: string, 1: array}> [id, fields] 列表
     */
    public static function xRead(string $key, string $fromId, int $blockMs, int $count = 100): array
    {
        $reply = self::connection()->xread([$key => $fromId], $count, $blockMs);
        return self::flattenStreamReply($reply, $key);
    }

    /**
     * XAUTOCLAIM：把空闲超过 $minIdleMs 的 pending 条目改挂到 $consumer 名下。
     *
     * 健壮适配 phpredis 的不同返回结构（关联字典、二元组列表、单键映射），
     * 准确解析真实 Stream ID 并自动处理 Redis 7+ 返回的 deleted-ids（物理删除但仍残留于 PEL 的条目），
     * 避免幽灵 ID 在 PEL 永久驻留。
     *
     * @param string &$cursor 扫描游标（传入起始游标，方法执行后更新为下一轮游标）
     * @return array<int, array{0: string, 1: array}> [id, fields] 列表
     */
    public static function xAutoClaim(string $key, string $group, string $consumer, int $minIdleMs, int $count = 10, string &$cursor = '0-0'): array
    {
        $start = $cursor !== '' ? $cursor : '0-0';
        $reply = self::connection()->xautoclaim($key, $group, $consumer, $minIdleMs, $start, $count);
        // phpredis 返回 [next-cursor, messages, deleted-ids]
        $cursor = isset($reply[0]) && is_string($reply[0]) && $reply[0] !== '' ? $reply[0] : '0-0';
        $messages = $reply[1] ?? [];
        $out = [];
        if (is_array($messages)) {
            foreach ($messages as $k => $v) {
                if (!is_array($v)) {
                    continue;
                }
                // 形态 1：[id => fields] 关联数组
                if (is_string($k) && str_contains($k, '-')) {
                    $out[] = [$k, $v];
                    continue;
                }
                // 形态 2：[[id, fields]] 索引二元组列表
                if (isset($v[0], $v[1]) && is_string($v[0]) && is_array($v[1]) && str_contains($v[0], '-')) {
                    $out[] = [$v[0], $v[1]];
                    continue;
                }
                // 形态 3：[[id => fields]] 索引包裹单键字典
                foreach ($v as $subK => $subV) {
                    if (is_string($subK) && is_array($subV) && str_contains($subK, '-')) {
                        $out[] = [$subK, $subV];
                        break;
                    }
                }
            }
        }

        // Redis 7+ 返回的 deleted-ids（Stream 中已被裁剪/删除但在 PEL 中挂起的条目）：
        // 自动 ACK 消除，防止在消费者组中形成永久游离 pending
        $deletedIds = $reply[2] ?? [];
        if (is_array($deletedIds) && $deletedIds !== []) {
            foreach ($deletedIds as $delId) {
                if (is_string($delId) && $delId !== '') {
                    try {
                        self::xAck($key, $group, $delId);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return $out;
    }

    /**
     * 把 phpredis 的流读取回复拉平为 [id, fields] 列表。
     *
     * phpredis 真实格式（见其 tests/RedisTest.php compareStreamIds）：
     *   [streamKey => [entryId => fields, ...]]
     * 外层兼容以 streamKey 为键的 map 与 [[streamKey => ...]] 数字列表两种形态。
     */
    private static function flattenStreamReply(mixed $reply, string $key): array
    {
        if (!is_array($reply)) {
            return [];
        }

        $entries = null;
        if (isset($reply[$key]) && is_array($reply[$key])) {
            $entries = $reply[$key];
        } else {
            foreach ($reply as $chunk) {
                if (is_array($chunk) && isset($chunk[$key]) && is_array($chunk[$key])) {
                    $entries = $chunk[$key];
                    break;
                }
            }
        }
        if ($entries === null) {
            return [];
        }

        $out = [];
        foreach ($entries as $id => $fields) {
            if (is_string($id) && is_array($fields)) {
                $out[] = [$id, $fields];
            }
        }
        return $out;
    }

    /* ─── 队列辅助键的普通命令（透传 RedisClient 的 protected op*） ─── */

    public static function set(string $key, string $value, int $ttl): void
    {
        self::opSet($key, $value, $ttl);
    }

    public static function setNxEx(string $key, string $value, int $ttl): bool
    {
        return (bool) self::connection()->set($key, $value, ['nx', 'ex' => $ttl]);
    }

    public static function get(string $key): ?string
    {
        return self::opGet($key);
    }

    public static function del(string $key): bool
    {
        return self::opDel($key);
    }

    public static function expire(string $key, int $seconds): bool
    {
        return self::opExpire($key, $seconds);
    }

    public static function incr(string $key): int
    {
        return self::opIncr($key);
    }
}
