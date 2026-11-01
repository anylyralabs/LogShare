<?php

declare(strict_types=1);

require 'core.php';

$baseUrl = 'https://127.0.0.1';
$host = getenv('API_HOST') ?: 'api.logshare.cn';

/**
 * @param non-empty-string $method
 * @param array<string|int, string> $headers
 * @return array{status: int, body: string, error: string}
 */
function request(string $method, string $path, array $headers = [], ?string $body = null): array
{
    global $baseUrl, $host;
    $ch = curl_init("{$baseUrl}{$path}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $defaultHeaders = [
        "Host: {$host}",
    ];
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
