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
    /** 单次请求体最大允许解压字节数（20MB，与 ContentParser 限制对齐），防御 Gzip 炸弹 OOM */
    protected const int MAX_DECOMPRESSED_BYTES = 20 * 1024 * 1024;

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

    private function decodeBody(string $rawBody): ?string
    {
        set_error_handler(static fn() => true);
        try {
            // Gzip magic header: 1f 8b
            if (str_starts_with($rawBody, "\x1f\x8b")) {
                $decompressed = gzdecode($rawBody, static::MAX_DECOMPRESSED_BYTES);
                if ($decompressed !== false) {
                    return $decompressed;
                }
            }

            // Deflate (raw RFC 1951 or zlib RFC 1950)
            $decompressed = gzinflate($rawBody, static::MAX_DECOMPRESSED_BYTES);
            if ($decompressed === false) {
                $decompressed = gzuncompress($rawBody, static::MAX_DECOMPRESSED_BYTES);
            }
            if ($decompressed !== false) {
                return $decompressed;
            }

            // Brotli (RFC 7932)
            if (function_exists('brotli_uncompress')) {
                try {
                    $decompressed = brotli_uncompress($rawBody);
                    if ($decompressed !== false && strlen($decompressed) <= static::MAX_DECOMPRESSED_BYTES) {
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
