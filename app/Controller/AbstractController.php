<?php

namespace App\Controller;

use App\ApiError;
use App\ApiResponse;
use App\ContentParser;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

abstract class AbstractController
{
    #[Inject]
    protected RequestInterface $request;

    public function getClientRealIp(): string
    {
        return \App\System\SecurityService::resolveClientIp(
            $this->request->getServerParams(),
            $this->request->getHeaders()
        );
    }

    protected function checkIpBan(): void
    {
        $ip = $this->getClientRealIp();
        if (\App\System\SecurityService::isIpBanned($ip)) {
            throw new ApiError(403, 'Your IP has been blocked from accessing this service.');
        }
    }

    /**
     * @param string|array<string, mixed>|null $content
     * @param array<int, array{name: string, data?: string, size?: int}>|null $files
     */
    protected function checkContentSecurity(string|array|null $content, ?array $files = null): void
    {
        if (is_string($content)) {
            \App\System\SecurityService::validateContent($content);
        } elseif (is_array($content)) {
            if (isset($content['content']) && is_string($content['content'])) {
                \App\System\SecurityService::validateContent($content['content']);
            }
            if (!empty($content['files']) && is_array($content['files'])) {
                foreach ($content['files'] as $f) {
                    if (is_array($f) && isset($f['data']) && is_string($f['data'])) {
                        \App\System\SecurityService::validateContent($f['data']);
                    }
                }
            }
        }

        if (!empty($files)) {
            foreach ($files as $f) {
                if (isset($f['data'])) {
                    \App\System\SecurityService::validateContent($f['data']);
                }
            }
        }
    }

    /**
     * @return string|ApiError|array<string, mixed>
     */
    protected function parseContent(): string|ApiError|array
    {
        return (new ContentParser($this->request))->getContent();
    }

    /**
     * @param array<string, mixed>|string|ApiError $result
     * @return string|array<string, mixed>
     */
    protected function validateContentExists(array|string|ApiError $result): string|array
    {
        if ($result instanceof ApiError) {
            throw $result;
        }
        return $result;
    }

    protected function apiPrefix(): string
    {
        $path = $this->request->getUri()->getPath();
        return str_starts_with($path, '/1/') ? '1' : 'v1';
    }

    protected function authorizationHeader(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');
        return $header !== '' ? $header : null;
    }

    /**
     * 获取请求体解析数据。优先获取框架解析的 ParsedBody，
     * 若为空则安全回退尝试从原始请求体反序列化 JSON。
     *
     * @return array<mixed>
     */
    protected function getParsedBody(): array
    {
        $parsed = $this->request->getParsedBody();
        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }

        try {
            $stream = $this->request->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $raw = (string) $stream;
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return is_array($parsed) ? $parsed : [];
    }

    protected function respondSuccess(mixed $data, string $message = 'OK'): PsrResponseInterface
    {
        return ApiResponse::success($data, $message);
    }

    protected function respondJson(mixed $data): PsrResponseInterface
    {
        return ApiResponse::json($data);
    }

    protected function respondRawJson(string $json): PsrResponseInterface
    {
        return ApiResponse::jsonRaw($json);
    }

    protected function respondError(string $message, int $code = 400, mixed $details = null): PsrResponseInterface
    {
        return ApiResponse::error($message, $code, $details);
    }

    protected function respondText(string $text, string $contentType = 'text/plain; charset=utf-8'): PsrResponseInterface
    {
        return ApiResponse::text($text, $contentType);
    }
}
