<?php

declare(strict_types=1);

namespace App\Queue\Handler;

use App\ApiError;
use App\Id;
use App\Log;
use App\Queue\QueueEvent;
use App\System\AuditLogManager;
use App\System\SecurityService;

/**
 * 异步关键词与安全规则审核事件处理器。
 */
class SecurityAuditHandler implements EventHandlerInterface
{
    public function getName(): string
    {
        return 'security_audit';
    }

    /**
     * 实例调用处理。
     */
    public function handle(QueueEvent $event): bool
    {
        $payload = $event->getPayload();
        $logId = $payload['logId'] ?? null;
        if (!is_string($logId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $logId)) {
            $event->setLastError("Invalid logId format: " . json_encode($logId));
            return false;
        }

        try {
            $log = new Log(new Id($logId));
            if (!$log->exists()) {
                // 日志不存在（可能已被删除），安全退出，阻断后续
                $event->stopPropagation();
                return true;
            }

            $content = $log->getContent();
            $files = $log->getRawFiles();

            $violationReason = null;
            try {
                SecurityService::validateContent($content);
                foreach ($files as $f) {
                    $fileData = $f['data'] ?? '';
                    if ($fileData !== '') {
                        SecurityService::validateContent($fileData);
                    }
                }
            } catch (ApiError $e) {
                $violationReason = $e->getMessage();
            }

            if ($violationReason === null) {
                // 合规，继续允许后续处理器（如反混淆）执行
                return true;
            }

            // 命中违规，阻断后续所有处理器流转
            $event->stopPropagation();
            $event->setLastError("Content violation: {$violationReason}");

            $clientIp = isset($payload['clientIp']) && is_string($payload['clientIp']) ? trim($payload['clientIp']) : null;

            \App\Syslog::error('SecurityAudit', "Log {$logId} rejected by async security audit: {$violationReason}");

            AuditLogManager::record(
                'security.async_reject',
                $logId,
                [
                    'reason' => $violationReason,
                    'source' => $payload['source'] ?? null,
                ],
                false,
                'event_worker',
                $clientIp
            );

            // 物理删除违规日志及其缓存
            $log->delete();

            // 若非私有或本地 IP，自动实施临时封禁防御
            if (!empty($clientIp) && !SecurityService::isPrivateIp($clientIp) && $clientIp !== '127.0.0.1' && $clientIp !== '::1') {
                SecurityService::banIp($clientIp, 86400, "异步内容审核命中: {$violationReason}", 'event_worker');
            }

            return false;
        } catch (\Throwable $e) {
            $event->setLastError("Audit exception: " . $e->getMessage());
            \App\Syslog::error('SecurityAuditHandler', "Failed to audit log {$logId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 静态兼容方法，支持传入 array payload 或 QueueEvent 对象。
     *
     * @param array<string, mixed>|QueueEvent $event
     * @return bool 审核通过返回 true，若违规被拦截删除或错误则返回 false
     */
    public static function process(array|QueueEvent $event): bool
    {
        $queueEvent = $event instanceof QueueEvent
            ? $event
            : new QueueEvent('log.security_audit', $event);

        return (new self())->handle($queueEvent);
    }
}
