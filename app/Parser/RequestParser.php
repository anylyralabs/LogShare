<?php

declare(strict_types=1);

namespace App\Parser;

use Hyperf\HttpMessage\Server\Request\Parser as HyperfParser;
use Hyperf\HttpMessage\Server\RequestParserInterface;
use InvalidArgumentException;

/**
 * Compression-aware request parser for Hyperf HTTP server.
 *
 * Automatically decompresses gzipped, brotli, or deflated request bodies before
 * passing them to format-specific sub-parsers (e.g. JsonParser), preventing
 * BadRequestHttpException on requests with Content-Encoding: gzip/br/deflate.
 */
class RequestParser extends HyperfParser
{
    /** 防御 Gzip 炸弹的硬上限；实际值按 storage.uploadFiles.maxRequestBodyBytes 计算 */
    protected const int MAX_DECOMPRESSED_BYTES = 32 * 1024 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function parse(string $rawBody, string $contentType): array
    {
        $contentType = strtolower($contentType);
        if (! array_key_exists($contentType, $this->parsers)) {
            throw new InvalidArgumentException("The '{$contentType}' request parser is not defined.");
        }

        $parser = $this->parsers[$contentType];
        if (! $parser instanceof RequestParserInterface) {
            throw new InvalidArgumentException("The '{$contentType}' request parser is invalid. It must implement the Hyperf\\HttpMessage\\Server\\RequestParserInterface.");
        }

        if ($rawBody === '') {
            return [];
        }

        // 1. 优先尝试直接原样解析（绝大多数未压缩普通请求直接走通，零解压开销）
        try {
            return $parser->parse($rawBody, $contentType);
        } catch (\Throwable $e) {
            // 普通解析失败，可能为被压缩的请求体
        }

        // 2. 检测并解压压缩流（Content-Encoding: gzip / deflate / zlib）
        $decompressed = $this->decodeBody($rawBody);
        if ($decompressed !== null && $decompressed !== $rawBody) {
            try {
                return $parser->parse($decompressed, $contentType);
            } catch (\Throwable $e) {
                return [];
            }
        }

        // 3. 非压缩且非有效数据，返回空数组交由下游 ContentParser 统一返回规范的 API 错误
        return [];
    }

    private function maxDecompressedBytes(): int
    {
        $storage = \App\Config::Get('storage');
        $upload = $storage['uploadFiles'] ?? [];
        $configured = (int) ($upload['maxRequestBodyBytes'] ?? static::MAX_DECOMPRESSED_BYTES);
        return min(static::MAX_DECOMPRESSED_BYTES, max(1, $configured));
    }

    private function decodeBody(string $rawBody): ?string
    {
        $limit = $this->maxDecompressedBytes();
        set_error_handler(static fn() => true);
        try {
            // Gzip magic header: 1f 8b
            if (str_starts_with($rawBody, "\x1f\x8b")) {
                $decompressed = gzdecode($rawBody, $limit);
                if ($decompressed !== false) {
                    return $decompressed;
                }
            }

            // Deflate (raw RFC 1951 or zlib RFC 1950)
            $decompressed = gzinflate($rawBody, $limit);
            if ($decompressed === false) {
                $decompressed = gzuncompress($rawBody, $limit);
            }
            if ($decompressed !== false) {
                return $decompressed;
            }

            // Brotli (RFC 7932)
            if (function_exists('brotli_uncompress')) {
                try {
                    $decompressed = brotli_uncompress($rawBody);
                    if ($decompressed !== false && strlen($decompressed) <= $limit) {
                        return $decompressed;
                    }
                } catch (\Throwable) {
                    // Ignore malformed brotli payload
                }
            }
        } finally {
            restore_error_handler();
        }

        return null;
    }
}
