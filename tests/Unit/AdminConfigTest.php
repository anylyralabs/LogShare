<?php

use App\Config;
use App\Controller\AdminController;
use Hyperf\HttpMessage\Server\Request;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $cfg = $this->origData;
    $cfg['admin'] = [
        'enabled' => true,
        'token' => 'test-admin-secret-token',
    ];
    $cfg['cache']['redis']['password'] = 'redis-secret-password';
    $cfg['storage']['mariadb']['password'] = 'mariadb-secret-password';
    $this->dataProp->setValue(null, $cfg);

    $dynamicPath = Config::getDynamicConfigPath();
    if (is_file($dynamicPath)) {
        @unlink($dynamicPath);
        clearstatcache(true, $dynamicPath);
    }
    $this->configRef->getProperty('dynamicMtime')->setValue(null, 0);
    $this->configRef->getProperty('dynamicSize')->setValue(null, -1);
    $this->configRef->getProperty('dynamicVersion')->setValue(null, '');
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    $dynamicPath = Config::getDynamicConfigPath();
    if (is_file($dynamicPath)) {
        @unlink($dynamicPath);
        clearstatcache(true, $dynamicPath);
    }
    $this->configRef->getProperty('dynamicMtime')->setValue(null, 0);
    $this->configRef->getProperty('dynamicSize')->setValue(null, -1);
    $this->configRef->getProperty('dynamicVersion')->setValue(null, '');
});

test('Config getMasked masks admin and connection credentials', function () {
    $masked = Config::getMasked();

    expect($masked['admin']['token'])->toBe('******');
    expect($masked['cache']['redis']['password'])->toBe('******');
    expect($masked['storage']['mariadb']['password'])->toBe('******');
});

test('Config saveDynamic updates non-sensitive settings and restores masked secrets', function () {
    $masked = Config::getMasked();

    Config::saveDynamic([
        'admin' => ['token' => $masked['admin']['token']],
        'cache' => ['redis' => ['password' => $masked['cache']['redis']['password']]],
        'storage' => [
            'storageTime' => 864000,
            'mariadb' => ['password' => $masked['storage']['mariadb']['password']],
        ],
    ]);

    $current = Config::all();
    expect($current['storage']['storageTime'])->toBe(864000);
    expect($current['admin']['token'])->toBe('test-admin-secret-token');
    expect($current['cache']['redis']['password'])->toBe('redis-secret-password');
    expect($current['storage']['mariadb']['password'])->toBe('mariadb-secret-password');
    expect(is_file(Config::getDynamicConfigPath()))->toBeTrue();
});

test('Config resetDynamic removes dynamic overrides', function () {
    Config::saveDynamic([
        'storage' => ['storageTime' => 999999],
    ]);
    expect(Config::Get('storage')['storageTime'])->toBe(999999);

    Config::resetDynamic();
    expect(is_file(Config::getDynamicConfigPath()))->toBeFalse();
});

test('Config automatically reloads on file mtime changes simulating cross-worker sync', function () {
    Config::saveDynamic([
        'storage' => ['storageTime' => 111111],
    ]);
    expect(Config::Get('storage')['storageTime'])->toBe(111111);

    $path = Config::getDynamicConfigPath();
    $raw = json_decode((string) file_get_contents($path), true);
    $raw['storage']['storageTime'] = 222222;
    file_put_contents($path, json_encode($raw));
    touch($path, time() + 5);

    expect(Config::Get('storage')['storageTime'])->toBe(222222);
});

test('Config ensureFresh prevents recursive loop and works safely with Redis client', function () {
    $redisMock = new \Tests\Mocks\RedisMock();
    \App\Client\RedisClient::setTestConnection($redisMock);

    try {
        Config::saveDynamic(['general' => ['name' => 'Sync Test V1']]);
        expect(Config::Get('general')['name'])->toBe('Sync Test V1');

        Config::ensureFresh();
        expect(Config::Get('general')['name'])->toBe('Sync Test V1');
    } finally {
        \App\Client\RedisClient::setTestConnection(null);
    }
});

test('AdminController config endpoints work properly', function () {
    $ref = new ReflectionClass(AdminController::class);
    $ctrl = $ref->newInstanceWithoutConstructor();
    $psr7Req = new Request('GET', '/v1/admin/config');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = $ref->getProperty('request');
    $reqRef->setValue($ctrl, new \Hyperf\HttpServer\Request());

    $res = $ctrl->getConfig();
    expect($res->getStatusCode())->toBe(200);
    $data = json_decode((string) $res->getBody(), true);
    expect($data['success'])->toBeTrue();
    expect($data['admin']['token'])->toBe('******');

    $putReq = (new Request('PUT', '/v1/admin/config'))
        ->withParsedBody([
            'storage' => ['storageTime' => 777777],
        ]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $putReq);

    $updateRes = $ctrl->updateConfig();
    expect($updateRes->getStatusCode())->toBe(200);
    expect(Config::Get('storage')['storageTime'])->toBe(777777);

    $resetReq = new Request('POST', '/v1/admin/config/reset');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $resetReq);
    $resetRes = $ctrl->resetConfig();
    expect($resetRes->getStatusCode())->toBe(200);
});
