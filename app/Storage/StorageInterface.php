<?php

namespace App\Storage;

use App\Data\MetadataEntry;
use App\Data\Token;

interface StorageInterface
{
    /**
     * Store a log composed of one or more files.
     *
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     * @param Token|null $token
     * @param MetadataEntry[] $metadata
     * @param string|null $source
     * @param bool|null $created 出参：true 表示新建，false 表示命中已有记录（去重）
     * @return ?\App\Id ID or null
     */
    public static function Put(array $files, ?Token $token = null, array $metadata = [], ?string $source = null, ?bool &$created = null): ?\App\Id;

    /**
     * Get a log and its files by id.
     *
     * @param \App\Id $id
     * @param bool $includeContent
     * @return array<string, mixed>|null Data array or null
     */
    public static function Get(\App\Id $id, bool $includeContent = true): ?array;

    /**
     * Renew the data to reset the time to live.
     *
     * @param \App\Id $id
     * @return bool Success
     */
    public static function Renew(\App\Id $id): bool;

    /**
     * Delete data from the storage by id.
     *
     * @param \App\Id $id
     * @return bool Success
     */
    public static function Delete(\App\Id $id): bool;
}
