<?php

namespace App\Controller;

use App\ApiError;
use App\Data\Token;
use App\Queue\EventQueue;
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

        $content = $this->validateContentExists($this->parseContent());

        $metadata = [];
        $source = null;
        $files = null;
        if (is_array($content)) {
            $metadata = $content['metadata'] ?? [];
            $source = $content['source'] ?? null;
            $files = !empty($content['files']) ? $content['files'] : null;
            $content = $content['content'];

            if (empty($content) && $files !== null) {
                $content = $files[0]['data'] ?? '';
            }
        }

        // 违规内容过滤：若开启异步审核则交由队列处理以大幅提升上传速度，否则保留同步检查
        if (!EventQueue::config()['asyncSecurityAudit']) {
            $this->checkContentSecurity($content, $files);
        }

        if (empty($source)) {
            $ua = $this->request->getHeaderLine('User-Agent');
            $launcher = \App\ContentParser::parseLauncherSource($ua);
            $source = $launcher ?? '未指定';
        }

        $log = new \App\Log();
        $token = new Token();

        $id = $log->put($content, $token, $metadata, $source, $files);
        if ($id === null) {
            throw new ApiError(500, 'Failed to persist log.');
        }

        // 去重命中时没有新内容落库，也没有新的审核/反混淆工作可做：
        // 重复派发只会让队列做无用功，还会重复审核已通过的日志。
        if (!$log->wasDeduplicated()) {
            // 统一异步队列事件调度：反混淆、关键词安全审核等后台排队异步执行
            $dispatched = EventQueue::dispatch(EventQueue::EVENT_LOG_UPLOADED, [
                'logId' => $id->get(),
                'clientIp' => $this->getClientRealIp(),
                'source' => $source,
            ]);
            if (!$dispatched) {
                \App\Syslog::error('EventQueue', "Failed to process uploaded log {$id->get()} after persistence");
            }
        }

        $urls = \App\Config::Get('urls');
        $apiPrefix = $this->apiPrefix();

        $payload = [
            'id' => $id->get(),
            'url' => $urls['baseUrl'] . "/" . $id->get(),
            'raw' => $urls['apiBaseUrl'] . "/{$apiPrefix}/raw/" . $id->get(),
            'token' => $token->get(),
            'deduplicated' => $log->wasDeduplicated(),
        ];

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
