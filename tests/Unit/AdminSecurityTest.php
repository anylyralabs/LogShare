<?php

use App\ApiError;
use App\Config;
use App\Controller\AdminController;
use App\Controller\LogController;
use App\System\SecurityService;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $this->tmpDir = CORE_PATH . '/tmp/logshare_sec_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);

    $cfg = $this->origData;
    $cfg['admin'] = [
        'enabled' => true,
        'token' => 'test-admin-secret-token-0123456789abcdef',
    ];
    $cfg['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $cfg['storage']['storageId'] = 'f';
    $cfg['storage']['storages']['f']['enabled'] = true;
    $cfg['eventQueue']['asyncSecurityAudit'] = false;
    $this->dataProp->setValue(null, $cfg);

    if (!\Hyperf\Context\ApplicationContext::hasContainer()) {
        $container = Mockery::mock(\Psr\Container\ContainerInterface::class);
        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('get')->andReturnUsing(function ($class) {
            if ($class === \Hyperf\HttpServer\Contract\RequestInterface::class) {
                return new \Hyperf\HttpServer\Request();
            }
            if ($class === \Hyperf\HttpServer\Response::class) {
                return new \Hyperf\HttpServer\Response();
            }
            return null;
        });
        \Hyperf\Context\ApplicationContext::setContainer($container);
    }
});

afterEach(function () {
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }
    $rulesFile = CORE_PATH . '/runtime/content_reject_rules.json';
    if (is_file($rulesFile)) {
        @unlink($rulesFile);
    }
    $bansFile = CORE_PATH . '/runtime/security_bans.json';
    if (is_file($bansFile)) {
        @unlink($bansFile);
    }
    // saveContentRules() 会双写动态配置，必须一并清理，避免污染后续本地运行
    $dynamicPath = Config::getDynamicConfigPath();
    if (is_file($dynamicPath)) {
        @unlink($dynamicPath);
    }
    $this->configRef->getProperty('dynamicMtime')->setValue(null, 0);
    $this->configRef->getProperty('dynamicSize')->setValue(null, -1);
    $this->configRef->getProperty('dynamicVersion')->setValue(null, '');
    $this->dataProp->setValue(null, $this->origData);
});

test('SecurityService ban, unban, and isIpBanned lifecycle', function () {
    $testIp = '198.51.100.42';

    // 初始未封禁
    expect(SecurityService::isIpBanned($testIp))->toBeFalse();

    // 封禁
    $banResult = SecurityService::banIp($testIp, 3600, 'Malicious crawling test');
    expect($banResult['ip'])->toBe($testIp);
    expect($banResult['reason'])->toBe('Malicious crawling test');

    // 封禁状态与列表检查
    expect(SecurityService::isIpBanned($testIp))->toBeTrue();
    $list = SecurityService::getBannedIps();
    $found = array_filter($list, fn($item) => $item['ip'] === $testIp);
    expect($found)->not->toBeEmpty();

    // 解封
    SecurityService::unbanIp($testIp);
    expect(SecurityService::isIpBanned($testIp))->toBeFalse();
});

test('SecurityService unban leaves the OpenLiteWaf snapshot file untouched', function () {
    // 封禁的权威状态是 nginx shared dict（跨 worker 共享），worker 0 每 60 秒用 dict 全量
    // 覆盖快照文件：应用层改文件既解不掉正在生效的封禁，也会被回写冲掉，历史上因此
    // 在生产完全失效（OpenLiteWaf/data 在应用容器里是只读挂载）。解封改走
    // POST /security/unban（Lua 侧 T28 覆盖），这里守住反面：绝不改写 WAF 数据文件。
    putenv('OPENLITEWAF_ADMIN_TOKEN');
    $snap = $this->tmpDir . '/waf-snapshot.json';
    $payload = json_encode(['bans' => [['slot' => 0, 'exp' => time() + 600, 'ip' => '203.0.113.7']]]);
    file_put_contents($snap, $payload);
    putenv('WAF_SNAPSHOT_PATH=' . $snap);

    expect(SecurityService::unbanIp('203.0.113.7'))->toBeTrue();

    expect(file_get_contents($snap))->toBe($payload, '解封不得改写 OpenLiteWaf 快照文件');
    putenv('WAF_SNAPSHOT_PATH');
});

test('SecurityService content rules validation and rejection', function () {
    SecurityService::saveContentRules([
        'enabled' => true,
        'keywords' => ['bad-malware-payload', 'cheat-distro-link'],
        'patterns' => ['/trojan_v\d+/i'],
    ]);

    $rules = SecurityService::getContentRules();
    expect($rules['enabled'])->toBeTrue();
    expect($rules['keywords'])->toContain('bad-malware-payload');

    // 正常文本不抛出
    expect(fn() => SecurityService::validateContent('Normal minecraft log'))->not->toThrow(ApiError::class);

    // 包含违规关键词抛出 ApiError
    expect(fn() => SecurityService::validateContent('Some log with bad-malware-payload included'))
        ->toThrow(ApiError::class);

    // 匹配正则抛出 ApiError
    expect(fn() => SecurityService::validateContent('Detected signature trojan_v2'))
        ->toThrow(ApiError::class);
});

test('AdminController security endpoints respond properly', function () {
    $controller = new AdminController();
    $psr7Req = new Request('GET', '/v1/admin/security/overview');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($controller, 'request');
    $reqRef->setValue($controller, new \Hyperf\HttpServer\Request());

    $overviewResp = $controller->getSecurityOverview();
    expect($overviewResp->getStatusCode())->toBe(200);
    $overviewBody = json_decode((string) $overviewResp->getBody(), true);
    expect($overviewBody['success'])->toBeTrue();
    expect($overviewBody)->toHaveKey('categories');

    // 封禁测试
    $banReq = (new Request('POST', '/v1/admin/security/ban'))->withParsedBody([
        'ip' => '203.0.113.88',
        'ttl' => 600,
        'reason' => 'Admin test ban',
    ]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $banReq);
    $banResp = $controller->banIp();
    expect($banResp->getStatusCode())->toBe(200);

    // 查列表
    $listReq = new Request('GET', '/v1/admin/security/bans');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $listReq);
    $listResp = $controller->getSecurityBans();
    $listBody = json_decode((string) $listResp->getBody(), true);
    expect($listBody['success'])->toBeTrue();
    expect($listBody['total'])->toBeGreaterThanOrEqual(1);

    // 解封
    putenv('OPENLITEWAF_ADMIN_TOKEN');
    $unbanReq = (new Request('POST', '/v1/admin/security/unban'))->withParsedBody(['ip' => '203.0.113.88']);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $unbanReq);
    $unbanResp = $controller->unbanIp();
    expect($unbanResp->getStatusCode())->toBe(200);
    // 边缘同步结果必须回传：未配置令牌时是 skipped（应用层已解封，WAF 仍会在 TTL 内 403），
    // 控制台据此区分提示，不能一律显示"已成功解封"
    $unbanBody = json_decode((string) $unbanResp->getBody(), true);
    expect($unbanBody['waf_sync'] ?? null)->toBe('skipped');
});

test('SecurityService resolveClientIp handles trusted reverse proxies and prevents spoofing', function () {
    // 1. 本地回环或 Docker 内网（受信任代理）转发真实客户端 IP
    $headers = ['x-real-ip' => ['203.0.113.50']];
    $serverParams = ['remote_addr' => '172.18.0.2']; // Docker bridge IP
    $ip = SecurityService::resolveClientIp($serverParams, $headers);
    expect($ip)->toBe('203.0.113.50');

    // 2. 127.0.0.1 回环受信任代理转发多级 X-Forwarded-For
    $headersXff = ['x-forwarded-for' => ['198.51.100.22, 10.0.0.1']];
    $serverParamsXff = ['remote_addr' => '127.0.0.1'];
    $ipXff = SecurityService::resolveClientIp($serverParamsXff, $headersXff);
    expect($ipXff)->toBe('198.51.100.22');

    // 3. 不受信任公网 IP（非代理）伪造 X-Real-IP 时，忽略伪造头部，使用直连 remote_addr
    $headersSpoof = ['x-real-ip' => ['1.1.1.1']];
    $serverParamsSpoof = ['remote_addr' => '198.51.100.99'];
    $ipSpoof = SecurityService::resolveClientIp($serverParamsSpoof, $headersSpoof);
    expect($ipSpoof)->toBe('198.51.100.99');
});

test('LogController and AnalyseController enforce IP ban and content rules', function () {
    $bannedIp = '203.0.113.77';
    SecurityService::banIp($bannedIp, 3600, 'Test ban');

    // 1. LogController 拦截封禁 IP
    $bannedStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $bannedStream->shouldReceive('getContents')->andReturn(json_encode(['content' => 'hello']));
    $bannedStream->shouldReceive('__toString')->andReturn(json_encode(['content' => 'hello']));

    $bannedReq = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $bannedReq->shouldReceive('getServerParams')->andReturn(['remote_addr' => '172.18.0.2']);
    $bannedReq->shouldReceive('getHeaders')->andReturn(['x-real-ip' => [$bannedIp]]);
    $bannedReq->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/json');
    $bannedReq->shouldReceive('getHeaderLine')->with('Content-Encoding')->andReturn('');
    $bannedReq->shouldReceive('getBody')->andReturn($bannedStream);
    $bannedReq->shouldReceive('getUploadedFiles')->andReturn([]);
    $bannedReq->shouldReceive('getParsedBody')->andReturn(['content' => 'hello']);

    $refLog = new ReflectionClass(LogController::class);
    $logController = $refLog->newInstanceWithoutConstructor();
    $refLog->getProperty('request')->setValue($logController, $bannedReq);

    try {
        $logController->create();
        test()->fail('Expected ApiError 403 was not thrown for banned IP in LogController');
    } catch (ApiError $e) {
        expect($e->getCode())->toBe(403);
        expect($e->getMessage())->toContain('blocked');
    }

    // 2. 解除封禁后，测试违规内容拦截
    SecurityService::unbanIp($bannedIp);
    SecurityService::saveContentRules([
        'enabled' => true,
        'keywords' => ['forbidden_secret_payload'],
        'patterns' => [],
    ]);

    $badContentStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $badContentPayload = json_encode(['content' => 'Notice: forbidden_secret_payload detected']);
    $badContentStream->shouldReceive('getContents')->andReturn($badContentPayload);
    $badContentStream->shouldReceive('__toString')->andReturn($badContentPayload);

    $badContentReq = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $badContentReq->shouldReceive('getServerParams')->andReturn(['remote_addr' => '127.0.0.1']);
    $badContentReq->shouldReceive('getHeaders')->andReturn([]);
    $badContentReq->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/json');
    $badContentReq->shouldReceive('getHeaderLine')->with('Content-Encoding')->andReturn('');
    $badContentReq->shouldReceive('getBody')->andReturn($badContentStream);
    $badContentReq->shouldReceive('getUploadedFiles')->andReturn([]);
    $badContentReq->shouldReceive('getParsedBody')->andReturn(['content' => 'Notice: forbidden_secret_payload detected']);

    $badContentCtrl = $refLog->newInstanceWithoutConstructor();
    $refLog->getProperty('request')->setValue($badContentCtrl, $badContentReq);

    try {
        $badContentCtrl->create();
        test()->fail('Expected ApiError 400 was not thrown for prohibited content in LogController');
    } catch (ApiError $e) {
        expect($e->getCode())->toBe(400);
        expect($e->getMessage())->toContain('prohibited keyword');
    }

    // 3. AnalyseController 违规内容拦截
    $refAnalyse = new ReflectionClass(\App\Controller\AnalyseController::class);
    $analyseCtrl = $refAnalyse->newInstanceWithoutConstructor();
    $refAnalyse->getProperty('request')->setValue($analyseCtrl, $badContentReq);

    try {
        $analyseCtrl->analyse();
        test()->fail('Expected ApiError 400 was not thrown in AnalyseController');
    } catch (ApiError $e) {
        expect($e->getCode())->toBe(400);
        expect($e->getMessage())->toContain('prohibited keyword');
    }

});
