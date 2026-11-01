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
     * @return array<string, mixed>|ApiError
     */
    protected function parseContent(): array|ApiError
    {
        return (new ContentParser($this->request))->getContent();
    }

    /**
     * Build the public file list with absolute raw/insights URLs.
     *
     * @return array{id: string, fileCount: int, requiresFileSelection: bool, files: array<int, array{name: string, size: int, lines: int, raw: string, insights: string}>}
     */
    protected function fileListPayload(\App\Log $log): array
    {
        $id = $log->getId();
        $idValue = $id !== null ? $id->get() : '';
        $files = [];

        foreach ($log->getFiles() as $file) {
            $name = $file['name'];
            $files[] = [
                'name' => $name,
                'size' => $file['size'],
                'lines' => $file['lines'],
                'raw' => $this->fileUrl($idValue, $name, 'raw'),
                'insights' => $this->fileUrl($idValue, $name, 'insights'),
            ];
        }

        return [
            'id' => $idValue,
            'fileCount' => count($files),
            'requiresFileSelection' => count($files) > 1,
            'files' => $files,
        ];
    }

    private function fileUrl(string $id, string $name, string $endpoint): string
    {
        $encodedName = implode('/', array_map('rawurlencode', explode('/', $name)));
        $urls = \App\Config::Get('urls');
        $apiPrefix = $this->apiPrefix();

        return $urls['apiBaseUrl'] . "/{$apiPrefix}/{$endpoint}/{$id}/{$encodedName}";
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
