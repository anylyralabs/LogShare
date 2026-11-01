<?php

namespace App\Storage;

use App\Data\MetadataEntry;
use App\Data\Token;

interface StorageInterface
{
    /**
     * Put some data in the storage, returns the (new) id for the data
     *
     * @param string $data
     * @param Token|null $token
     * @param MetadataEntry[] $metadata
     * @param string|null $source
     * @param array<int, array{name: string, data?: string, size?: int}>|null $files Additional files stored under the same id
     * @param bool|null $created 出参：true 表示新建，false 表示命中已有记录（去重）
     * @return ?\App\Id ID or null
     */
    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null, ?bool &$created = null): ?\App\Id;

    /**
     * Update existing log data and optional files by id
     *
     * @param \App\Id $id
     * @param string $data
     * @param array<int, array{name: string, data?: string, size?: int}>|null $files
     * @return bool
     */
    public static function Update(\App\Id $id, string $data, ?array $files = null): bool;

    /**
     * Get some data from the storage by id
     *
     * @param \App\Id $id
     * @param bool $includeContent
     * @return array<string, mixed>|null Data array or null
     */
    public static function Get(\App\Id $id, bool $includeContent = true): ?array;

    /**
     * Renew the data to reset the time to live
     *
     * @param \App\Id $id
     * @return bool Success
     */
    public static function Renew(\App\Id $id): bool;

    /**
     * Delete data from the storage by id
     *
     * @param \App\Id $id
     * @return bool Success
     */
    public static function Delete(\App\Id $id): bool;

    /**
     * List logs with pagination and optional filters.
     *
     * @param int $limit
     * @param int $offset
     * @param string|null $source
     * @param int|null $since
     * @param int|null $until
     * @param string|null $keyword
     * @return array<int, array{id: string, size: int, source: ?string, created: int, filesCount: int}>
     */
    public static function List(int $limit = 20, int $offset = 0, ?string $source = null, ?int $since = null, ?int $until = null, ?string $keyword = null): array;

    /**
     * Count total logs matching optional filters.
     *
     * @param string|null $source
     * @param int|null $since
     * @param int|null $until
     * @param string|null $keyword
     * @return int
     */
    public static function Count(?string $source = null, ?int $since = null, ?int $until = null, ?string $keyword = null): int;
}