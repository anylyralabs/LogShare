<?php

declare(strict_types=1);

/**
 * LogShare 端到端真实环境冒烟与全链路集成测试脚本。
 *
 * 用法：
 *   php scripts/ci_e2e_test.php --base-url=http://127.0.0.1:9501 [--waf-test]
 */

$options = getopt('', ['base-url:', 'waf-test::', 'large-file::']);
$baseUrl = rtrim(is_string($options['base-url'] ?? null) ? $options['base-url'] : 'http://127.0.0.1:9501', '/');
$testWaf = isset($options['waf-test']);
$testLargeFile = isset($options['large-file']);

echo "=== 开始 LogShare 真实环境端到端测试 ===\n";
echo "目标地址: {$baseUrl}\n";
echo "WAF/网关测试模式: " . ($testWaf ? "已启用" : "未启用") . "\n";
echo "大文件分块测试: " . ($testLargeFile ? "已启用" : "未启用") . "\n";

$testsRun = 0;
$testsPassed = 0;

/**
 * @param non-empty-string $method
 * @param non-empty-string $url
 * @param array<string, string> $headers
 * @return array{status: int, headers: string, body: string}
 */
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true);

    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "{$k}: {$v}";
    }
    if (!empty($formattedHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    if (!is_string($raw)) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL 请求失败: {$err} for {$method} {$url}");
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $headerStr = substr($raw, 0, $headerSize);
    $bodyStr = substr($raw, $headerSize);

    return [
        'status' => $statusCode,
        'headers' => $headerStr,
        'body' => $bodyStr,
    ];
}

function runCheck(string $name, callable $fn): void
{
    global $testsRun, $testsPassed;
    $testsRun++;
    echo "[TEST {$testsRun}] {$name} ... ";
    try {
        $fn();
        $testsPassed++;
        echo "PASS\n";
    } catch (Throwable $e) {
        echo "FAIL\n";
        echo "  错误: " . $e->getMessage() . "\n";
        echo "  位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
        exit(1);
    }
}

// ── 1. 公共接口与配置健康检查 ──

runCheck('GET /v1/limits 存储限制与大小配置', function () use ($baseUrl) {
    $res = httpRequest('GET', "{$baseUrl}/v1/limits");
    if ($res['status'] !== 200) {
        throw new RuntimeException("状态码非 200: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['storageTime'], $json['maxFiles'], $json['maxTotalBytes'])) {
        throw new RuntimeException("响应中缺少 storageTime、maxFiles 或 maxTotalBytes 字段: {$res['body']}");
    }
});

runCheck('GET / 根路径 API 目录检查', function () use ($baseUrl) {
    $res = httpRequest('GET', "{$baseUrl}/");
    if ($res['status'] !== 200) {
        throw new RuntimeException("状态码非 200: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['endpoints']) || !is_array($json['endpoints'])) {
        throw new RuntimeException("根路径缺少 endpoints 数组: {$res['body']}");
    }
});

// ── 2. 日志完整生命周期：写入、附件、读取、分析、元数据、删除、404 闭环 ──

$logId = null;
$logToken = null;

runCheck('POST /v1/log 创建日志并包含附件文件', function () use ($baseUrl, &$logId, &$logToken) {
    $payload = (string) json_encode([
        'source' => 'e2e-runner/1.0',
        'files' => [
            ['name' => 'latest.log', 'content' => "[12:00:00] [Server thread/INFO]: Minecraft server starting\n[12:00:01] [Server thread/ERROR]: Exception in thread 'main' java.lang.RuntimeException: Test crash\n"],
            ['name' => 'crash-report.txt', 'content' => "Time: 2026-09-20\nDescription: Crash during e2e test\n"],
            ['name' => 'server.properties', 'content' => "server-port=25565\nmotd=A Minecraft Server\n"],
        ],
    ]);

    $res = httpRequest('POST', "{$baseUrl}/v1/log", ['Content-Type' => 'application/json'], $payload);
    if ($res['status'] !== 200) {
        throw new RuntimeException("创建日志失败，状态码 {$res['status']}: {$res['body']}");
    }
    $json = json_decode($res['body'], true);
    if (empty($json['success']) || empty($json['id']) || empty($json['token'])) {
        throw new RuntimeException("返回数据不完整: {$res['body']}");
    }

    $logId = $json['id'];
    $logToken = $json['token'];
});

runCheck('GET /v1/log/{id} 获取日志内容与附件元数据', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/log/{$logId}");
    if ($res['status'] !== 200) {
        throw new RuntimeException("获取日志失败，状态码 {$res['status']}: {$res['body']}");
    }
    $json = json_decode($res['body'], true);
    if (empty($json['success']) || $json['id'] !== $logId) {
        throw new RuntimeException("返回的日志 ID 不匹配: {$res['body']}");
    }
    if (empty($json['lines']) || empty($json['size']) || empty($json['filesUrl'])) {
        throw new RuntimeException("元数据缺少 lines, size 或 filesUrl 字段: {$res['body']}");
    }
    if (!isset($json['files']) || count($json['files']) !== 3) {
        throw new RuntimeException("文件列表数量不符合预期");
    }
    if (empty($json['requiresFileSelection'])) {
        throw new RuntimeException("多文件日志应要求选择文件");
    }
});

runCheck('GET /v1/raw/{id} 多文件返回文件列表', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/raw/{$logId}");
    if ($res['status'] !== 200) {
        throw new RuntimeException("获取文件列表失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json) || ($json['fileCount'] ?? 0) !== 3) {
        throw new RuntimeException("文件列表不符合预期: {$res['body']}");
    }
});

runCheck('GET /v1/raw/{id}/{filename} 获取指定文件内容', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/raw/{$logId}/latest.log");
    if ($res['status'] !== 200) {
        throw new RuntimeException("获取附加文件失败: {$res['status']}");
    }
    if (!str_contains($res['body'], 'Minecraft server starting')) {
        throw new RuntimeException("文件内容不匹配: {$res['body']}");
    }
});

runCheck('GET /v1/insights/{id} 多文件返回文件列表', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/insights/{$logId}");
    if ($res['status'] !== 200) {
        throw new RuntimeException("获取可分析文件列表失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json) || ($json['fileCount'] ?? 0) !== 3) {
        throw new RuntimeException("可分析文件列表不符合预期: {$res['body']}");
    }
});

runCheck('GET /v1/insights/{id}/{filename} Codex 日志特征解析', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/insights/{$logId}/latest.log");
    if ($res['status'] !== 200) {
        throw new RuntimeException("Codex insights 解析失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException("insights 响应非 JSON 结构: {$res['body']}");
    }
});

runCheck('DELETE /v1/log/{id} 鉴权拦截与有效 Token 成功删除', function () use ($baseUrl, &$logId, &$logToken) {
    // 1. 无 Token
    $unauthRes = httpRequest('DELETE', "{$baseUrl}/v1/log/{$logId}");
    if ($unauthRes['status'] !== 401) {
        throw new RuntimeException("无 Token 删除应被 401 拦截，实际状态: {$unauthRes['status']}");
    }

    // 2. 错误 Token
    $badTokenRes = httpRequest('DELETE', "{$baseUrl}/v1/log/{$logId}", ['Authorization' => 'Bearer bad-token']);
    if ($badTokenRes['status'] !== 400 && $badTokenRes['status'] !== 403) {
        throw new RuntimeException("错误 Token 应被拒绝，实际状态: {$badTokenRes['status']}");
    }

    // 3. 正确 Token 成功删除
    $goodRes = httpRequest('DELETE', "{$baseUrl}/v1/log/{$logId}", ['Authorization' => "Bearer {$logToken}"]);
    if ($goodRes['status'] !== 200) {
        throw new RuntimeException("正确 Token 删除失败，状态: {$goodRes['status']}: {$goodRes['body']}");
    }

    // 4. 重复删除返回 400/404
    $againRes = httpRequest('DELETE', "{$baseUrl}/v1/log/{$logId}", ['Authorization' => "Bearer {$logToken}"]);
    if ($againRes['status'] !== 400 && $againRes['status'] !== 404) {
        throw new RuntimeException("重复删除应失败，实际状态: {$againRes['status']}");
    }

    // 5. 再次查询返回 404
    $getAfterDel = httpRequest('GET', "{$baseUrl}/v1/log/{$logId}");
    if ($getAfterDel['status'] !== 404) {
        throw new RuntimeException("删除后获取日志应返回 404，实际: {$getAfterDel['status']}");
    }
});

// ── 4. 大文件分块与 IPC 传输（若开启 --large-file） ──

if ($testLargeFile) {
    $largeId = null;
    $largeToken = null;
    $largeMarker = null;

    runCheck('POST /v1/log 上传 9 MiB 日志并分块写入', function () use ($baseUrl, &$largeId, &$largeToken, &$largeMarker) {
        $largeMarker = 'LARGE-' . bin2hex(random_bytes(8));
        $content = $largeMarker . "\n" . str_repeat('A', 9 * 1024 * 1024);
        $payload = (string) json_encode([
            'source' => 'ci-large-file/1.0',
            'content' => $content,
        ]);

        $res = httpRequest('POST', "{$baseUrl}/v1/log", ['Content-Type' => 'application/json'], $payload);
        if ($res['status'] !== 200) {
            throw new RuntimeException("大文件上传失败，状态码 {$res['status']}: " . substr($res['body'], 0, 300));
        }

        $json = json_decode($res['body'], true);
        if (empty($json['id']) || empty($json['token']) || ($json['files'][0]['name'] ?? '') !== 'latest.log') {
            throw new RuntimeException("大文件上传响应不完整: " . substr($res['body'], 0, 300));
        }

        $largeId = $json['id'];
        $largeToken = $json['token'];
    });

    runCheck('GET /v1/raw/{id}/latest.log 大文件内容完整返回', function () use ($baseUrl, &$largeId, &$largeMarker) {
        $res = httpRequest('GET', "{$baseUrl}/v1/raw/{$largeId}/latest.log");
        if ($res['status'] !== 200) {
            throw new RuntimeException("大文件读取失败，状态码 {$res['status']}");
        }
        $expectedLength = strlen((string) $largeMarker) + 1 + 9 * 1024 * 1024;
        if (strlen($res['body']) !== $expectedLength) {
            throw new RuntimeException('大文件内容长度不匹配: ' . strlen($res['body']) . " != {$expectedLength}");
        }
        if (!str_starts_with($res['body'], (string) $largeMarker)) {
            throw new RuntimeException('大文件内容前缀不匹配');
        }
    });

    runCheck('DELETE /v1/log/{id} 清理大文件测试数据', function () use ($baseUrl, &$largeId, &$largeToken) {
        $res = httpRequest('DELETE', "{$baseUrl}/v1/log/{$largeId}", ['Authorization' => "Bearer {$largeToken}"]);
        if ($res['status'] !== 200) {
            throw new RuntimeException("大文件删除失败，状态码 {$res['status']}: {$res['body']}");
        }
    });
}

// ── 4. WAF 与边缘安全网关测试（若开启 --waf-test） ──

if ($testWaf) {
    runCheck('WAF 统计端点 GET /security/stats 正常响应', function () use ($baseUrl) {
        $res = httpRequest('GET', "{$baseUrl}/security/stats");
        if ($res['status'] !== 200) {
            throw new RuntimeException("安全统计页不可达: {$res['status']}");
        }
        $json = json_decode($res['body'], true);
        $hasBlocked = isset($json['blocked_total']) || isset($json['attacks_blocked']);
        $hasBanned = isset($json['banned_active']) || isset($json['banned_ips_count']);
        if (!$hasBlocked || !$hasBanned) {
            throw new RuntimeException("安全统计数据缺少必要字段: {$res['body']}");
        }
    });

    runCheck('站点统计端点 GET /stats/data 正常响应', function () use ($baseUrl) {
        $res = httpRequest('GET', "{$baseUrl}/stats/data");
        if ($res['status'] !== 200) {
            throw new RuntimeException("站点统计数据不可达: {$res['status']}");
        }
        $json = json_decode($res['body'], true);
        if (!isset($json['today'])) {
            throw new RuntimeException("站点统计缺少 today 统计对象: {$res['body']}");
        }
    });

    runCheck('OpenLiteWaf 白名单豁免验证 (带报错异常的日志 POST 放行)', function () use ($baseUrl) {
        $payload = (string) json_encode([
            'files' => [
                ['name' => 'latest.log', 'content' => "[12:00:00] [ERROR]: org.sqlite.SQLiteException: [SQLITE_ERROR] syntax error near 'SELECT * FROM'\n"],
            ],
        ]);
        $res = httpRequest('POST', "{$baseUrl}/v1/log", ['Content-Type' => 'application/json'], $payload);
        if ($res['status'] !== 200) {
            throw new RuntimeException("OpenLiteWaf 误拦截正常日志上传，状态: {$res['status']}: {$res['body']}");
        }
    });

    runCheck('OpenLiteWaf 常见静态路径不误判探测（/sitemap.xml 与 /robots.txt）', function () use ($baseUrl) {
        foreach (['/sitemap.xml', '/robots.txt', '/favicon.ico'] as $path) {
            $res = httpRequest('GET', "{$baseUrl}{$path}");
            if ($res['status'] === 403) {
                throw new RuntimeException("OpenLiteWaf 将 {$path} 判为探测（403），扩展名规则应收窄到请求路径");
            }
        }
    });

    runCheck('OpenLiteWaf 真实恶意攻击拦截 (SQL 注入 / 路径遍历)', function () use ($baseUrl) {
        // 尝试发送典型路径遍历请求
        $attackRes = httpRequest('GET', "{$baseUrl}/v1/log?id=../../../../etc/passwd");
        if ($attackRes['status'] !== 403) {
            throw new RuntimeException("OpenLiteWaf 未拦截路径遍历攻击，状态: {$attackRes['status']}");
        }

        // 尝试发送典型 SQL 注入请求
        $sqliRes = httpRequest('GET', "{$baseUrl}/v1/limits?id=1%20UNION%20SELECT%201,version()");
        if ($sqliRes['status'] !== 403) {
            throw new RuntimeException("OpenLiteWaf 未拦截 SQL 注入请求，状态: {$sqliRes['status']}");
        }
    });

    runCheck('OpenLiteWaf 敏感文件与扫描器 UA 仍判探测', function () use ($baseUrl) {
        foreach (['/.env', '/db.backup.sql', '/latest.log', '/web.config', '/cgi-bin/test.cgi'] as $path) {
            $res = httpRequest('GET', "{$baseUrl}{$path}");
            if ($res['status'] !== 403) {
                throw new RuntimeException("OpenLiteWaf 未拦截敏感文件探测 {$path}，状态: {$res['status']}");
            }
        }
        foreach (['sqlmap/1.7.11#stable', 'gobuster/3.6', 'Nikto/2.5.0'] as $ua) {
            $res = httpRequest('GET', "{$baseUrl}/v1/limits", ['User-Agent' => $ua]);
            if ($res['status'] !== 403) {
                throw new RuntimeException("OpenLiteWaf 未拦截扫描器 UA {$ua}，状态: {$res['status']}");
            }
        }
    });

    // 顺序纪律：以下用例会累积 strike 计数并封禁 CI 出口 IP，必须排在全部正向断言之后
    runCheck('OpenLiteWaf 特征命中累计封禁与运行时解封', function () use ($baseUrl) {
        for ($i = 0; $i < 3; $i++) {
            httpRequest('GET', "{$baseUrl}/v1/limits?id=1%20UNION%20SELECT%201,version()");
        }
        $banned = httpRequest('GET', "{$baseUrl}/v1/limits");
        if ($banned['status'] !== 403) {
            throw new RuntimeException("累计三次特征命中后未封禁出口 IP，状态: {$banned['status']}");
        }

        $token = trim((string) (getenv('OPENLITEWAF_ADMIN_TOKEN') ?: ''));
        if ($token === '') {
            fwrite(STDOUT, "  · 未配置 OPENLITEWAF_ADMIN_TOKEN，跳过解封自检（封禁等 TTL 自然到期）\n");
            return;
        }
        $bans = httpRequest('GET', "{$baseUrl}/security/bans", ['X-OpenLiteWaf-Token' => $token]);
        if ($bans['status'] === 404) {
            throw new RuntimeException('运维端点返回 404：nginx 未把 OPENLITEWAF_ADMIN_TOKEN 透传给 Lua（检查 env 指令与 compose 注入）');
        }
        $list = json_decode($bans['body'], true);
        $victims = [];
        foreach ((array) ($list['bans'] ?? []) as $entry) {
            if (!empty($entry['ip'])) {
                $victims[] = (string) $entry['ip'];
            }
        }
        if ($victims === []) {
            throw new RuntimeException('/security/bans 未列出刚产生的封禁，封禁原因或槽位写入有问题');
        }
        foreach ($victims as $ip) {
            $res = httpRequest('POST', "{$baseUrl}/security/unban?ip=" . rawurlencode($ip), [
                'X-OpenLiteWaf-Token' => $token,
            ]);
            if (!str_contains($res['body'], '"ok":true')) {
                throw new RuntimeException("解封 {$ip} 失败: {$res['status']} {$res['body']}");
            }
        }
        $after = httpRequest('GET', "{$baseUrl}/v1/limits");
        if ($after['status'] === 403) {
            throw new RuntimeException('解封后出口 IP 仍被拦：shared dict 封禁键或槽位未清干净');
        }
    });
}

echo "=== 全部 {$testsPassed}/{$testsRun} 项端到端测试均成功通过 ===\n";
