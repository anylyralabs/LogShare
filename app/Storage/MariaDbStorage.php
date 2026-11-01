<?php

namespace App\Storage;

use App\Data\Token;
use Hyperf\DbConnection\Db;

/**
 * MariaDB 存储后端。
 *
 * 日志不再有“正文”字段：每条日志由 log_files 中的零到多个文件组成。
 * `logs` 只保存 id、删除凭证、来源、创建时间与去重指纹。
 *
 * 表结构为按 created 的天级 RANGE 分区（见 docker/mariadb-init.sql）。
 * 两点必须记住：
 *   1. 分区表不支持外键，原 ON DELETE CASCADE 已移除，所有级联删除
 *      都由本类显式完成，漏删任何一张表都会留下孤儿行。
 *   2. 清理走 DROP PARTITION 而非 DELETE：只有前者能真正把磁盘空间
 *      归还操作系统（DELETE 只会让 .ibd 文件内部出现永远不会被回收的空洞）。
 */
class MariaDbStorage implements StorageInterface
{
    protected const TABLE_LOGS = 'logs';
    protected const TABLE_FILES = 'log_files';
    protected const TABLE_METADATA = 'log_metadata';

    /** 单次 CleanupExpired 最多丢弃的分区数上限，限制请求路径内的清理工作量 */
    private const CLEANUP_BATCH = 60;

    /**
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     * @param \App\Data\MetadataEntry[] $metadata
     */
    public static function Put(array $files, ?Token $token = null, array $metadata = [], ?string $source = null, ?bool &$created = null): ?\App\Id
    {
        $normalizedFiles = self::normalizeFiles($files);
        $normalizedSource = ($source !== null && trim($source) !== '')
            ? mb_strcut(trim($source), 0, 64, 'UTF-8')
            : '未指定';

        // 内容指纹：按名称排序后的文件集合 + 来源。命中已有指纹说明同一批
        // 文件被重复上传，直接复用既有 ID，不再占用新的分区空间。
        $fingerprint = self::fingerprint($normalizedFiles, $normalizedSource);
        $existingId = self::findByFingerprint($fingerprint);
        if ($existingId !== null) {
            $created = false;
            return new \App\Id($existingId);
        }

        $created = true;

        $id = new \App\Id();
        $id->setStorage('s');

        // 协程并发下 idExists 预检与 insert 之间存在 TOCTOU 窗口，唯一键冲突时
        // 重新生成 ID 重试。ID 生成同样设上限：库连接异常时不能让 worker 空转。
        $maxAttempts = 5;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            for ($generated = 0; $generated < $maxAttempts; $generated++) {
                $id->regenerate();
                if (!self::idExists($id->getRaw())) {
                    break;
                }
            }

            try {
                Db::transaction(function () use ($id, $normalizedFiles, $token, $metadata, $normalizedSource, $fingerprint) {
                    self::insertLog($id->getRaw(), $normalizedFiles, $token, $metadata, $normalizedSource, $fingerprint);
                });
                return $id;
            } catch (\Throwable $e) {
                // 并发上传同一批文件时唯一键可能落在 fingerprint 上：
                // 此时换 ID 无济于事，重新查一次指纹并按去重命中返回。
                $existingId = self::findByFingerprint($fingerprint);
                if ($existingId !== null) {
                    $created = false;
                    return new \App\Id($existingId);
                }
                if ($attempt >= $maxAttempts - 1 || !self::isDuplicateKeyError($e)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Failed to allocate a unique log id after retries.');
    }

    /**
     * 计算文件集合指纹。
     *
     * 采用 xxh128（非加密哈希，速度约为 SHA-256 的十倍量级）。文件按名称
     * 排序后参与计算，使同一批文件无论上传顺序如何都能得到相同指纹。
     *
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     */
    public static function fingerprint(array $files, string $source): string
    {
        $context = hash_init('xxh128');
        hash_update($context, $source);
        hash_update($context, "\0");

        $parts = [];
        foreach ($files as $file) {
            $parts[] = [(string) $file['name'], (string) ($file['data'] ?? '')];
        }
        usort($parts, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

        foreach ($parts as [$name, $content]) {
            hash_update($context, $name);
            hash_update($context, "\0");
            hash_update($context, $content);
            hash_update($context, "\0");
        }

        return hash_final($context);
    }

    /**
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     * @return array<int, array{name: string, data: string, size: int}>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $file) {
            $data = (string) ($file['data'] ?? '');
            $normalized[] = [
                'name' => (string) $file['name'],
                'data' => $data,
                'size' => isset($file['size']) ? (int) $file['size'] : strlen($data),
            ];
        }
        return $normalized;
    }

    /**
     * 按指纹查找已存在的日志 ID。
     *
     * 走 idx_fingerprint 的等值匹配，成本是一次 B-tree 定位，与日志总量无关。
     * 返回最新的一条：最接近当前时间的那条剩余 TTL 最长，交还给客户端的 ID
     * 不至于马上失效。
     */
    private static function findByFingerprint(string $fingerprint): ?string
    {
        try {
            $raw = Db::table(self::TABLE_LOGS)
                ->where('fingerprint', $fingerprint)
                ->orderByDesc('created')
                ->value('id');
        } catch (\Throwable $e) {
            // 旧库尚未补齐 fingerprint 列时静默降级为不去重，绝不阻断上传
            \App\Syslog::error('MariaDbStorage', 'fingerprint lookup failed: ' . $e->getMessage());
            return null;
        }

        return $raw === null ? null : \App\Id::fromRaw('s', (string) $raw)->get();
    }

    /**
     * 把遗留的无前缀 token 哈希补齐 `sha256:` 前缀（惰性迁移用）。
     */
    public static function UpgradeToken(\App\Id $id, string $normalizedToken): bool
    {
        return Db::table(self::TABLE_LOGS)
            ->where('id', $id->getRaw())
            ->where('token', 'not like', \App\Data\Token::HASH_PREFIX . '%')
            ->update(['token' => $normalizedToken]) >= 0;
    }

    /**
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     * @param \App\Data\MetadataEntry[] $metadata
     */
    private static function insertLog(string $rawId, array $files, ?Token $token, array $metadata, string $source, string $fingerprint): void
    {
        // 子表的 created 必须与父表一致：分区回收按同一 created 边界进行，
        // 若两者不一致，父表分区被丢弃后子表会留下永久无法回收的孤儿数据。
        $created = time();
        $document = [
            'id' => $rawId,
            'created' => $created,
            'source' => $source,
            'fingerprint' => $fingerprint,
        ];

        if ($token !== null) {
            $document['token'] = $token->get();
        }

        Db::table(self::TABLE_LOGS)->insert($document);

        if (!empty($metadata)) {
            $rows = [];
            $seenKeys = [];
            foreach ($metadata as $entry) {
                $serialized = $entry->jsonSerialize();
                if (empty($serialized['key'])) {
                    continue;
                }
                // 入口已去重，这里再兜一次，避免新增调用点再次触发主键冲突
                if (isset($seenKeys[$serialized['key']])) {
                    continue;
                }
                $seenKeys[$serialized['key']] = true;
                $value = $serialized['value'];
                $rows[] = [
                    'log_id' => $rawId,
                    'key' => $serialized['key'],
                    // 非字符串值统一 JSON 序列化后存储；读取端 decodeMetadataValue 会做对称还原
                    'value' => is_string($value) ? $value : (is_null($value) ? null : json_encode($value, JSON_UNESCAPED_UNICODE)),
                    'label' => $serialized['label'],
                    'visible' => (int) $serialized['visible'],
                    'created' => $created,
                ];
            }
            if (!empty($rows)) {
                Db::table(self::TABLE_METADATA)->insert($rows);
            }
        }

        if (!empty($files)) {
            $rows = [];
            $seenNames = [];
            foreach ($files as $file) {
                $name = $file['name'];
                // 同名文件会撞 (log_id, name, created) 主键
                if ($name === '' || isset($seenNames[$name])) {
                    continue;
                }
                $seenNames[$name] = true;
                $data = (string) ($file['data'] ?? '');
                $rows[] = [
                    'log_id' => $rawId,
                    'name' => $name,
                    'data' => $data,
                    'size' => isset($file['size']) ? (int) $file['size'] : strlen($data),
                    'created' => $created,
                ];
            }
            if (!empty($rows)) {
                Db::table(self::TABLE_FILES)->insert($rows);
            }
        }
    }

    /**
     * 判断异常是否为主键/唯一键冲突（并发下同 ID 竞争写入）。
     */
    private static function isDuplicateKeyError(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate entry')
            || (string) $e->getCode() === '23000';
    }

    /**
     * 还原 metadata 值的原始类型：写入端对非字符串值做了 json_encode，
     * 读取端做对称解码，使存储层（jsonSerialize 保留类型）行为一致。
     */
    private static function decodeMetadataValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * 轻量存在性检查（仅查 logs 表主键，避免 Get() 连带拉取 files/metadata）。
     */
    private static function idExists(string $rawId): bool
    {
        return Db::table(self::TABLE_LOGS)->where('id', $rawId)->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function Get(\App\Id $id, bool $includeContent = true): ?array
    {
        $log = Db::table(self::TABLE_LOGS)->where('id', $id->getRaw())->first();
        if ($log === null) {
            return null;
        }

        $files = [];
        $fileQuery = Db::table(self::TABLE_FILES)
            ->where('log_id', $id->getRaw())
            ->orderBy('name');
        if (!$includeContent) {
            $fileQuery->select(['name', 'size']);
        }
        /** @var \stdClass $file */
        foreach ($fileQuery->get() as $file) {
            $entry = [
                'name' => (string) $file->name,
                'size' => (int) $file->size,
            ];
            if ($includeContent) {
                $entry['data'] = (string) $file->data;
            }
            $files[] = $entry;
        }

        $metadata = [];
        /** @var \stdClass $m */
        foreach (Db::table(self::TABLE_METADATA)->where('log_id', $id->getRaw())->get() as $m) {
            $metadata[] = [
                'key' => (string) $m->key,
                'value' => self::decodeMetadataValue($m->value === null ? null : (string) $m->value),
                'label' => $m->label === null ? null : (string) $m->label,
                'visible' => (bool) $m->visible,
            ];
        }

        /** @var \stdClass $log */
        return [
            'token' => $log->token,
            'metadata' => $metadata,
            'source' => $log->source !== null && (string) $log->source !== '' ? (string) $log->source : '未指定',
            'created' => (int) $log->created,
            'files' => $files,
        ];
    }

    /**
     * 续期：把日志的过期时刻重置到现在。
     *
     * 父表 created 变化后 InnoDB 会把该行物理移动到目标分区（分区表的正常
     * 行为）。子表的 created 必须同步更新，否则父子行会落在不同分区，
     * 之后 DROP PARTITION 只能删掉父表，子表残留成为永久无法回收的孤儿数据。
     */
    public static function Renew(\App\Id $id): bool
    {
        $rawId = $id->getRaw();
        $now = time();

        return Db::transaction(function () use ($rawId, $now) {
            $updated = Db::table(self::TABLE_LOGS)
                ->where('id', $rawId)
                ->update(['created' => $now]);

            Db::table(self::TABLE_METADATA)->where('log_id', $rawId)->update(['created' => $now]);
            Db::table(self::TABLE_FILES)->where('log_id', $rawId)->update(['created' => $now]);

            return $updated > 0;
        });
    }

    public static function Delete(\App\Id $id): bool
    {
        return Db::transaction(function () use ($id) {
            Db::table(self::TABLE_METADATA)->where('log_id', $id->getRaw())->delete();
            Db::table(self::TABLE_FILES)->where('log_id', $id->getRaw())->delete();

            return Db::table(self::TABLE_LOGS)->where('id', $id->getRaw())->delete() > 0;
        });
    }

    /**
     * 回收已过期的日志。
     *
     * 实现方式与旧版截然不同：旧版用 DELETE 逐行删除，而 InnoDB 的 DELETE 只是
     * 把页标记为空洞留在 .ibd 文件内部等待后续复用，磁盘占用只增不减，这正是
     * 服务器空间爆满的根因。改为按天 RANGE 分区后，这里直接 DROP PARTITION，
     * 操作系统层面的空间在同一时刻被真正归还，且该操作是元数据级的瞬时动作，
     * 不产生逐行扫描与 purge 压力。
     *
     * 三张表各自治地丢弃自己确实拥有的过期分区，互不依赖；即使边界错位也不会
     * 整轮作废。最后再按行回收滞留在兜底分区里的过期数据，保证「过期日志一定
     * 会被删掉」这一语义在所有情况下都成立。
     *
     * @return int 本次丢弃的分区数（按表计）与兜底行数之和
     */
    public static function CleanupExpired(): int
    {
        $config = \App\Config::Get('storage');
        $storageTime = (int) ($config['storageTime'] ?? (7 * 24 * 60 * 60));
        $expireBefore = time() - $storageTime;

        // 分区布局由安装期一次铺满，运行期只做丢弃、不做 REORGANIZE。
        // 这里仅确保兜底分区存在（幂等、无副作用），避免极端情况下写入失败。
        \App\System\LogPartitionManager::ensureFallbackPartition();

        $dropped = \App\System\LogPartitionManager::dropExpiredPartitions($expireBefore);

        // 分区回收后理论上不该再有残留的过期行；这里做一次兜底行级清理，
        // 覆盖分区缺失、分区边界异常等退化场景。
        $leftover = \App\System\LogPartitionManager::cleanupStrays($expireBefore, self::CLEANUP_BATCH);

        return $dropped + $leftover;
    }
}
