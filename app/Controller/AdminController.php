<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiError;
use App\Config;
use App\Id;
use App\Log;
use App\Middleware\AdminAuthMiddleware;
use App\Storage\StorageInterface;
use App\System\AnalyticsService;
use App\System\AuditLogManager;
use App\System\SecurityService;
use App\System\SpinYarnManager;
use App\System\StorageHealthService;
use App\System\SystemLogManager;
use App\Telemetry\TelemetryService;
use App\Version;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller for Admin operations under /{version:v?1}/admin.
 *
 * Protected by AdminAuthMiddleware (requires valid admin Bearer token).
 */
#[Controller(prefix: '/{version:v?1}/admin')]
#[Middleware(AdminAuthMiddleware::class)]
class AdminController extends AbstractController
{
    /**
     * @return class-string<StorageInterface>
     */
    private function getStorageClass(): string
    {
        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';
        $storages = $storageConfig['storages'] ?? [];
        if (!isset($storages[$storageId]['class'])) {
            throw new ApiError(500, "Primary storage [{$storageId}] is not configured");
        }
        /** @var class-string<StorageInterface> $storageClass */
        $storageClass = $storages[$storageId]['class'];
        return $storageClass;
    }

    #[GetMapping(path: 'logs')]
    public function listLogs(): ResponseInterface
    {
        $params = $this->request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $source = isset($params['source']) && $params['source'] !== '' ? (string) $params['source'] : null;
        $since = isset($params['since']) && ctype_digit((string) $params['since']) ? (int) $params['since'] : null;
        $until = isset($params['until']) && ctype_digit((string) $params['until']) ? (int) $params['until'] : null;
        $keyword = isset($params['keyword']) && trim((string) $params['keyword']) !== '' ? trim((string) $params['keyword']) : null;

        $storage = $this->getStorageClass();

        $items = $storage::List($limit, $offset, $source, $since, $until, $keyword);
        $total = $storage::Count($source, $since, $until, $keyword);

        return $this->respondSuccess([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int) ceil($total / $limit),
        ], 'Logs retrieved successfully');
    }

    #[GetMapping(path: 'logs/{id}')]
    public function getLog(string $id): ResponseInterface
    {
        $logId = trim($id);
        if ($logId === '') {
            throw new ApiError(400, 'Log ID is required');
        }

        try {
            $parsedId = new Id($logId);
        } catch (\Throwable) {
            throw new ApiError(400, "Invalid log ID format: {$logId}");
        }

        $log = new Log($parsedId);
        if (!$log->exists()) {
            throw new ApiError(404, "Log not found: {$logId}");
        }

        return $this->respondSuccess([
            'id' => $log->getId()?->get() ?? $logId,
            'size' => $log->getSize(),
            'lines' => $log->getLineNumbers(),
            'created' => $log->getCreated(),
            'expires' => $log->getExpires(),
            'source' => $log->getSource(),
            'files' => $log->getFiles(),
            'metadata' => $log->getMetadata(),
            'content' => $log->getContent(),
        ], 'Log details retrieved successfully');
    }

    #[DeleteMapping(path: 'logs/{id}')]
    public function deleteLogs(string $id): ResponseInterface
    {
        $logIds = array_values(array_filter(array_map('trim', explode(',', $id)), fn($i) => $i !== ''));
        if (empty($logIds)) {
            throw new ApiError(400, 'At least one valid ID is required');
        }

        $results = [
            'deleted' => [],
            'failed' => [],
        ];

        foreach ($logIds as $logId) {
            try {
                $parsedId = new Id($logId);
                $log = new Log($parsedId);
            } catch (\Throwable $e) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Invalid log ID: {$e->getMessage()}",
                    'code' => 400,
                ];
                continue;
            }

            if (!$log->exists()) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Log not found: {$logId}",
                    'code' => 404,
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
            return $this->respondError('Failed to delete logs: ' . implode('; ', $errorMessages), 400, $results['failed']);
        }

        AuditLogManager::record('log.delete', implode(',', $results['deleted']), [
            'total' => count($logIds),
            'deletedCount' => count($results['deleted']),
            'failedCount' => count($results['failed']),
        ], true, 'admin', $this->getClientIp());

        return $this->respondSuccess([
            'deleted' => $results['deleted'],
            'failed' => $results['failed'],
            'total' => count($logIds),
            'deletedCount' => count($results['deleted']),
            'failedCount' => count($results['failed']),
        ], 'Log deletion completed');
    }

    #[GetMapping(path: 'system/stats')]
    public function getSystemStats(): ResponseInterface
    {
        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';
        $storage = $this->getStorageClass();

        $totalLogs = 0;
        try {
            $totalLogs = $storage::Count();
        } catch (\Throwable) {
            // Database might be temporarily unavailable
        }

        return $this->respondSuccess([
            'version' => Version::VERSION,
            'phpVersion' => PHP_VERSION,
            'swooleVersion' => phpversion('swoole') ?: null,
            'storageBackend' => $storageId,
            'totalLogs' => $totalLogs,
            'storageTime' => (int) ($storageConfig['storageTime'] ?? 0),
        ], 'System statistics retrieved successfully');
    }

    #[GetMapping(path: 'config')]
    public function getConfig(): ResponseInterface
    {
        return $this->respondSuccess(Config::getMasked(), 'Configuration retrieved successfully');
    }

    #[PutMapping(path: 'config')]
    public function updateConfig(): ResponseInterface
    {
        $payload = $this->getParsedBody();
        if (empty($payload)) {
            throw new ApiError(400, 'Invalid or empty configuration payload');
        }

        try {
            Config::saveDynamic($payload);
            AuditLogManager::record('config.update', 'dynamic_config', [
                'keys' => array_keys($payload),
            ], true, 'admin', $this->getClientIp());
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, 'Configuration validation failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to save dynamic configuration: ' . $e->getMessage());
        }

        return $this->respondSuccess(Config::getMasked(), 'Configuration updated successfully');
    }

    #[PostMapping(path: 'config/reset')]
    public function resetConfig(): ResponseInterface
    {
        try {
            Config::resetDynamic();
            AuditLogManager::record('config.reset', 'dynamic_config', [], true, 'admin', $this->getClientIp());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to reset configuration: ' . $e->getMessage());
        }

        return $this->respondSuccess(Config::getMasked(), 'Configuration reset to defaults successfully');
    }

    #[GetMapping(path: 'telemetry/stats')]
    public function getTelemetryStats(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $day = isset($params['day']) && is_string($params['day']) && $params['day'] !== '' ? $params['day'] : null;
        $stats = TelemetryService::getStats($day);
        return $this->respondSuccess($stats);
    }

    #[DeleteMapping(path: 'telemetry/stats')]
    public function clearTelemetryStats(): ResponseInterface
    {
        $cleared = TelemetryService::clearStats();
        return $this->respondSuccess(['cleared' => $cleared], 'Telemetry stats cleared');
    }

    #[GetMapping(path: 'system/logs')]
    public function getSystemLogs(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 100;
        $level = isset($params['level']) && is_string($params['level']) && $params['level'] !== '' ? $params['level'] : null;
        $keyword = isset($params['keyword']) && is_string($params['keyword']) && $params['keyword'] !== '' ? $params['keyword'] : null;
        $since = isset($params['since']) && is_numeric($params['since']) ? (int) $params['since'] : null;

        $logs = SystemLogManager::getLogs($limit, $level, $keyword, $since);
        return $this->respondSuccess($logs);
    }

    #[DeleteMapping(path: 'system/logs')]
    public function clearSystemLogs(): ResponseInterface
    {
        $cleared = SystemLogManager::clearLogs();
        return $this->respondSuccess(['cleared' => $cleared], 'System logs cleared');
    }

    #[GetMapping(path: 'analytics/sources')]
    public function getAnalyticsSources(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $days = isset($params['days']) && is_numeric($params['days']) ? (int) $params['days'] : 7;
        $stats = AnalyticsService::getSourceStats($days);
        return $this->respondSuccess($stats, 'Source distribution retrieved successfully');
    }

    #[GetMapping(path: 'analytics/versions')]
    public function getAnalyticsVersions(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $days = isset($params['days']) && is_numeric($params['days']) ? (int) $params['days'] : 30;
        $stats = AnalyticsService::getVersionStats($days);
        return $this->respondSuccess($stats, 'Version statistics retrieved successfully');
    }

    #[GetMapping(path: 'analytics/trends')]
    public function getAnalyticsTrends(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $days = isset($params['days']) && is_numeric($params['days']) ? (int) $params['days'] : 7;
        $trends = AnalyticsService::getTrends($days);
        return $this->respondSuccess($trends, 'Log trends retrieved successfully');
    }

    #[GetMapping(path: 'system/storage-health')]
    public function getStorageHealth(): ResponseInterface
    {
        $health = StorageHealthService::getHealth();
        return $this->respondSuccess($health, 'Storage health diagnostics retrieved successfully');
    }

    #[PostMapping(path: 'system/cleanup-expired')]
    public function cleanupExpiredLogs(): ResponseInterface
    {
        try {
            $result = StorageHealthService::cleanupExpired();
            AuditLogManager::record('system.cleanup_expired', 'logs', $result, true, 'admin', $this->getClientIp());
            return $this->respondSuccess($result, "Cleanup completed: {$result['deletedCount']} logs removed");
        } catch (\Throwable $e) {
            AuditLogManager::record('system.cleanup_expired', 'logs', ['error' => $e->getMessage()], false, 'admin', $this->getClientIp());
            throw new ApiError(500, 'Failed to execute expired log cleanup: ' . $e->getMessage());
        }
    }

    #[PostMapping(path: 'system/cache/flush')]
    public function flushCache(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $prefix = isset($body['prefix']) && is_string($body['prefix']) ? trim($body['prefix']) : 'log:*';
        $result = StorageHealthService::flushCache($prefix);
        AuditLogManager::record('system.cache_flush', $prefix, $result, true, 'admin', $this->getClientIp());
        return $this->respondSuccess($result, "Cache flush executed for prefix: {$prefix}");
    }

    #[PostMapping(path: 'logs/batch-delete')]
    public function batchDeleteLogs(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body)) {
            throw new ApiError(400, 'Invalid request body');
        }

        $source = isset($body['source']) && is_string($body['source']) && trim($body['source']) !== '' ? trim($body['source']) : null;
        $since = isset($body['since']) && is_numeric($body['since']) ? (int) $body['since'] : null;
        $until = isset($body['until']) && is_numeric($body['until']) ? (int) $body['until'] : null;
        $keyword = isset($body['keyword']) && is_string($body['keyword']) && trim($body['keyword']) !== '' ? trim($body['keyword']) : null;
        $limit = isset($body['limit']) && is_numeric($body['limit']) ? min(1000, max(1, (int) $body['limit'])) : 500;

        if ($source === null && $since === null && $until === null && $keyword === null) {
            throw new ApiError(400, 'At least one filter condition (source, since, until, or keyword) is required for batch deletion');
        }

        $storage = $this->getStorageClass();
        $items = $storage::List($limit, 0, $source, $since, $until, $keyword);
        $totalMatched = $storage::Count($source, $since, $until, $keyword);

        $deleted = [];
        $failed = [];

        foreach ($items as $item) {
            $logId = (string) $item['id'];
            try {
                $parsedId = new Id($logId);
                $log = new Log($parsedId);
                if ($log->exists() && $log->delete()) {
                    $deleted[] = $logId;
                } else {
                    $failed[] = $logId;
                }
            } catch (\Throwable) {
                $failed[] = $logId;
            }
        }

        AuditLogManager::record('log.batch_delete', 'batch', [
            'deletedCount' => count($deleted),
            'failedCount' => count($failed),
            'totalMatched' => $totalMatched,
            'criteria' => [
                'source' => $source,
                'since' => $since,
                'until' => $until,
                'keyword' => $keyword,
                'limit' => $limit,
            ],
        ], true, 'admin', $this->getClientIp());

        return $this->respondSuccess([
            'deletedCount' => count($deleted),
            'failedCount' => count($failed),
            'totalMatched' => $totalMatched,
            'remaining' => max(0, $totalMatched - count($deleted)),
        ], 'Batch deletion completed');
    }

    #[GetMapping(path: 'security/bans')]
    public function getSecurityBans(): ResponseInterface
    {
        $bans = SecurityService::getBannedIps();
        return $this->respondSuccess([
            'bans' => $bans,
            'total' => count($bans),
        ], 'Banned IP list retrieved successfully');
    }

    #[PostMapping(path: 'security/ban')]
    public function banIp(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body['ip'])) {
            throw new ApiError(400, 'IP address is required');
        }

        $ip = trim((string) $body['ip']);
        $ttl = isset($body['ttl']) && is_numeric($body['ttl']) ? (int) $body['ttl'] : 86400;
        $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '管理员主动封禁';

        $result = SecurityService::banIp($ip, $ttl, $reason);
        AuditLogManager::record('security.ban', $ip, [
            'ttl' => $ttl,
            'reason' => $reason,
        ], true, 'admin', $this->getClientIp());
        return $this->respondSuccess($result, "IP [{$ip}] successfully banned");
    }

    #[PostMapping(path: 'security/unban')]
    public function unbanIp(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body['ip'])) {
            throw new ApiError(400, 'IP address is required');
        }

        $ip = trim((string) $body['ip']);
        $wafSync = null;
        $success = SecurityService::unbanIp($ip, $wafSync);
        AuditLogManager::record('security.unban', $ip, [
            'unbanned' => $success,
            'waf_sync' => $wafSync,
        ], true, 'admin', $this->getClientIp());

        // waf_sync 必须回传：未配置 OPENLITEWAF_ADMIN_TOKEN 时边缘封禁解不掉，
        // 客户端仍会 403，控制台不能一律提示"已解封"
        return $this->respondSuccess([
            'ip' => $ip,
            'unbanned' => $success,
            'waf_sync' => $wafSync,
        ], "IP [{$ip}] unbanned successfully");
    }

    #[GetMapping(path: 'security/overview')]
    public function getSecurityOverview(): ResponseInterface
    {
        $overview = SecurityService::getWafOverview();
        return $this->respondSuccess($overview, 'Security overview retrieved successfully');
    }

    #[GetMapping(path: 'security/content-rules')]
    public function getContentRules(): ResponseInterface
    {
        $rules = SecurityService::getContentRules();
        return $this->respondSuccess($rules, 'Content reject rules retrieved successfully');
    }

    #[PutMapping(path: 'security/content-rules')]
    public function updateContentRules(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body)) {
            throw new ApiError(400, 'Invalid request body');
        }

        $rules = SecurityService::saveContentRules($body);
        AuditLogManager::record('security.content_rules_update', 'rules', [
            'enabled' => $rules['enabled'],
            'keywordCount' => count($rules['keywords']),
            'patternCount' => count($rules['patterns']),
        ], true, 'admin', $this->getClientIp());
        return $this->respondSuccess($rules, 'Content reject rules updated successfully');
    }

    #[GetMapping(path: 'spinyarn/status')]
    public function getSpinYarnStatus(): ResponseInterface
    {
        $status = SpinYarnManager::getStatus();
        return $this->respondSuccess($status, 'SpinYarn status retrieved successfully');
    }

    #[PostMapping(path: 'spinyarn/test')]
    public function testSpinYarnDeobfuscate(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body['content']) || empty($body['version'])) {
            throw new ApiError(400, 'Both content and version are required');
        }

        $content = (string) $body['content'];
        if (strlen($content) > 256 * 1024) {
            throw new ApiError(400, 'Content exceeds 256KB limit');
        }
        $version = trim((string) $body['version']);
        if (!\App\Client\SpinYarnClient::isValidVersion($version)) {
            throw new ApiError(400, 'Invalid version format');
        }

        // 同时接受 mappingType（优先）与 mapping_type，非法时 400（不再静默默认 yarn）
        $rawType = $body['mappingType'] ?? $body['mapping_type'] ?? 'yarn';
        if (!is_string($rawType)) {
            throw new ApiError(400, 'Invalid mappingType, expected yarn or vanilla');
        }
        $mappingType = \App\Client\SpinYarnClient::normalizeMappingType($rawType);
        if ($mappingType === null) {
            throw new ApiError(400, 'Invalid mappingType, expected yarn or vanilla');
        }

        $result = SpinYarnManager::testDeobfuscate($content, $version, $mappingType);
        return $this->respondSuccess($result, 'SpinYarn deobfuscation test completed');
    }

    #[GetMapping(path: 'audit/logs')]
    public function getAuditLogs(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $pageSize = isset($params['pageSize']) ? (int) $params['pageSize'] : 20;
        $action = isset($params['action']) && $params['action'] !== '' ? (string) $params['action'] : null;
        $keyword = isset($params['keyword']) && $params['keyword'] !== '' ? (string) $params['keyword'] : null;
        $since = isset($params['since']) && is_numeric($params['since']) ? (int) $params['since'] : null;
        $until = isset($params['until']) && is_numeric($params['until']) ? (int) $params['until'] : null;

        $logs = AuditLogManager::getLogs($page, $pageSize, $action, $keyword, $since, $until);
        return $this->respondSuccess($logs, 'Audit logs retrieved successfully');
    }

    #[DeleteMapping(path: 'audit/logs')]
    public function clearAuditLogs(): ResponseInterface
    {
        $count = AuditLogManager::clearLogs();
        return $this->respondSuccess(['cleared' => $count], 'Audit logs cleared');
    }

    #[GetMapping(path: 'event-queue/stats')]
    public function getEventQueueStats(): ResponseInterface
    {
        $stats = \App\Queue\EventQueue::getStats();
        return $this->respondSuccess($stats, 'Event queue stats retrieved successfully');
    }

    #[GetMapping(path: 'event-queue/dead')]
    public function getDeadLetters(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? min(100, max(1, (int) $params['limit'])) : 50;
        $items = \App\Queue\DeadLetterQueue::list($limit);
        return $this->respondSuccess([
            'total' => \App\Queue\DeadLetterQueue::count(),
            'items' => $items,
        ], 'Dead letters retrieved successfully');
    }

    #[PostMapping(path: 'event-queue/dead/retry')]
    public function retryDeadLetter(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $streamId = isset($body['streamId']) && is_string($body['streamId']) ? trim($body['streamId']) : null;
        if (empty($streamId)) {
            throw new ApiError(400, 'Parameter streamId is required');
        }

        $retried = \App\Queue\DeadLetterQueue::retry($streamId);
        if (!$retried) {
            throw new ApiError(404, 'Failed to retry dead letter: item not found or invalid');
        }

        AuditLogManager::record('event_queue.dead_retry', $streamId, [], true, 'admin', $this->getClientIp());
        return $this->respondSuccess(['streamId' => $streamId, 'retried' => true], 'Dead letter retried successfully');
    }

    #[DeleteMapping(path: 'event-queue/dead')]
    public function clearDeadLetters(): ResponseInterface
    {
        $cleared = \App\Queue\DeadLetterQueue::clear();
        AuditLogManager::record('event_queue.dead_clear', 'dead_letters', [], $cleared, 'admin', $this->getClientIp());
        return $this->respondSuccess(['cleared' => $cleared], 'Dead letters cleared');
    }

    private function getClientIp(): string
    {
        return $this->getClientRealIp();
    }
}


