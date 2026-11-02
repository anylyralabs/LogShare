<?php

declare(strict_types=1);

/**
 * LogShare 上传/读取并发基准。
 *
 * 用法：
 *   php scripts/bench_api.php --base-url=https://api.logshare.cn \
 *       --concurrency=16 --iterations=5 --size=1048576
 *
 * 每个请求使用唯一 marker 和 source，避免命中上传去重。默认会删除创建的
 * 日志；加 --keep 可保留现场。
 */

$options = getopt('', ['base-url:', 'concurrency::', 'iterations::', 'size::', 'keep::']);
$baseUrl = rtrim(is_string($options['base-url'] ?? null) ? $options['base-url'] : 'http://127.0.0.1:9501', '/');
$concurrency = max(1, (int) ($options['concurrency'] ?? 16));
$iterations = max(1, (int) ($options['iterations'] ?? 5));
$size = max(1, (int) ($options['size'] ?? 1024 * 1024));
$keep = isset($options['keep']);

/**
 * @param list<float> $values
 */
function percentile(array $values, float $percentile): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values);
    $index = (int) ceil($percentile / 100 * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));

    return $values[$index];
}

/**
 * curl 的 CURLOPT_POSTFIELDS 不接受空字符串；这里集中校验并保持 PHPStan 能
 * 推断出 non-empty-string。
 */
function setPostBody(\CurlHandle $ch, string $body): void
{
    if ($body === '') {
        throw new InvalidArgumentException('POST body must not be empty');
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

/**
 * @param list<array{url: string, method?: string, body: string, headers?: list<string>}> $requests
 * @return list<array{status: int, body: string, latency_ms: float}>
 */
function runConcurrent(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];
    $started = [];

    foreach ($requests as $index => $request) {
        $ch = curl_init($request['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        if (($request['method'] ?? 'GET') === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            setPostBody($ch, $request['body']);
        }
        if (isset($request['method']) && $request['method'] !== ''
            && $request['method'] !== 'GET' && $request['method'] !== 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request['method']);
        }
        if (isset($request['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $request['headers']);
        }
        curl_multi_add_handle($multi, $ch);
        $handles[$index] = $ch;
        $started[$index] = hrtime(true);
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $index => $ch) {
        $body = (string) curl_multi_getcontent($ch);
        $results[] = [
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => $body,
            'latency_ms' => (float) ((hrtime(true) - $started[$index]) / 1_000_000),
        ];
        curl_multi_remove_handle($multi, $ch);
        unset($ch);
    }

    curl_multi_close($multi);

    return $results;
}

$total = $concurrency * $iterations;
fwrite(STDOUT, sprintf(
    "LogShare benchmark: base=%s concurrency=%d iterations=%d size=%d bytes total=%d\n",
    $baseUrl,
    $concurrency,
    $iterations,
    $size,
    $total
));

$created = [];
$uploadLatencies = [];
$rawLatencies = [];
$wallStart = hrtime(true);

for ($batch = 0; $batch < $iterations; $batch++) {
    $requests = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $marker = 'bench-' . bin2hex(random_bytes(8));
        $content = $marker . "\n" . str_repeat('A', $size);
        $encoded = json_encode([
            'source' => 'bench/' . $marker,
            'content' => $content,
        ], JSON_THROW_ON_ERROR);
        $requests[] = [
            'url' => $baseUrl . '/v1/log',
            'method' => 'POST',
            'body' => $encoded,
            'headers' => ['Content-Type: application/json'],
        ];
    }

    $responses = runConcurrent($requests);
    foreach ($responses as $response) {
        $uploadLatencies[] = $response['latency_ms'];
        if ($response['status'] !== 200) {
            fwrite(STDERR, "upload failed: status={$response['status']} body=" . substr($response['body'], 0, 200) . "\n");
            exit(1);
        }
        $json = json_decode($response['body'], true);
        if (!is_array($json) || empty($json['id']) || empty($json['token'])) {
            fwrite(STDERR, "upload response missing id/token: " . substr($response['body'], 0, 200) . "\n");
            exit(1);
        }
        $created[] = ['id' => (string) $json['id'], 'token' => (string) $json['token']];
    }

    $readRequests = [];
    foreach (array_slice($created, -$concurrency) as $entry) {
        $readRequests[] = [
            'url' => $baseUrl . '/v1/raw/' . $entry['id'] . '/latest.log',
            'body' => '{}',
        ];
    }
    foreach (runConcurrent($readRequests) as $response) {
        $rawLatencies[] = $response['latency_ms'];
        if ($response['status'] !== 200) {
            fwrite(STDERR, "raw failed: status={$response['status']}\n");
            exit(1);
        }
    }
}

$wallMs = (hrtime(true) - $wallStart) / 1_000_000;

if (!$keep) {
    foreach ($created as $entry) {
        $ch = curl_init($baseUrl . '/v1/log/' . $entry['id']);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $entry['token']],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FORBID_REUSE => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200) {
            fwrite(STDERR, "delete failed: id={$entry['id']} status={$status} body=" . substr((string) $body, 0, 200) . "\n");
            exit(1);
        }
    }
}

$average = static fn (array $values): float => $values === [] ? 0.0 : array_sum($values) / count($values);
fwrite(STDOUT, sprintf(
    "upload: n=%d avg=%.1fms p50=%.1fms p95=%.1fms max=%.1fms\n",
    count($uploadLatencies),
    $average($uploadLatencies),
    percentile($uploadLatencies, 50),
    percentile($uploadLatencies, 95),
    max($uploadLatencies ?: [0.0])
));
fwrite(STDOUT, sprintf(
    "raw:    n=%d avg=%.1fms p50=%.1fms p95=%.1fms max=%.1fms\n",
    count($rawLatencies),
    $average($rawLatencies),
    percentile($rawLatencies, 50),
    percentile($rawLatencies, 95),
    max($rawLatencies ?: [0.0])
));
fwrite(STDOUT, sprintf(
    "wall=%.2fs throughput=%.2f req/s\n",
    $wallMs / 1000,
    $total / ($wallMs / 1000)
));
