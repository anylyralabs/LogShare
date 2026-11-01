<?php

declare(strict_types=1);

namespace App\Storage;

use App\Client\MongoDBClient;
use App\Data\Fingerprint;
use App\Data\Token;
use App\Id;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\CommandException;

/**
 * MongoDB 存储后端。
 *
 * 数据分两个集合：
 *   - logs：父文档。保存 token、来源、指纹、metadata 与文件描述（不保存内容）；
 *   - log_chunks：文件内容分块。单块 8 MiB，规避 MongoDB 16 MiB BSON 文档上限。
 *
 * 父文档和分块都带 created 字段，MongoDB TTL 索引负责过期删除。读取时会
 * 按 storageTime 过滤，因此即使 TTL 监控尚未真正删除，逻辑过期数据也不会
 * 返回给客户端。
 */
class MongoStorage implements StorageInterface
{
    public const COLLECTION_LOGS = 'logs';
    public const COLLECTION_CHUNKS = 'log_chunks';

    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';

    /** 单个 BSON 分块大小；必须明显低于 MongoDB 16 MiB 文档上限 */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /**
     * TTL 索引比逻辑存储时长多留的宽限。
     *
     * 续期需要同时更新父文档和多个分块，MongoDB 单机模式下没有跨文档事务。
     * 宽限期避免 TTL 监控在续期过程中删掉分块，造成父文档存活但内容缺失。
     */
    public const TTL_GRACE_SECONDS = 120;

    private const MAX_ID_ATTEMPTS = 5;

    /**
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     * @param \App\Data\MetadataEntry[] $metadata
     */
    public static function Put(array $files, ?Token $token = null, array $metadata = [], ?string $source = null, ?bool &$created = null): ?Id
    {
        $normalizedFiles = self::normalizeFiles($files);
        $normalizedSource = ($source !== null && trim($source) !== '')
            ? mb_strcut(trim($source), 0, 64, 'UTF-8')
            : '未指定';

        $fingerprint = Fingerprint::compute($normalizedFiles, $normalizedSource);
        $existing = self::findByFingerprint($fingerprint);
        if ($existing !== null) {
            $created = false;
            return $existing;
        }

        $created = true;

        for ($attempt = 0; $attempt < self::MAX_ID_ATTEMPTS; $attempt++) {
            $id = new Id();
            $rawId = '';
            for ($generated = 0; $generated < self::MAX_ID_ATTEMPTS; $generated++) {
                $id->regenerate();
                $rawId = $id->getRaw();
                if (!self::idExists($rawId)) {
                    break;
                }
            }

            if ($rawId === '' || self::idExists($rawId)) {
                throw new \RuntimeException('Failed to allocate a unique log id after retries.');
            }

            try {
                self::insertLog($rawId, $normalizedFiles, $token, $metadata, $normalizedSource, $fingerprint);
                return Id::fromRaw(self::storageId(), $rawId);
            } catch (\Throwable $e) {
                if ($attempt >= self::MAX_ID_ATTEMPTS - 1 || !self::isDuplicateKeyError($e)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Failed to allocate a unique log id after retries.');
    }

    /**
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     * @param \App\Data\MetadataEntry[] $metadata
     */
    private static function insertLog(string $rawId, array $files, ?Token $token, array $metadata, string $source, string $fingerprint): void
    {
        $logs = MongoDBClient::collection(self::COLLECTION_LOGS);
        $chunks = MongoDBClient::collection(self::COLLECTION_CHUNKS);
        $created = new UTCDateTime(time() * 1000);

        // 先占住 _id，再写分块。这样即使 ID 撞车，也不会把新分块写进
        // 别人的日志；只有 status=ready 的父文档才会被读取。
        $document = [
            '_id' => $rawId,
            'status' => self::STATUS_PENDING,
            'source' => $source,
            'created' => $created,
            'fingerprint' => $fingerprint,
        ];
        if ($token !== null) {
            $document['token'] = $token->get();
        }
        $logs->insertOne($document);

        try {
            $descriptors = [];
            $chunkDocuments = [];

            foreach ($files as $fileIndex => $file) {
                $data = (string) ($file['data'] ?? '');
                $size = strlen($data);
                $chunkCount = $size === 0 ? 0 : (int) ceil($size / self::CHUNK_BYTES);

                $descriptors[] = [
                    'name' => (string) $file['name'],
                    'size' => $size,
                    'chunks' => $chunkCount,
                ];

                for ($seq = 0; $seq < $chunkCount; $seq++) {
                    $chunkDocuments[] = [
                        'log_id' => $rawId,
                        'file_index' => (int) $fileIndex,
                        'seq' => $seq,
                        'data' => new Binary(substr($data, $seq * self::CHUNK_BYTES, self::CHUNK_BYTES), Binary::TYPE_GENERIC),
                        'created' => $created,
                    ];
                }
            }

            if ($chunkDocuments !== []) {
                $chunks->insertMany($chunkDocuments);
            }

            $metadataRows = [];
            $seenKeys = [];
            foreach ($metadata as $entry) {
                $serialized = $entry->jsonSerialize();
                $key = $serialized['key'] ?? null;
                if (!is_string($key) || $key === '' || isset($seenKeys[$key])) {
                    continue;
                }
                $seenKeys[$key] = true;
                $metadataRows[] = $serialized;
            }

            $result = $logs->updateOne(
                ['_id' => $rawId, 'status' => self::STATUS_PENDING],
                ['$set' => [
                    'status' => self::STATUS_READY,
                    'files' => $descriptors,
                    'metadata' => $metadataRows,
                ]]
            );

            if ($result->getMatchedCount() !== 1) {
                throw new \RuntimeException('Failed to finalize MongoDB log document.');
            }
        } catch (\Throwable $e) {
            self::cleanupPartial($rawId);
            throw $e;
        }
    }

    private static function cleanupPartial(string $rawId): void
    {
        try {
            MongoDBClient::collection(self::COLLECTION_CHUNKS)->deleteMany(['log_id' => $rawId]);
            MongoDBClient::collection(self::COLLECTION_LOGS)->deleteOne([
                '_id' => $rawId,
                'status' => self::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            // 清理失败不覆盖原始异常；pending 文档和分块会由 TTL 兜底回收。
            \App\Syslog::error('MongoStorage', 'partial upload cleanup failed: ' . $e->getMessage());
        }
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

    private static function findByFingerprint(string $fingerprint): ?Id
    {
        $document = self::asArray(MongoDBClient::collection(self::COLLECTION_LOGS)->findOne(
            [
                'fingerprint' => $fingerprint,
                'status' => self::STATUS_READY,
                'created' => ['$gt' => self::logicalCutoff()],
            ],
            [
                'sort' => ['created' => -1],
                'projection' => ['_id' => 1],
            ]
        ));

        return $document === null
            ? null
            : Id::fromRaw(self::storageId(), (string) $document['_id']);
    }

    private static function idExists(string $rawId): bool
    {
        return self::asArray(MongoDBClient::collection(self::COLLECTION_LOGS)->findOne(
            ['_id' => $rawId],
            ['projection' => ['_id' => 1]]
        )) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function Get(Id $id, bool $includeContent = true): ?array
    {
        $rawId = $id->getRaw();
        $document = self::asArray(MongoDBClient::collection(self::COLLECTION_LOGS)->findOne([
            '_id' => $rawId,
            'status' => self::STATUS_READY,
            'created' => ['$gt' => self::logicalCutoff()],
        ]));

        if ($document === null) {
            return null;
        }

        /** @var array<int, array{name:string,size:int,chunks:int}> $descriptors */
        $descriptors = $document['files'] ?? [];

        $chunksByFile = [];
        if ($includeContent) {
            $cursor = MongoDBClient::collection(self::COLLECTION_CHUNKS)->find(
                ['log_id' => $rawId],
                ['sort' => ['file_index' => 1, 'seq' => 1]]
            );
            foreach ($cursor as $chunkDocument) {
                $chunk = self::asArray($chunkDocument);
                if ($chunk === null) {
                    continue;
                }
                $fileIndex = (int) $chunk['file_index'];
                $seq = (int) $chunk['seq'];
                $chunksByFile[$fileIndex][$seq] = (string) $chunk['data'];
            }
        }

        $files = [];
        foreach ($descriptors as $fileIndex => $descriptor) {
            $name = (string) $descriptor['name'];
            $size = (int) $descriptor['size'];
            $expectedChunks = (int) $descriptor['chunks'];

            $entry = ['name' => $name, 'size' => $size];
            if ($includeContent) {
                $parts = $chunksByFile[(int) $fileIndex] ?? [];
                ksort($parts);
                $data = implode('', $parts);
                if (count($parts) !== $expectedChunks || strlen($data) !== $size) {
                    \App\Syslog::error('MongoStorage', "chunk mismatch for log {$rawId}, file {$name}");
                    return null;
                }
                $entry['data'] = $data;
            }
            $files[] = $entry;
        }

        usort($files, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'token' => $document['token'] ?? null,
            'metadata' => $document['metadata'] ?? [],
            'source' => isset($document['source']) && (string) $document['source'] !== ''
                ? (string) $document['source']
                : '未指定',
            'created' => self::createdTimestamp($document['created'] ?? null),
            'files' => $files,
        ];
    }

    /**
     * 续期：父文档与全部分块同步更新 created。
     *
     * 先更新分块再更新父文档：如果中途失败，父文档仍是旧时间并最终过期，
     * 不会出现“父文档续期成功、分块先被删”的正常路径。
     */
    public static function Renew(Id $id): bool
    {
        $rawId = $id->getRaw();
        $logs = MongoDBClient::collection(self::COLLECTION_LOGS);

        $exists = self::asArray($logs->findOne(
            [
                '_id' => $rawId,
                'status' => self::STATUS_READY,
                'created' => ['$gt' => self::logicalCutoff()],
            ],
            ['projection' => ['_id' => 1]]
        ));
        if ($exists === null) {
            return false;
        }

        $now = new UTCDateTime(time() * 1000);
        MongoDBClient::collection(self::COLLECTION_CHUNKS)->updateMany(
            ['log_id' => $rawId],
            ['$set' => ['created' => $now]]
        );

        $result = $logs->updateOne(
            ['_id' => $rawId, 'status' => self::STATUS_READY],
            ['$set' => ['created' => $now]]
        );

        return $result->getMatchedCount() > 0;
    }

    public static function Delete(Id $id): bool
    {
        $rawId = $id->getRaw();
        $result = MongoDBClient::collection(self::COLLECTION_LOGS)->deleteOne(['_id' => $rawId]);
        if ($result->getDeletedCount() === 0) {
            return false;
        }

        try {
            MongoDBClient::collection(self::COLLECTION_CHUNKS)->deleteMany(['log_id' => $rawId]);
        } catch (\Throwable $e) {
            // 父文档已删，分块会由 TTL 兜底，不应该让删除接口误报失败。
            \App\Syslog::error('MongoStorage', 'chunk deletion failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * 把遗留的无前缀 token 哈希补齐 `sha256:` 前缀（惰性迁移用）。
     */
    public static function UpgradeToken(Id $id, string $normalizedToken): bool
    {
        $result = MongoDBClient::collection(self::COLLECTION_LOGS)->updateOne(
            ['_id' => $id->getRaw()],
            ['$set' => ['token' => $normalizedToken]]
        );

        return $result->getMatchedCount() > 0;
    }

    /**
     * 创建索引并同步 TTL 的 expireAfterSeconds。
     *
     * 该方法可重复执行；scripts/init_mongodb.php 在容器启动与 storageTime
     * 变更后调用。
     */
    public static function ensureIndexes(): void
    {
        $logs = MongoDBClient::collection(self::COLLECTION_LOGS);
        $chunks = MongoDBClient::collection(self::COLLECTION_CHUNKS);

        self::ensureCompoundIndex(
            $logs,
            ['fingerprint' => 1, 'status' => 1, 'created' => -1],
            ['name' => 'idx_fingerprint_status_created']
        );
        self::ensureUniqueIndex(
            $chunks,
            ['log_id' => 1, 'file_index' => 1, 'seq' => 1],
            ['name' => 'idx_log_file_chunk']
        );

        self::ensureTtlIndex($logs, self::ttlSeconds());
        self::ensureTtlIndex($chunks, self::ttlSeconds());
    }

    /**
     * 启用 MongoDB 8.0 的后台自动压缩；不支持时返回 false，由
     * MongoCompactProcess 用周期性 compact 兜底。
     */
    public static function ensureAutoCompact(): bool
    {
        $config = \App\Config::Get('mongo');
        if (($config['autoCompact'] ?? true) !== true) {
            return false;
        }

        try {
            // autoCompact 是管理命令，必须在 admin 库执行；自建环境通常需要
            // hostManager 级别的 compact 权限。权限不足时回退到集合级 compact。
            MongoDBClient::client()->selectDatabase('admin')->command([
                'autoCompact' => true,
                'freeSpaceTargetMB' => (int) ($config['freeSpaceTargetMB'] ?? 20),
            ])->toArray();
            self::$autoCompactSupported = true;
            return true;
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'already') || str_contains($message, 'active')) {
                self::$autoCompactSupported = true;
                return true;
            }
            if (str_contains($message, 'no such command')
                || str_contains($message, 'unrecognized')
                || str_contains($message, 'unknown command')
                || str_contains($message, 'command not found')
                || str_contains($message, 'not authorized')
                || str_contains($message, 'unauthorized')) {
                self::$autoCompactSupported = false;
                \App\Syslog::warning('MongoStorage', 'autoCompact is unavailable; scheduled compact fallback is active: ' . $e->getMessage());
                return false;
            }
            throw new \RuntimeException('Failed to enable MongoDB autoCompact: ' . $e->getMessage(), 0, $e);
        }
    }

    private static ?bool $autoCompactSupported = null;

    public static function autoCompactAvailable(): bool
    {
        if (self::$autoCompactSupported === null) {
            self::ensureAutoCompact();
        }

        return self::$autoCompactSupported === true;
    }

    public static function compactionEnabled(): bool
    {
        $config = \App\Config::Get('mongo');
        return ($config['compactionEnabled'] ?? true) === true;
    }

    /**
     * 对日志集合执行一次 compact。MongoDB 7 没有 autoCompact 时的兜底。
     *
     * @return array<string, string> 集合 => ok / auto / error
     */
    public static function compactCollections(): array
    {
        $result = [];
        foreach ([self::COLLECTION_LOGS, self::COLLECTION_CHUNKS] as $collection) {
            try {
                MongoDBClient::database()->command(['compact' => $collection])->toArray();
                $result[$collection] = 'ok';
            } catch (\Throwable $e) {
                $message = strtolower($e->getMessage());
                if (str_contains($message, 'autocompact') || str_contains($message, 'already')) {
                    $result[$collection] = 'auto';
                    continue;
                }
                \App\Syslog::error('MongoStorage', "compact {$collection} failed: " . $e->getMessage());
                $result[$collection] = 'error';
            }
        }

        return $result;
    }

    public static function ttlSeconds(): int
    {
        $storage = \App\Config::Get('storage');
        $storageTime = max(1, (int) ($storage['storageTime'] ?? (7 * 24 * 60 * 60)));

        return $storageTime + self::TTL_GRACE_SECONDS;
    }

    public static function ping(): bool
    {
        return MongoDBClient::ping();
    }

    /**
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     */
    public static function fingerprint(array $files, string $source): string
    {
        return Fingerprint::compute($files, $source);
    }

    private static function storageId(): string
    {
        $storage = \App\Config::Get('storage');
        return (string) ($storage['storageId'] ?? 'm');
    }

    private static function logicalCutoff(): UTCDateTime
    {
        $storage = \App\Config::Get('storage');
        $storageTime = max(1, (int) ($storage['storageTime'] ?? (7 * 24 * 60 * 60)));

        return new UTCDateTime((time() - $storageTime) * 1000);
    }

    private static function createdTimestamp(mixed $value): ?int
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->getTimestamp();
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * @param array<string, int> $keys
     * @param array<string, mixed> $options
     */
    private static function ensureCompoundIndex(Collection $collection, array $keys, array $options): void
    {
        foreach (self::listIndexes($collection) as $index) {
            if ($index->getKey() === $keys) {
                return;
            }
        }

        $collection->createIndex($keys, $options);
    }

    /**
     * @param array<string, int> $keys
     * @param array<string, mixed> $options
     */
    private static function ensureUniqueIndex(Collection $collection, array $keys, array $options): void
    {
        foreach (self::listIndexes($collection) as $index) {
            if ($index->getKey() !== $keys) {
                continue;
            }
            if ($index->isUnique()) {
                return;
            }
            $collection->dropIndex($index->getName());
            break;
        }

        $collection->createIndex($keys, $options + ['unique' => true]);
    }

    private static function ensureTtlIndex(Collection $collection, int $ttlSeconds): void
    {
        $key = ['created' => 1];
        foreach (self::listIndexes($collection) as $index) {
            if ($index->getKey() !== $key) {
                continue;
            }

            try {
                MongoDBClient::database()->command([
                    'collMod' => $collection->getCollectionName(),
                    'index' => [
                        'name' => $index->getName(),
                        'expireAfterSeconds' => $ttlSeconds,
                    ],
                ])->toArray();
                return;
            } catch (\Throwable $e) {
                // 现有 created 索引不是 TTL 索引时，collMod 不能直接加 TTL；
                // 删除后按 TTL 索引重建。权限错误则向上抛出。
                if (!str_contains(strtolower($e->getMessage()), 'ttl')
                    && !str_contains(strtolower($e->getMessage()), 'expire')) {
                    throw $e;
                }
                $collection->dropIndex($index->getName());
                break;
            }
        }

        $collection->createIndex($key, [
            'name' => 'ttl_created',
            'expireAfterSeconds' => $ttlSeconds,
        ]);
    }

    /**
     * @return iterable<\MongoDB\Model\IndexInfo>
     */
    private static function listIndexes(Collection $collection): iterable
    {
        try {
            return $collection->listIndexes();
        } catch (CommandException $e) {
            // NamespaceNotFound：集合尚未创建，交给 createIndex 隐式创建。
            if ($e->getCode() === 26) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * 统一把 MongoDB 返回的 array|object 文档归一化为数组。
     *
     * 连接配置了 typeMap=array，正常情况下已经是数组；这里保留对象兜底，
     * 同时让静态分析获得确定的 array 类型。
     *
     * @return array<array-key, mixed>|null
     */
    private static function asArray(mixed $document): ?array
    {
        if ($document === null) {
            return null;
        }
        if (is_array($document)) {
            return $document;
        }
        if ($document instanceof \MongoDB\Model\BSONDocument) {
            return $document->getArrayCopy();
        }
        if ($document instanceof \MongoDB\Model\BSONArray) {
            return $document->getArrayCopy();
        }
        if ($document instanceof \stdClass) {
            return get_object_vars($document);
        }
        return null;
    }

    private static function isDuplicateKeyError(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'E11000')
            || str_contains(strtolower($e->getMessage()), 'duplicate key');
    }
}
