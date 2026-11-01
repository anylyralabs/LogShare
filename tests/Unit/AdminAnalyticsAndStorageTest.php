<?php

use App\ApiError;
use App\Config;
use App\Controller\AdminController;
use App\Data\MetadataEntry;
use App\Data\Token;
use App\Log;
use App\Storage\FilesystemStorage;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $this->tmpDir = CORE_PATH . '/tmp/logshare_analytics_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);

    $cfg = $this->origData;
    $cfg['admin'] = [
        'enabled' => true,
        'token' => 'test-admin-secret-token-0123456789abcdef',
    ];
    $cfg['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $cfg['storage']['storageId'] = 'f';
    $cfg['storage']['storages']['f']['enabled'] = true;
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
    $this->dataProp->setValue(null, $this->origData);
});

test('Analytics sources, versions and trends return valid response structure', function () {
    // 写入模拟日志
    $meta = [
        MetadataEntry::fromArray(['key' => 'version', 'value' => '1.20.1']),
        MetadataEntry::fromArray(['key' => 'type', 'value' => 'Fabric']),
    ];
    $log = new Log();
    $id = $log->put('Error at MinecraftServer.main()', new Token(), $meta, 'pojav/3.4.0');
    expect($id)->not->toBeNull();

    $controller = new AdminController();
    $psr7Req = (new Request('GET', '/v1/admin/analytics/sources'))->withQueryParams(['days' => '7']);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($controller, 'request');
    $reqRef->setValue($controller, new \Hyperf\HttpServer\Request());

    $sourceResp = $controller->getAnalyticsSources();
    expect($sourceResp->getStatusCode())->toBe(200);
    $body = json_decode((string) $sourceResp->getBody(), true);
    expect($body['success'])->toBeTrue();
    expect($body['total'])->toBeGreaterThanOrEqual(1);
    expect($body['sources'])->toBeArray();

    $trendsResp = $controller->getAnalyticsTrends();
    expect($trendsResp->getStatusCode())->toBe(200);
    $trendsBody = json_decode((string) $trendsResp->getBody(), true);
    expect($trendsBody['success'])->toBeTrue();
    expect($trendsBody['trends'])->toBeArray();

    $versionResp = $controller->getAnalyticsVersions();
    expect($versionResp->getStatusCode())->toBe(200);
    $versionBody = json_decode((string) $versionResp->getBody(), true);
    expect($versionBody['success'])->toBeTrue();
    expect($versionBody['versions'])->toBeArray();
});

test('Storage health diagnostics and cache flush return valid payload', function () {
    $controller = new AdminController();
    $psr7Req = new Request('GET', '/v1/admin/system/storage-health');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($controller, 'request');
    $reqRef->setValue($controller, new \Hyperf\HttpServer\Request());

    $healthResp = $controller->getStorageHealth();
    expect($healthResp->getStatusCode())->toBe(200);
    $healthBody = json_decode((string) $healthResp->getBody(), true);
    expect($healthBody['success'])->toBeTrue();
    expect($healthBody['storageBackend'])->toBe('f');
    expect($healthBody['filesystem'])->toBeArray();

    $cleanResp = $controller->cleanupExpiredLogs();
    expect($cleanResp->getStatusCode())->toBe(200);
    $cleanBody = json_decode((string) $cleanResp->getBody(), true);
    expect($cleanBody['success'])->toBeTrue();
    expect($cleanBody['deletedCount'])->toBeInt();

    $flushResp = $controller->flushCache();
    expect($flushResp->getStatusCode())->toBe(200);
    $flushBody = json_decode((string) $flushResp->getBody(), true);
    expect($flushBody['success'])->toBeTrue();
    expect($flushBody)->toHaveKey('deletedKeys');
});

test('batchDeleteLogs requires filter condition and deletes matching logs', function () {
    $controller = new AdminController();
    $psr7ReqEmpty = (new Request('POST', '/v1/admin/logs/batch-delete'))->withParsedBody([]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7ReqEmpty);
    $reqRef = new ReflectionProperty($controller, 'request');
    $reqRef->setValue($controller, new \Hyperf\HttpServer\Request());

    // 1. 无有效过滤条件直接抛 ApiError
    expect(fn() => $controller->batchDeleteLogs())->toThrow(ApiError::class);

    // 2. 写入日志准备删除
    $log1 = new Log();
    $id1 = $log1->put('spam log content 1', new Token(), [], 'spam-client/1.0');
    $log2 = new Log();
    $id2 = $log2->put('normal log content 2', new Token(), [], 'pojav/3.4.0');

    // 注入有过滤条件的 request parsed body
    $psrReq = (new Request('POST', '/v1/admin/logs/batch-delete'))
        ->withParsedBody(['source' => 'spam-client/1.0']);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psrReq);

    $deleteResp = $controller->batchDeleteLogs();
    expect($deleteResp->getStatusCode())->toBe(200);
    $deleteBody = json_decode((string) $deleteResp->getBody(), true);

    expect($deleteBody['success'])->toBeTrue();
    expect($deleteBody['deletedCount'])->toBe(1);

    // 验证 log1 已删，log2 还在
    $check1 = new Log($id1);
    expect($check1->exists())->toBeFalse();
    $check2 = new Log($id2);
    expect($check2->exists())->toBeTrue();
});

test('AnalyticsService MariaDb backend and heuristic version parsing', function () {
    try {
        \Hyperf\DbConnection\Db::statement('SELECT 1');
    } catch (\Throwable) {
        test()->markTestSkipped('MariaDB unavailable');
    }

    $cfg = $this->dataProp->getValue();
    $cfg['storage']['storageId'] = 's';
    $cfg['storage']['storages']['s']['enabled'] = true;
    $this->dataProp->setValue(null, $cfg);

    try {
        $prefix = 'analytics_' . uniqid();
        $id1 = \App\Storage\MariaDbStorage::Put(
            "[12:00:00] [main/INFO] [NeoForge]: Minecraft 1.20.4 loading...\n",
            new Token(),
            [\App\Data\MetadataEntry::fromArray(['key' => 'version', 'value' => '1.20.4'])],
            "{$prefix}_launcher"
        );
        $id2 = \App\Storage\MariaDbStorage::Put(
            "[12:00:00] [main/INFO] [Fabric]: Loading 5 mods: fabricloader 0.15.0\n",
            new Token(),
            [],
            "{$prefix}_launcher"
        );

        $sources = \App\System\AnalyticsService::getSourceStats(7);
        expect($sources['days'])->toBe(7);
        expect($sources['total'])->toBeGreaterThanOrEqual(2);
        expect($sources['sources'])->toBeArray();

        $trends = \App\System\AnalyticsService::getTrends(7);
        expect($trends['days'])->toBe(7);
        expect($trends['total_logs'])->toBeGreaterThanOrEqual(2);
        expect($trends['trends'])->toBeArray();

        $versions = \App\System\AnalyticsService::getVersionStats(30);
        expect($versions['days'])->toBe(30);
        expect($versions['versions'])->toBeArray();
        expect($versions['loaders'])->toBeArray();

        $storageHealth = \App\System\StorageHealthService::getHealth();
        expect($storageHealth['storageBackend'])->toBe('s');
        expect($storageHealth['database'])->toBeArray();
    } finally {
        if (isset($id1)) \App\Storage\MariaDbStorage::Delete($id1);
        if (isset($id2)) \App\Storage\MariaDbStorage::Delete($id2);
        $this->dataProp->setValue(null, $this->origData);
    }
});

