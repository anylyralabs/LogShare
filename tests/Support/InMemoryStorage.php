<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\MetadataEntry;
use App\Data\Token;
use App\Id;
use App\Storage\MariaDbStorage;
use App\Storage\StorageInterface;

/**
 * 无外部依赖的内存存储，用于单元测试。
 *
 * 生产环境只保留 MariaDB 后端；该实现仅避免测试必须启动数据库。
 */
final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, array{data: string, token: ?string, metadata: array<int, mixed>, source: string, created: int, files: array<int, array{name: string, data: string, size: int}>}> */
    private static array $rows = [];

    /** @var array<string, string> fingerprint => raw id */
    private static array $fingerprints = [];

    private static int $sequence = 0;

    public static function reset(): void
    {
        self::$rows = [];
        self::$fingerprints = [];
        self::$sequence = 0;
    }

    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null, ?bool &$created = null): ?Id
    {
        $normalizedSource = ($source !== null && trim($source) !== '')
            ? mb_strcut(trim($source), 0, 64, 'UTF-8')
            : '未指定';

        $normalizedFiles = [];
        foreach ($files ?? [] as $file) {
            $normalizedFiles[] = [
                'name' => (string) ($file['name'] ?? ''),
                'data' => (string) ($file['data'] ?? ''),
                'size' => isset($file['size']) ? (int) $file['size'] : strlen((string) ($file['data'] ?? '')),
            ];
        }

        $fingerprint = MariaDbStorage::fingerprint($data, $normalizedFiles, $normalizedSource);
        if (isset(self::$fingerprints[$fingerprint], self::$rows[self::$fingerprints[$fingerprint]])) {
            $created = false;
            return Id::fromRaw('s', self::$fingerprints[$fingerprint]);
        }

        $created = true;
        $rawId = sprintf('%06d', ++self::$sequence);
        self::$fingerprints[$fingerprint] = $rawId;
        self::$rows[$rawId] = [
            'data' => $data,
            'token' => $token?->normalizedValue(),
            'metadata' => array_map(static fn(MetadataEntry $entry): array => $entry->jsonSerialize(), $metadata),
            'source' => $normalizedSource,
            'created' => time(),
            'files' => $normalizedFiles,
        ];

        return Id::fromRaw('s', $rawId);
    }

    public static function Update(Id $id, string $data, ?array $files = null): bool
    {
        $rawId = $id->getRaw();
        if (!isset(self::$rows[$rawId])) {
            return false;
        }

        self::$rows[$rawId]['data'] = $data;
        if ($files !== null) {
            $normalized = [];
            foreach ($files as $file) {
                $normalized[] = [
                    'name' => (string) ($file['name'] ?? ''),
                    'data' => (string) ($file['data'] ?? ''),
                    'size' => isset($file['size']) ? (int) $file['size'] : strlen((string) ($file['data'] ?? '')),
                ];
            }
            self::$rows[$rawId]['files'] = $normalized;
        }

        return true;
    }

    public static function Get(Id $id, bool $includeContent = true): ?array
    {
        $row = self::$rows[$id->getRaw()] ?? null;
        if ($row === null) {
            return null;
        }

        $files = [];
        foreach ($row['files'] as $file) {
            $entry = ['name' => $file['name'], 'size' => $file['size']];
            if ($includeContent) {
                $entry['data'] = $file['data'];
            }
            $files[] = $entry;
        }

        return [
            'data' => $row['data'],
            'token' => $row['token'],
            'metadata' => $row['metadata'],
            'source' => $row['source'],
            'created' => $row['created'],
            'files' => $files,
        ];
    }

    public static function Renew(Id $id): bool
    {
        $rawId = $id->getRaw();
        if (!isset(self::$rows[$rawId])) {
            return false;
        }

        self::$rows[$rawId]['created'] = time();
        return true;
    }

    public static function Delete(Id $id): bool
    {
        $rawId = $id->getRaw();
        if (!isset(self::$rows[$rawId])) {
            return false;
        }

        unset(self::$rows[$rawId]);
        foreach (self::$fingerprints as $fingerprint => $mappedRawId) {
            if ($mappedRawId === $rawId) {
                unset(self::$fingerprints[$fingerprint]);
            }
        }

        return true;
    }

    public static function UpgradeToken(Id $id, string $normalizedToken): bool
    {
        $rawId = $id->getRaw();
        if (!isset(self::$rows[$rawId])) {
            return false;
        }

        self::$rows[$rawId]['token'] = $normalizedToken;
        return true;
    }
}
