<?php

declare(strict_types=1);

require 'core.php';

$adminToken = (string) \App\Config::Get('admin')['token'];
echo "Admin token configured: " . ($adminToken !== '' ? 'YES (length ' . strlen($adminToken) . ')' : 'NO') . "\n";

$baseUrl = 'https://127.0.0.1';
$host = getenv('API_HOST') ?: 'api.logshare.cn';

/**
 * @param non-empty-string $method
 * @param array<string|int, string> $headers
 * @return array{status: int, body: string, error: string}
 */
function request(string $method, string $path, array $headers = [], ?string $body = null): array
{
    global $baseUrl, $host, $adminToken;
    $ch = curl_init("{$baseUrl}{$path}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $defaultHeaders = [
        "Host: {$host}",
    ];
    if ($adminToken !== '' && str_contains($path, '/admin/')) {
        $defaultHeaders[] = "Authorization: Bearer {$adminToken}";
    }
    foreach ($headers as $k => $v) {
        $defaultHeaders[] = is_int($k) ? $v : "{$k}: {$v}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeaders);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $response = is_string($response) ? $response : '';
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => $response,
        'error' => $error,
    ];
}

$endpoints = [
    // 1. 公开端点
    ['GET', '/v1/limits', [], null, '公开存储与格式上限'],
    ['GET', '/v1/filters', [], null, '公开过滤器列表'],
    ['GET', '/', [], null, 'API 根目录索引'],
    ['GET', '/security/stats', [], null, 'WAF 统计公开概览'],
    ['GET', '/stats/data', [], null, 'OpenLiteStats 访问统计数据'],

    // 2. Admin 核心大盘与统计
    ['GET', '/v1/admin/logs?page=1&limit=5', [], null, 'Admin 日志检索分页'],
    ['GET', '/v1/admin/system/stats', [], null, 'Admin 系统核心运行指标'],
    ['GET', '/v1/admin/system/storage-health', [], null, 'Admin 存储健康诊断'],
    ['GET', '/v1/admin/security/overview', [], null, 'Admin 安全态势感知概览'],
    ['GET', '/v1/admin/security/bans', [], null, 'Admin 封禁 IP 列表'],
    ['GET', '/v1/admin/security/content-rules', [], null, 'Admin 违规内容规则'],
    ['GET', '/v1/admin/analytics/sources', [], null, 'Admin 客户端来源分布'],
    ['GET', '/v1/admin/analytics/versions', [], null, 'Admin 版本与加载器矩阵'],
    ['GET', '/v1/admin/analytics/trends?hours=24', [], null, 'Admin 趋势指标分析'],

    // 3. Admin 统一事件队列与死信
    ['GET', '/v1/admin/event-queue/stats', [], null, 'Admin 事件队列概况指标'],
    ['GET', '/v1/admin/event-queue/dead?limit=10', [], null, 'Admin 死信队列列表'],

    // 4. Admin 审计与系统日志
    ['GET', '/v1/admin/audit/logs?limit=10', [], null, 'Admin 操作审计日志'],
    ['GET', '/v1/admin/telemetry/stats', [], null, 'Admin 遥测统计聚合'],
    ['GET', '/v1/admin/system/logs?limit=10', [], null, 'Admin 系统诊断日志'],

    // 5. Admin 配置接口
    ['GET', '/v1/admin/config', [], null, 'Admin 配置读取 (脱敏)'],

    // 6. 已知领域知识接口
];

echo "\n=== 开始执行线上 API 全量探查 ===\n\n";

$failedCount = 0;
$passedCount = 0;

foreach ($endpoints as [$method, $path, $headers, $body, $desc]) {
    $res = request($method, $path, $headers, $body);
    $status = $res['status'];
    $isOk = ($status >= 200 && $status < 300);
    $icon = $isOk ? "✓" : "✗";

    $jsonPreview = '';
    if ($res['body'] !== '') {
        $decoded = json_decode($res['body'], true);
        if (is_array($decoded)) {
            $keys = array_slice(array_keys($decoded), 0, 5);
            $jsonPreview = ' | keys: ' . implode(',', $keys);
            if (isset($decoded['success']) && !$decoded['success']) {
                $isOk = false;
                $icon = "✗";
                $jsonPreview .= " [ERR: " . ($decoded['error'] ?? $decoded['message'] ?? 'fail') . "]";
            }
        } else {
            $jsonPreview = ' | len: ' . strlen($res['body']) . ' (non-JSON)';
        }
    } else {
        $jsonPreview = ' | EMPTY BODY';
        if ($isOk) {
            $isOk = false;
            $icon = "✗";
        }
    }

    printf("[%s] %-4s %-38s -> %d%s (%s)\n", $icon, $method, $path, $status, $jsonPreview, $desc);
    if ($isOk) {
        $passedCount++;
    } else {
        $failedCount++;
    }
}

// 8. 测试配置更新 PUT /v1/admin/config
echo "\n=== 8. 配置修改实测 ===\n";
$cfgRes = request('GET', '/v1/admin/config');
$cfgData = json_decode($cfgRes['body'], true);
$currentStorageTime = $cfgData['data']['storage']['storageTime'] ?? 604800;
echo "当前 storage.storageTime: " . json_encode($currentStorageTime) . "\n";

$updatePayload = (string) json_encode([
    'storage' => [
        'storageTime' => 604800,
    ],
]);
$updateRes = request('PUT', '/v1/admin/config', ['Content-Type' => 'application/json'], $updatePayload);
printf("PUT /v1/admin/config (with Content-Type) -> %d | body: %s\n", $updateRes['status'], substr($updateRes['body'], 0, 120));

$cfgCheckRes = request('GET', '/v1/admin/config');
$cfgCheckData = json_decode($cfgCheckRes['body'], true);
$updatedStorageTime = $cfgCheckData['data']['storage']['storageTime'] ?? null;
echo "更新后 storage.storageTime: " . json_encode($updatedStorageTime) . "\n";

echo "\n=== 探查完成: 通过 {$passedCount} 项，失败 {$failedCount} 项 ===\n";
