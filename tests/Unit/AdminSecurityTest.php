<?php

use App\ApiError;
use App\Config;
use App\Controller\AnalyseController;
use App\Controller\LogController;
use App\System\SecurityService;
use Tests\Mocks\RedisMock;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $cfg = $this->origData;
    $cfg['security']['enabled'] = true;
    $cfg['security']['contentRules'] = [
        'enabled' => true,
        'keywords' => [],
        'patterns' => [],
    ];
    $cfg['security']['ipBans'] = [];
    $cfg['eventQueue']['asyncSecurityAudit'] = false;
    $this->dataProp->setValue(null, $cfg);

    RedisMock::reset();
    \App\Client\RedisClient::setTestConnection(new RedisMock());

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
    \App\Client\RedisClient::setTestConnection(null);
    RedisMock::reset();

    foreach ([
        CORE_PATH . '/runtime/content_reject_rules.json',
        CORE_PATH . '/runtime/security_bans.json',
        Config::getDynamicConfigPath(),
    ] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    $this->configRef->getProperty('dynamicMtime')->setValue(null, 0);
    $this->configRef->getProperty('dynamicSize')->setValue(null, -1);
    $this->configRef->getProperty('dynamicVersion')->setValue(null, '');
    $this->dataProp->setValue(null, $this->origData);
});

test('SecurityService bans an IP and reports the ban state', function () {
    $testIp = '198.51.100.42';

    expect(SecurityService::isIpBanned($testIp))->toBeFalse();

    $banResult = SecurityService::banIp($testIp, 3600, 'Malicious crawling test');
    expect($banResult['ip'])->toBe($testIp);
    expect($banResult['reason'])->toBe('Malicious crawling test');
    expect(SecurityService::isIpBanned($testIp))->toBeTrue();
});

test('SecurityService validates configured content rules', function () {
    $cfg = $this->dataProp->getValue();
    $cfg['security']['contentRules'] = [
        'enabled' => true,
        'keywords' => ['bad-malware-payload', 'cheat-distro-link'],
        'patterns' => ['/trojan_v\d+/i'],
    ];
    $this->dataProp->setValue(null, $cfg);

    $rules = SecurityService::getContentRules();
    expect($rules['enabled'])->toBeTrue();
    expect($rules['keywords'])->toContain('bad-malware-payload');

    expect(fn() => SecurityService::validateContent('Normal minecraft log'))->not->toThrow(ApiError::class);
    expect(fn() => SecurityService::validateContent('Some log with bad-malware-payload included'))
        ->toThrow(ApiError::class);
    expect(fn() => SecurityService::validateContent('Detected signature trojan_v2'))
        ->toThrow(ApiError::class);
});

test('SecurityService resolveClientIp handles trusted reverse proxies and prevents spoofing', function () {
    $headers = ['x-real-ip' => ['203.0.113.50']];
    $serverParams = ['remote_addr' => '172.18.0.2'];
    expect(SecurityService::resolveClientIp($serverParams, $headers))->toBe('203.0.113.50');

    $headersXff = ['x-forwarded-for' => ['198.51.100.22, 10.0.0.1']];
    $serverParamsXff = ['remote_addr' => '127.0.0.1'];
    expect(SecurityService::resolveClientIp($serverParamsXff, $headersXff))->toBe('198.51.100.22');

    $headersSpoof = ['x-real-ip' => ['1.1.1.1']];
    $serverParamsSpoof = ['remote_addr' => '198.51.100.99'];
    expect(SecurityService::resolveClientIp($serverParamsSpoof, $headersSpoof))->toBe('198.51.100.99');
});

test('LogController and AnalyseController enforce IP ban and content rules', function () {
    $bannedIp = '203.0.113.77';
    SecurityService::banIp($bannedIp, 3600, 'Test ban');

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

    $cfg = $this->dataProp->getValue();
    $cfg['security']['contentRules'] = [
        'enabled' => true,
        'keywords' => ['forbidden_secret_payload'],
        'patterns' => [],
    ];
    $this->dataProp->setValue(null, $cfg);

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

    $refAnalyse = new ReflectionClass(AnalyseController::class);
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
