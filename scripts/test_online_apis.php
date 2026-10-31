<?php

declare(strict_types=1);

require 'core.php';

$adminToken = (string) \App\Config::Get('admin.token');
echo "Admin token configured: " . ($adminToken !== '' ? 'YES (length ' . strlen($adminToken) . ')' : 'NO') . "\n";

$baseUrl = 'https://127.0.0.1';
$host = 'api.logshare.cn';

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
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => $response ?: '',
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
    ['GET', '/v1/admin/system/queue', [], null, 'Admin AI 队列指标'],
    ['GET', '/v1/admin/ai/metrics', [], null, 'Admin AI 性能吞吐与耗时'],
    ['GET', '/v1/admin/ai/queue/inspect', [], null, 'Admin AI 队列深度审计'],

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
    ['GET', '/v1/admin/ai/domain-knowledge', [], null, 'Admin 领域知识列表获取'],
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

// 8. 测试领域知识的 POST / PUT / DELETE 生命周期
echo "\n=== 8. 领域知识 CRUD 专项实测 ===\n";
// 测试 A: 模拟前端带 Content-Type: application/json 发送创建
$postPayload = json_encode(['content' => '测试条目：PojavLauncher 遇到 SIGSEGV 时优先切换 gl4es 渲染器', 'enabled' => true]);
$createRes = request('POST', '/v1/admin/ai/domain-knowledge', ['Content-Type' => 'application/json'], $postPayload);
printf("POST /v1/admin/ai/domain-knowledge (with Content-Type) -> %d | body: %s\n", $createRes['status'], substr($createRes['body'], 0, 120));

// 测试 B: 模拟前端未带 Content-Type 发送创建（复现用户反映的问题！）
$createNoCtRes = request('POST', '/v1/admin/ai/domain-knowledge', [], $postPayload);
printf("POST /v1/admin/ai/domain-knowledge (NO Content-Type) -> %d | body: %s\n", $createNoCtRes['status'], substr($createNoCtRes['body'], 0, 120));

$createdId = null;
$createdData = json_decode($createRes['body'], true);
if (isset($createdData['data']['id'])) {
    $createdId = $createdData['data']['id'];
    echo "创建条目 ID: {$createdId}\n";

    // 测试 PUT 更新
    $putPayload = json_encode(['content' => '更新条目：PojavLauncher 在 Android 14+ 必须使用 gl4es 渲染器', 'enabled' => true]);
    $putRes = request('PUT', "/v1/admin/ai/domain-knowledge/{$createdId}", ['Content-Type' => 'application/json'], $putPayload);
    printf("PUT /v1/admin/ai/domain-knowledge/{$createdId} -> %d | body: %s\n", $putRes['status'], substr($putRes['body'], 0, 120));

    // 测试 DELETE 删除
    $delRes = request('DELETE', "/v1/admin/ai/domain-knowledge/{$createdId}");
    printf("DELETE /v1/admin/ai/domain-knowledge/{$createdId} -> %d | body: %s\n", $delRes['status'], substr($delRes['body'], 0, 120));
}

// 9. 测试配置更新 PUT /v1/admin/config
echo "\n=== 9. 配置修改实测 (LogAgent 工具调用轮次修改) ===\n";
$cfgRes = request('GET', '/v1/admin/config');
$cfgData = json_decode($cfgRes['body'], true);
$currentAi = $cfgData['data']['ai'] ?? [];
$currentRounds = $currentAi['agent']['maxToolRounds'] ?? 10;
echo "当前 agent.maxToolRounds: " . json_encode($currentRounds) . "\n";

// 尝试更新 maxToolRounds 为 25
$updatePayload = json_encode([
    'ai' => [
        'agent' => [
            'enabled' => true,
            'maxToolRounds' => 25,
            'maxFileLines' => 60000,
            'maxFileBytes' => 10485760,
        ]
    ]
]);
$updateRes = request('PUT', '/v1/admin/config', ['Content-Type' => 'application/json'], $updatePayload);
printf("PUT /v1/admin/config (with Content-Type) -> %d | body: %s\n", $updateRes['status'], substr($updateRes['body'], 0, 120));

// 再次读取检查
$cfgCheckRes = request('GET', '/v1/admin/config');
$cfgCheckData = json_decode($cfgCheckRes['body'], true);
$updatedRounds = $cfgCheckData['data']['ai']['agent']['maxToolRounds'] ?? null;
echo "更新后 agent.maxToolRounds: " . json_encode($updatedRounds) . "\n";

echo "\n=== 探查完成: 通过 {$passedCount} 项，失败 {$failedCount} 项 ===\n";
