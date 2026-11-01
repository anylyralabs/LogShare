<?php

namespace App\Controller;

use App\ApiError;
use App\Data\Token;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class LogController extends AbstractController
{
    #[PostMapping(path: 'log')]
    public function create(): ResponseInterface
    {
        // 1. IP 黑名单校验
        $this->checkIpBan();

        $parsed = $this->parseContent();
        if ($parsed instanceof ApiError) {
            throw $parsed;
        }

        $files = $parsed['files'] ?? [];
        $metadata = $parsed['metadata'] ?? [];
        $source = isset($parsed['source']) && is_string($parsed['source']) ? $parsed['source'] : null;
        $mainFile = isset($parsed['mainFile']) && is_string($parsed['mainFile']) ? $parsed['mainFile'] : null;

        if (!is_array($files) || $files === []) {
            throw new ApiError(400, "At least one file is required.");
        }

        $log = new \App\Log();
        $token = new Token();

        // 上传阶段逐文件脱敏并执行 SpinYarn 反混淆；全文 Codex 分析不在这里执行。
        $id = $log->put($files, $token, is_array($metadata) ? $metadata : [], $source);
        if ($id === null) {
            throw new ApiError(500, 'Failed to persist log.');
        }

        $urls = \App\Config::Get('urls');
        $apiPrefix = $this->apiPrefix();
        $idValue = $id->get();

        $filePayload = $this->fileListPayload($log);
        $payload = [
            'id' => $idValue,
            'url' => $urls['baseUrl'] . "/" . $idValue,
            'filesUrl' => $urls['apiBaseUrl'] . "/{$apiPrefix}/log/" . $idValue,
            'token' => $token->get(),
            'deduplicated' => $log->wasDeduplicated(),
        ] + $filePayload;

        // 旧版 content + files 上传时，raw 必须指向主文件而不是文件列表。
        if ($mainFile !== null && count($filePayload['files']) > 1) {
            $payload['raw'] = $this->fileUrl($idValue, $mainFile, 'raw');
        }

        return $this->respondSuccess($payload, 'Log submitted successfully');
    }

    #[DeleteMapping(path: 'log/{id}')]
    public function delete(string $id): ResponseInterface
    {
        $logIds = array_values(array_filter(array_map('trim', explode(',', $id)), fn($i) => $i !== ''));

        if (empty($logIds)) {
            throw new ApiError(400, "At least one valid ID is required");
        }

        $authorizationHeader = $this->authorizationHeader();
        $requestToken = null;
        if ($authorizationHeader && str_starts_with($authorizationHeader, 'Bearer ')) {
            $requestToken = substr($authorizationHeader, 7);
        }

        if (!$requestToken) {
            throw new ApiError(401, "Missing token in Authorization header. Use: Bearer <token>");
        }

        $results = [
            'deleted' => [],
            'failed' => [],
        ];

        foreach ($logIds as $logId) {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $logId)) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Invalid ID format: {$logId}",
                    'code' => 400,
                ];
                continue;
            }

            $logIdObj = new \App\Id($logId);
            $log = new \App\Log($logIdObj);

            if (!$log->exists()) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Log not found: {$logId}",
                    'code' => 404,
                ];
                continue;
            }

            if (!$log->verifyToken($requestToken)) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Invalid token for log: {$logId}",
                    'code' => 403,
                ];
                continue;
            }

            if ($log->delete()) {
                $results['deleted'][] = $logId;
            } else {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Failed to delete log: {$logId}",
                    'code' => 500,
                ];
            }
        }

        if (empty($results['deleted'])) {
            $errorMessages = array_column($results['failed'], 'message');
            return $this->respondError("Failed to delete logs: " . implode('; ', $errorMessages), 400, $results['failed']);
        }

        return $this->respondSuccess([
            'deleted' => $results['deleted'],
            'failed' => $results['failed'],
            'total' => count($logIds),
            'deletedCount' => count($results['deleted']),
            'failedCount' => count($results['failed']),
        ], 'Log deletion completed');
    }
}
