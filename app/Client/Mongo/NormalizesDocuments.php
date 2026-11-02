<?php

declare(strict_types=1);

namespace App\Client\Mongo;

use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * 把 MongoDB 返回的文档统一归一化为数组。
 *
 * ext-mongodb 的 typeMap 在不同版本/路径下可能返回 array、BSONDocument 或
 * stdClass；GoTask 的 BSON 解码通常已经返回 array。调用方只处理数组。
 */
trait NormalizesDocuments
{
    /**
     * @return array<string, mixed>|null
     */
    protected static function asArray(mixed $document): ?array
    {
        if ($document === null) {
            return null;
        }
        if ($document instanceof BSONDocument || $document instanceof BSONArray) {
            $document = $document->getArrayCopy();
        } elseif ($document instanceof \stdClass) {
            $document = get_object_vars($document);
        }

        if (!is_array($document)) {
            return null;
        }

        $normalized = [];
        foreach ($document as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * 空的 PHP 数组会被 BSON 编码成 array；MongoDB 的 filter/options 等位置
     * 需要 document，因此顶层空数组必须转成 stdClass。
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>|object
     */
    protected static function asDocument(array $value): array|object
    {
        return $value === [] ? new \stdClass() : $value;
    }
}
