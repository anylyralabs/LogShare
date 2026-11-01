<?php

namespace App;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;

/**
 * API 响应助手类
 * 统一 API 响应格式，返回 PSR-7 ResponseInterface（由 Hyperf 输出）
 */
class ApiResponse
{
    /** 平铺到响应顶层时不可被数据覆盖的协议保留键 */
    private const RESERVED_KEYS = ['success', 'message', 'error', 'code'];

    public static function success(mixed $data = null, string $message = 'Success', int $httpCode = 200): ResponseInterface
    {
        $response = new \stdClass();
        $response->success = true;
        $response->message = $message;

        if ($data !== null) {
            if (is_object($data) || (is_array($data) && !array_is_list($data))) {
                /** @var array<string, mixed> $fields */
                $fields = is_object($data) ? get_object_vars($data) : $data;
                if (array_intersect(array_keys($fields), self::RESERVED_KEYS) === []) {
                    foreach ($fields as $key => $value) {
                        $response->$key = $value;
                    }
                    return self::jsonResponse($response, $httpCode);
                }
                // 数据键与协议保留键冲突时整体降级为 data 字段承载，避免覆盖 success/message
            }
            $response->data = $data;
        }

        return self::jsonResponse($response, $httpCode);
    }

    public static function error(string $message, int $httpCode = 400, mixed $errors = null): ResponseInterface
    {
        $response = new \stdClass();
        $response->success = false;
        $response->error = $message;
        $response->code = $httpCode;

        if ($errors !== null) {
            $response->errors = $errors;
        }

        return self::jsonResponse($response, $httpCode);
    }

    public static function json(mixed $data, int $httpCode = 200): ResponseInterface
    {
        return self::jsonResponse($data, $httpCode);
    }

    /**
     * 输出已序列化好的 JSON 字符串（跳过二次 json_encode，供缓存命中场景直接返回）。
     */
    public static function jsonRaw(string $json, int $httpCode = 200): ResponseInterface
    {
        $response = new \Hyperf\HttpMessage\Server\Response();

        return $response
            ->withStatus($httpCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream($json));
    }

    public static function text(string $content, string $contentType = 'text/plain; charset=utf-8', int $httpCode = 200): ResponseInterface
    {
        $response = new \Hyperf\HttpMessage\Server\Response();

        return $response
            ->withStatus($httpCode)
            ->withHeader('Content-Type', $contentType)
            ->withBody(new SwooleStream($content));
    }

    private static function encodeJson(mixed $data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '{"success":false,"error":"Unable to encode response.","code":500}';
        }
    }

    private static function jsonResponse(mixed $data, int $httpCode): ResponseInterface
    {
        $response = new \Hyperf\HttpMessage\Server\Response();

        return $response
            ->withStatus($httpCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream(self::encodeJson($data)));
    }
}
