<?php

use App\ApiError;
use App\Config;
use App\System\AuditLogManager;
use App\System\SpinYarnManager;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $cfg = $this->origData;
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

    // 清理测试环境下的审计日志
    AuditLogManager::clearLogs();
});

afterEach(function () {
    AuditLogManager::clearLogs();
    $this->dataProp->setValue(null, $this->origData);
});

test('SpinYarnManager getStatus returns structured info', function () {
    $status = SpinYarnManager::getStatus();
    expect($status)->toBeArray();
    expect($status)->toHaveKeys(['extensionLoaded', 'mappingsDir', 'totalYarnCount', 'yarnMappings', 'totalVanillaCount', 'vanillaMappings']);
    expect(is_bool($status['extensionLoaded']))->toBeTrue();
    expect(is_int($status['totalYarnCount']))->toBeTrue();
    expect(is_array($status['yarnMappings']))->toBeTrue();
});

test('SpinYarnManager testDeobfuscate gracefully falls back when extension unavailable', function () {
    $res = SpinYarnManager::testDeobfuscate("java.lang.NullPointerException\n\tat net.minecraft.class_310.method_1508", '1.20.1', 'yarn');
    expect($res)->toBeArray();
    expect($res)->toHaveKey('original');
    expect($res['original'])->toContain('net.minecraft.class_310');
    if (!$res['available']) {
        expect($res['success'])->toBeFalse();
        expect($res['message'])->toContain('未加载');
    } else {
        expect($res['success'])->toBeTrue();
    }
});

test('AuditLogManager records and retrieves logs with filters and pagination', function () {
    AuditLogManager::record('log.delete', 's123456', ['reason' => 'user request'], true, 'admin', '192.168.1.10');
    AuditLogManager::record('security.ban', '1.2.3.4', ['duration' => 3600], true, 'admin', '192.168.1.10');
    AuditLogManager::record('config.update', 'storage.storageTime', ['old' => 604800, 'new' => 864000], true, 'admin', '192.168.1.10');

    $all = AuditLogManager::getLogs(1, 20);
    expect($all['total'])->toBeGreaterThanOrEqual(3);
    expect($all['items'])->toHaveCount(3);
    expect($all['items'][0]['action'])->toBe('config.update');

    // 按动作过滤
    $filtered = AuditLogManager::getLogs(1, 10, 'log.delete');
    expect($filtered['total'])->toBe(1);
    expect($filtered['items'][0]['target'])->toBe('s123456');

    // 关键词过滤
    $searched = AuditLogManager::getLogs(1, 10, null, '1.2.3.4');
    expect($searched['total'])->toBe(1);
    expect($searched['items'][0]['action'])->toBe('security.ban');

    // 清空
    $cleared = AuditLogManager::clearLogs();
    expect($cleared)->toBeGreaterThanOrEqual(3);
    $afterClear = AuditLogManager::getLogs(1, 10);
    expect($afterClear['total'])->toBe(0);
});
