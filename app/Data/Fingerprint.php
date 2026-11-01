<?php

declare(strict_types=1);

namespace App\Data;

/**
 * 内容指纹：按文件名称排序后的文件集合 + 来源计算 xxh128。
 *
 * 存储后端共用该实现，避免 MongoDB / 测试内存存储的指纹口径漂移。
 */
final class Fingerprint
{
    /**
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     */
    public static function compute(array $files, string $source): string
    {
        $context = hash_init('xxh128');
        hash_update($context, $source);
        hash_update($context, "\0");

        $parts = [];
        foreach ($files as $file) {
            $parts[] = [(string) $file['name'], (string) ($file['data'] ?? '')];
        }
        usort($parts, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        foreach ($parts as [$name, $content]) {
            hash_update($context, $name);
            hash_update($context, "\0");
            hash_update($context, $content);
            hash_update($context, "\0");
        }

        return hash_final($context);
    }
}
