<?php

namespace Tests\Mocks;

class RedisMock
{
    private static array $data = [];
    private static array $ttl = [];

    /** Lists 模拟：key => [string, ...] */
    private static array $lists = [];
    /** Hashes 模拟：key => [field => string, ...] */
    private static array $hashes = [];
    /** Sorted Sets 模拟：key => [member => score, ...] */
    private static array $zsets = [];

    /** Streams 模拟：key => [['id' => string, 'fields' => array]]（插入序） */
    private static array $streams = [];
    private static int $streamSeq = 0;
    /** key|group => ['delivered' => int, 'pending' => [id => ['consumer' => string, 'at' => float]]] */
    private static array $groups = [];
    /** 故障注入：true 时所有命令抛异常（fail-open 路径测试用） */
    public static bool $failAll = false;

    private static function guard(): void
    {
        if (self::$failAll) {
            throw new \RuntimeException('RedisMock: simulated failure');
        }
    }

    public function __construct() {}

    public function connect(): bool { return true; }
    public function auth(): bool { return true; }
    public function select(): bool { return true; }
    public function ping(): bool { return true; }
    public function isConnected(): bool { return true; }

    public function get(string $key): string|false
    {
        self::guard();
        if (!isset(self::$data[$key])) {
            return false;
        }
        if (isset(self::$ttl[$key]) && self::$ttl[$key] < time()) {
            unset(self::$data[$key], self::$ttl[$key]);
            return false;
        }
        return self::$data[$key];
    }

    public function exists(string $key): bool
    {
        self::guard();
        if (!isset(self::$data[$key])) {
            return false;
        }
        if (isset(self::$ttl[$key]) && self::$ttl[$key] < time()) {
            unset(self::$data[$key], self::$ttl[$key]);
            return false;
        }
        return true;
    }

    public function set(string $key, string $value, string|array|int $mode = 'EX', int $ttl = 0): bool
    {
        self::guard();
        // 支持 ext-redis 的选项数组形式：set($key, $value, ['nx', 'ex' => 10])
        if (is_array($mode)) {
            $ttl = (int) ($mode['ex'] ?? 0);
            if (in_array('nx', $mode, true) && isset(self::$data[$key])) {
                return false;
            }
        }
        self::$data[$key] = $value;
        if ($ttl > 0) {
            self::$ttl[$key] = time() + $ttl;
        }
        return true;
    }

    public function setEx(string $key, int $ttl, string $value): bool
    {
        self::guard();
        self::$data[$key] = $value;
        if ($ttl > 0) {
            self::$ttl[$key] = time() + $ttl;
        }
        return true;
    }

    public function del(string ...$keys): int
    {
        self::guard();
        $count = 0;
        foreach ($keys as $key) {
            $deleted = false;
            if (isset(self::$data[$key])) {
                unset(self::$data[$key], self::$ttl[$key]);
                $deleted = true;
            }
            if (isset(self::$lists[$key])) {
                unset(self::$lists[$key]);
                $deleted = true;
            }
            if (isset(self::$hashes[$key])) {
                unset(self::$hashes[$key]);
                $deleted = true;
            }
            if (isset(self::$streams[$key])) {
                unset(self::$streams[$key]);
                $deleted = true;
            }
            if ($deleted) {
                $count++;
            }
        }
        return $count;
    }

    public function incr(string $key): int
    {
        self::guard();
        $val = (int)(self::$data[$key] ?? 0) + 1;
        self::$data[$key] = (string)$val;
        return $val;
    }

    public function expire(string $key, int $ttl): bool
    {
        self::guard();
        if (isset(self::$data[$key])) {
            self::$ttl[$key] = time() + $ttl;
            return true;
        }
        return false;
    }

    public function ttl(string $key): int
    {
        if (!isset(self::$ttl[$key])) {
            return -2;
        }
        $remaining = self::$ttl[$key] - time();
        return $remaining > 0 ? $remaining : -2;
    }

    /**
     * SCAN 游标遍历。生产代码用 SCAN 替代 KEYS 以避免阻塞 Redis，
     * mock 需要提供同样的语义：第一次返回全部匹配项并把游标置 0。
     */
    public function scan(&$iterator, string $pattern = '*', int $count = 0): array|false
    {
        self::guard();
        $matches = $this->keys($pattern);
        // 必须与 phpredis 一致：遍历结束时游标是 int 0，
        // 调用方用 `!== 0` 严格比较，字符串 '0' 会导致死循环。
        $iterator = 0;
        return $matches;
    }

    /** @param array<string, string> $fields */
    public function hExists(string $key, string $field): bool
    {
        self::guard();
        return isset(self::$hashes[$key][$field]);
    }

    public function hLen(string $key): int
    {
        self::guard();
        return isset(self::$hashes[$key]) ? count(self::$hashes[$key]) : 0;
    }

    public function keys(string $pattern): array
    {
        $allKeys = array_unique(array_merge(
            array_keys(self::$data),
            array_keys(self::$lists),
            array_keys(self::$hashes),
            array_keys(self::$streams)
        ));
        // 必须先按段分割再 preg_quote，否则 `*` 会先被转义成 `\*`，
        // 后续的 str_replace('*', ...) 就再也匹配不到，key 永远返回空数组。
        $regexParts = array_map(
            static fn(string $part): string => preg_quote($part, '/'),
            explode('*', $pattern)
        );
        $regex = '/^' . implode('.*', $regexParts) . '$/';
        return array_values(array_filter($allKeys, fn($k) => preg_match($regex, $k)));
    }

    /* ─── Lists（遥测/日志队列用） ─── */

    public function lpush(string $key, string ...$values): int
    {
        self::guard();
        if (!isset(self::$lists[$key])) {
            self::$lists[$key] = [];
        }
        foreach ($values as $val) {
            array_unshift(self::$lists[$key], $val);
        }
        return count(self::$lists[$key]);
    }

    public function llen(string $key): int
    {
        self::guard();
        return isset(self::$lists[$key]) ? count(self::$lists[$key]) : 0;
    }

    public function lrange(string $key, int $start, int $stop): array
    {
        self::guard();
        if (!isset(self::$lists[$key])) {
            return [];
        }
        $list = self::$lists[$key];
        $len = count($list);
        if ($start < 0) {
            $start = max(0, $len + $start);
        }
        if ($stop < 0) {
            $stop = $len + $stop;
        }
        if ($start > $stop || $start >= $len) {
            return [];
        }
        $length = $stop - $start + 1;
        return array_slice($list, $start, $length);
    }

    public function ltrim(string $key, int $start, int $stop): bool
    {
        self::guard();
        if (!isset(self::$lists[$key])) {
            return true;
        }
        $list = self::$lists[$key];
        $len = count($list);
        if ($start < 0) {
            $start = max(0, $len + $start);
        }
        if ($stop < 0) {
            $stop = $len + $stop;
        }
        if ($start > $stop || $start >= $len) {
            self::$lists[$key] = [];
            return true;
        }
        $length = $stop - $start + 1;
        self::$lists[$key] = array_slice($list, $start, $length);
        return true;
    }

    /* ─── Hashes（遥测聚合指标用） ─── */

    public function hset(string $key, string $field, string $value): int|bool
    {
        self::guard();
        $isNew = !isset(self::$hashes[$key][$field]);
        self::$hashes[$key][$field] = $value;
        return $isNew ? 1 : 0;
    }

    public function hmset(string $key, array $members): bool
    {
        self::guard();
        foreach ($members as $field => $val) {
            self::$hashes[$key][(string) $field] = (string) $val;
        }
        return true;
    }

    public function hget(string $key, string $field): string|false
    {
        self::guard();
        return self::$hashes[$key][$field] ?? false;
    }

    public function hgetall(string $key): array
    {
        self::guard();
        return self::$hashes[$key] ?? [];
    }

    public function hincrby(string $key, string $field, int $value): int
    {
        self::guard();
        $current = (int) (self::$hashes[$key][$field] ?? 0);
        $new = $current + $value;
        self::$hashes[$key][$field] = (string) $new;
        return $new;
    }

    public function hincrbyfloat(string $key, string $field, float $value): float
    {
        self::guard();
        $current = (float) (self::$hashes[$key][$field] ?? 0.0);
        $new = $current + $value;
        self::$hashes[$key][$field] = (string) $new;
        return $new;
    }

    /* ─── Streams（统一事件队列测试用，语义按最小实现） ─── */

    public function xadd(string $key, string $id, array $values, ?int $maxlen = 0, bool $approx = false, bool $nomkstream = false): string
    {
        self::guard();
        $fields = $values;
        $newId = ($id !== null && $id !== '*') ? $id : (++self::$streamSeq) . '-1';
        self::$streams[$key][] = ['id' => $newId, 'fields' => $fields];
        if ($maxlen > 0 && count(self::$streams[$key]) > $maxlen) {
            self::$streams[$key] = array_slice(self::$streams[$key], -$maxlen);
        }
        return $newId;
    }

    public function xlen(string $key): int
    {
        self::guard();
        return count(self::$streams[$key] ?? []);
    }

    public function xdel(string $key, array $ids): int
    {
        self::guard();
        $n = 0;
        if (!isset(self::$streams[$key])) {
            return 0;
        }
        foreach (self::$streams[$key] as $idx => $entry) {
            if (in_array($entry['id'], $ids, true)) {
                unset(self::$streams[$key][$idx]);
                $n++;
            }
        }
        self::$streams[$key] = array_values(self::$streams[$key]);
        return $n;
    }

    public function xgroup(string $operation, string $key, string $group, $id = null, $mkstream = false): int|bool
    {
        self::guard();
        $op = strtoupper($operation);
        $gk = $key . '|' . $group;
        if ($op === 'CREATE') {
            if (isset(self::$groups[$gk])) {
                throw new \RuntimeException('BUSYGROUP Consumer Group name already exists');
            }
            self::$groups[$gk] = ['delivered' => 0, 'pending' => []];
            return true;
        }
        if ($op === 'DELCONSUMER') {
            $consumer = (string) $id;
            $n = 0;
            if (isset(self::$groups[$gk])) {
                foreach (self::$groups[$gk]['pending'] as $entry => $info) {
                    if ($info['consumer'] === $consumer) {
                        $n++;
                    }
                }
            }
            return $n;
        }
        throw new \RuntimeException('RedisMock: unsupported operation: ' . $op);
    }

    public function xreadgroup(string $group, string $consumer, array $keys, int $count = 1, int $block = 0): array
    {
        self::guard();
        $out = [];
        foreach ($keys as $key => $from) {
            if ($from !== '>') {
                continue; // mock 只支持新消息读取
            }
            $gk = $key . '|' . $group;
            if (!isset(self::$groups[$gk])) {
                throw new \RuntimeException("NOGROUP No such consumer group '{$group}' for key name '{$key}'");
            }
            $entries = [];
            $g = &self::$groups[$gk];
            while ($g['delivered'] < count(self::$streams[$key] ?? []) && count($entries) < $count) {
                $entry = self::$streams[$key][$g['delivered']];
                $g['delivered']++;
                $g['pending'][$entry['id']] = ['consumer' => $consumer, 'at' => microtime(true)];
                $entries[$entry['id']] = $entry['fields'];
            }
            unset($g);
            if ($entries !== []) {
                // 与 phpredis 一致：[streamKey => [entryId => fields]]
                $out[$key] = $entries;
            }
        }
        return $out;
    }

    public function xread(array $keys, int $count = 1, int $block = 0): array
    {
        self::guard();
        $out = [];
        foreach ($keys as $key => $fromId) {
            $fromSeq = self::idSeq($fromId);
            $entries = [];
            foreach (self::$streams[$key] ?? [] as $entry) {
                if (self::idSeq($entry['id']) > $fromSeq) {
                    $entries[$entry['id']] = $entry['fields'];
                    if (count($entries) >= $count) {
                        break;
                    }
                }
            }
            if ($entries !== []) {
                $out[$key] = $entries;
            }
        }
        return $out;
    }

    public function xack(string $key, string $group, array $ids): int
    {
        self::guard();
        $gk = $key . '|' . $group;
        $n = 0;
        foreach ($ids as $id) {
            if (isset(self::$groups[$gk]['pending'][$id])) {
                unset(self::$groups[$gk]['pending'][$id]);
                $n++;
            }
        }
        return $n;
    }

    public function xautoclaim(string $key, string $group, string $consumer, int $minIdleMs, string $start = '0-0', int $count = 100): array
    {
        self::guard();
        $gk = $key . '|' . $group;
        $claimed = [];
        $g = &self::$groups[$gk];
        foreach ($g['pending'] ?? [] as $id => $info) {
            if (count($claimed) >= $count) {
                break;
            }
            if (self::idSeq($id) <= self::idSeq($start)) {
                continue;
            }
            if ((microtime(true) - $info['at']) * 1000 >= $minIdleMs) {
                $g['pending'][$id]['consumer'] = $consumer;
                $g['pending'][$id]['at'] = microtime(true);
                foreach (self::$streams[$key] ?? [] as $entry) {
                    if ($entry['id'] === $id) {
                        $claimed[$id] = $entry['fields'];
                        break;
                    }
                }
            }
        }
        unset($g);
        return ['0-0', $claimed, []];
    }

    public function xrange(string $key, string $start, string $end, ?int $count = null): array
    {
        self::guard();
        if (!isset(self::$streams[$key])) {
            return [];
        }
        $entries = self::$streams[$key];
        $out = [];
        foreach ($entries as $entry) {
            $id = $entry['id'];
            if ($start !== '-' && $id < $start) {
                continue;
            }
            if ($end !== '+' && $id > $end) {
                continue;
            }
            $out[$id] = $entry['fields'];
            if ($count !== null && count($out) >= $count) {
                break;
            }
        }
        return $out;
    }

    public function xrevrange(string $key, string $end, string $start, ?int $count = null): array
    {
        self::guard();
        if (!isset(self::$streams[$key])) {
            return [];
        }
        $entries = array_reverse(self::$streams[$key]);
        $out = [];
        foreach ($entries as $entry) {
            $id = $entry['id'];
            if ($end !== '+' && $id > $end) {
                continue;
            }
            if ($start !== '-' && $id < $start) {
                continue;
            }
            $out[$id] = $entry['fields'];
            if ($count !== null && count($out) >= $count) {
                break;
            }
        }
        return $out;
    }

    private static function idSeq(string $id): int
    {
        return (int) explode('-', $id)[0];
    }

    public function xpending(string $key, string $group, ...$args): array
    {
        self::guard();
        $gk = $key . '|' . $group;
        if (!isset(self::$groups[$gk])) {
            throw new \RuntimeException("NOGROUP No such consumer group '{$group}' for key name '{$key}'");
        }
        $g = self::$groups[$gk];
        $ids = array_keys($g['pending']);
        $perConsumer = [];
        foreach ($g['pending'] as $info) {
            $perConsumer[$info['consumer']] = ($perConsumer[$info['consumer']] ?? 0) + 1;
        }
        $consumers = [];
        foreach ($perConsumer as $c => $n) {
            $consumers[] = [$c, (string) $n];
        }
        // XPENDING summary：[count, min-id, max-id, [[consumer, count], ...]]
        return [count($ids), $ids[0] ?? null, $ids ? end($ids) : null, $consumers];
    }

    public function xinfo(string $operation, string $key, ?string $arg = null): array
    {
        self::guard();
        $op = strtoupper($operation);
        if ($op === 'GROUPS') {
            if (!isset(self::$streams[$key])) {
                throw new \RuntimeException('ERR no such key: ' . $key);
            }
            $total = count(self::$streams[$key]);
            $out = [];
            foreach (self::$groups as $gk => $g) {
                [$gkey, $gname] = explode('|', $gk, 2);
                if ($gkey !== $key) {
                    continue;
                }
                $out[] = [
                    'name' => $gname,
                    'consumers' => count(array_unique(array_column($g['pending'], 'consumer'))),
                    'pending' => count($g['pending']),
                    // Redis 7.0+ 的 XINFO GROUPS 字段：组自创建以来累计投递条数
                    'entries-read' => $g['delivered'],
                    'last-delivered-id' => $g['delivered'] > 0 && isset(self::$streams[$key][$g['delivered'] - 1])
                        ? self::$streams[$key][$g['delivered'] - 1]['id']
                        : '0-0',
                    'lag' => max(0, $total - $g['delivered']),
                ];
            }
            return $out;
        }
        if ($op === 'CONSUMERS') {
            $group = $arg ?? '';
            $gk = $key . '|' . $group;
            if (!isset(self::$groups[$gk])) {
                return [];
            }
            $g = self::$groups[$gk];
            $perConsumer = [];
            foreach ($g['pending'] as $info) {
                $c = $info['consumer'];
                $perConsumer[$c] = ($perConsumer[$c] ?? 0) + 1;
            }
            $out = [];
            foreach ($perConsumer as $c => $pending) {
                $out[] = [
                    'name' => $c,
                    'pending' => $pending,
                    'idle' => 1000,
                ];
            }
            return $out;
        }
        throw new \RuntimeException('RedisMock: unsupported xinfo operation ' . $op);
    }

    /** 测试辅助：直接读取事件流全部条目 [id, fields] */
    public static function peekStream(string $key): array
    {
        return array_map(fn($e) => [$e['id'], $e['fields']], self::$streams[$key] ?? []);
    }

    public function mGet(array $keys): array
    {
        self::guard();
        $result = [];
        foreach ($keys as $key) {
            $val = $this->get($key);
            $result[] = $val !== false ? $val : false;
        }
        return $result;
    }

    public function zAdd(string $key, float|int $score, string $member): int
    {
        self::guard();
        if (!isset(self::$zsets[$key])) {
            self::$zsets[$key] = [];
        }
        $isNew = !isset(self::$zsets[$key][$member]);
        self::$zsets[$key][$member] = (float) $score;
        return $isNew ? 1 : 0;
    }

    public function zRevRangeByScore(string $key, string|float $max, string|float $min, array $options = []): array
    {
        self::guard();
        if (!isset(self::$zsets[$key])) {
            return [];
        }
        $items = self::$zsets[$key];
        arsort($items);

        $minVal = ($min === '-inf') ? -INF : (float) $min;
        $maxVal = ($max === '+inf') ? INF : (float) $max;

        $matched = [];
        foreach ($items as $member => $score) {
            if ($score >= $minVal && $score <= $maxVal) {
                $matched[] = (string) $member;
            }
        }

        if (isset($options['limit']) && is_array($options['limit'])) {
            $offset = (int) ($options['limit'][0] ?? 0);
            $count = (int) ($options['limit'][1] ?? count($matched));
            $matched = array_slice($matched, $offset, $count);
        }

        return $matched;
    }

    public function zRangeByScore(string $key, string|float $min, string|float $max, array $options = []): array
    {
        self::guard();
        if (!isset(self::$zsets[$key])) {
            return [];
        }
        $items = self::$zsets[$key];
        asort($items);

        $minVal = ($min === '-inf') ? -INF : (float) $min;
        $maxVal = ($max === '+inf') ? INF : (float) $max;

        $matched = [];
        foreach ($items as $member => $score) {
            if ($score >= $minVal && $score <= $maxVal) {
                $matched[] = (string) $member;
            }
        }

        if (isset($options['limit']) && is_array($options['limit'])) {
            $offset = (int) ($options['limit'][0] ?? 0);
            $count = (int) ($options['limit'][1] ?? count($matched));
            $matched = array_slice($matched, $offset, $count);
        }

        return $matched;
    }

    public function zRem(string $key, string ...$members): int
    {
        self::guard();
        if (!isset(self::$zsets[$key])) {
            return 0;
        }
        $removed = 0;
        foreach ($members as $m) {
            if (isset(self::$zsets[$key][$m])) {
                unset(self::$zsets[$key][$m]);
                $removed++;
            }
        }
        return $removed;
    }

    public function zRemRangeByRank(string $key, int $start, int $end): int
    {
        self::guard();
        if (!isset(self::$zsets[$key])) {
            return 0;
        }
        asort(self::$zsets[$key]);
        $keys = array_keys(self::$zsets[$key]);
        $total = count($keys);
        if ($start < 0) $start = max(0, $total + $start);
        if ($end < 0) $end = max(-1, $total + $end);
        if ($start > $end || $start >= $total) {
            return 0;
        }
        $toRemove = array_slice($keys, $start, $end - $start + 1);
        foreach ($toRemove as $k) {
            unset(self::$zsets[$key][$k]);
        }
        return count($toRemove);
    }

    public static function reset(): void
    {
        self::$data = [];
        self::$ttl = [];
        self::$lists = [];
        self::$hashes = [];
        self::$streams = [];
        self::$groups = [];
        self::$zsets = [];
        self::$failAll = false;
    }
}