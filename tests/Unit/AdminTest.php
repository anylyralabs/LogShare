<?php

use App\ApiError;
use App\Config;
use App\Controller\AdminController;
use App\Id;
use App\Middleware\AdminAuthMiddleware;
use App\Storage\FilesystemStorage;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use Psr\Http\Server\RequestHandlerInterface;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $this->tmpDir = CORE_PATH . '/tmp/logshare_admin_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);

    $cfg = $this->origData;
    $cfg['admin'] = [
        'enabled' => true,
        'token' => 'test-admin-secret-token',
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

test('AdminAuthMiddleware rejects when admin is disabled', function () {
    $cfg = $this->dataProp->getValue();
    $cfg['admin']['enabled'] = false;
    $this->dataProp->setValue(null, $cfg);

    $middleware = new AdminAuthMiddleware();
    $request = (new Request('GET', '/v1/admin/logs'))->withHeader('Authorization', 'Bearer test-admin-secret-token');
    $handler = Mockery::mock(RequestHandlerInterface::class);

    expect(fn() => $middleware->process($request, $handler))
        ->toThrow(ApiError::class);
});

test('AdminAuthMiddleware rejects invalid or missing token', function () {
    $middleware = new AdminAuthMiddleware();
    $handler = Mockery::mock(RequestHandlerInterface::class);

    // Missing token
    $requestWithoutToken = new Request('GET', '/v1/admin/logs');
    expect(fn() => $middleware->process($requestWithoutToken, $handler))
        ->toThrow(ApiError::class);

    // Wrong token
    $requestWrongToken = (new Request('GET', '/v1/admin/logs'))->withHeader('Authorization', 'Bearer wrong-token');
    expect(fn() => $middleware->process($requestWrongToken, $handler))
        ->toThrow(ApiError::class);
});

test('AdminAuthMiddleware accepts valid Bearer token and X-Admin-Token', function () {
    $middleware = new AdminAuthMiddleware();
    $handler = Mockery::mock(RequestHandlerInterface::class);
    $handler->shouldReceive('handle')->twice()->andReturn(new Response());

    // Bearer token
    $bearerReq = (new Request('GET', '/v1/admin/logs'))->withHeader('Authorization', 'Bearer test-admin-secret-token');
    $resp1 = $middleware->process($bearerReq, $handler);
    expect($resp1)->toBeInstanceOf(\Psr\Http\Message\ResponseInterface::class);

    // X-Admin-Token
    $headerReq = (new Request('GET', '/v1/admin/logs'))->withHeader('X-Admin-Token', 'test-admin-secret-token');
    $resp2 = $middleware->process($headerReq, $handler);
    expect($resp2)->toBeInstanceOf(\Psr\Http\Message\ResponseInterface::class);
});

test('AdminAuthMiddleware passes OPTIONS preflight requests', function () {
    $middleware = new AdminAuthMiddleware();
    $handler = Mockery::mock(RequestHandlerInterface::class);
    $handler->shouldReceive('handle')->once()->andReturn(new Response());

    $optionsReq = new Request('OPTIONS', '/v1/admin/logs');
    $resp = $middleware->process($optionsReq, $handler);
    expect($resp)->toBeInstanceOf(\Psr\Http\Message\ResponseInterface::class);
});

test('FilesystemStorage List and Count support pagination and search', function () {
    $id1 = FilesystemStorage::Put("Server log line 1\nServer log line 2", null, [], 'server-1');
    $id2 = FilesystemStorage::Put("Client crash log", null, [], 'client-1');

    expect($id1)->not->toBeNull();
    expect($id2)->not->toBeNull();

    $total = FilesystemStorage::Count();
    expect($total)->toBe(2);

    $items = FilesystemStorage::List(10, 0);
    expect($items)->toHaveCount(2);
    expect($items[0])->toHaveKeys(['id', 'size', 'source', 'created', 'filesCount']);

    // Keyword search
    $searchCount = FilesystemStorage::Count(null, null, null, $id1->get());
    expect($searchCount)->toBe(1);

    $searchResults = FilesystemStorage::List(10, 0, null, null, null, $id1->get());
    expect($searchResults)->toHaveCount(1);
    expect($searchResults[0]['id'])->toBe($id1->get());
});

test('AdminController listLogs returns paginated response', function () {
    FilesystemStorage::Put("Log content A", null, [], 'source-a');
    FilesystemStorage::Put("Log content B", null, [], 'source-b');

    $controller = new AdminController();
    $psr7Req = (new Request('GET', '/v1/admin/logs'))->withQueryParams(['page' => '1', 'limit' => '10']);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($controller, 'request');
    $reqRef->setValue($controller, new \Hyperf\HttpServer\Request());

    $resp = $controller->listLogs();
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string) $resp->getBody(), true);
    expect($body['success'])->toBeTrue();
    expect($body['total'])->toBe(2);
    expect($body['items'])->toHaveCount(2);
    expect($body['page'])->toBe(1);
});

test('AdminController getLog and deleteLogs work properly', function () {
    $id = FilesystemStorage::Put("Admin detailed log test", null, [], 'source-test');
    expect($id)->not->toBeNull();

    $controller = new AdminController();
    $psr7Req = new Request('GET', '/v1/admin/logs/' . $id->get());
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($controller, 'request');
    $reqRef->setValue($controller, new \Hyperf\HttpServer\Request());

    // Get log
    $resp = $controller->getLog($id->get());
    expect($resp->getStatusCode())->toBe(200);
    $data = json_decode((string) $resp->getBody(), true);
    expect($data['id'])->toBe($id->get());
    expect($data['content'])->toBe("Admin detailed log test");

    // Delete log
    $delResp = $controller->deleteLogs($id->get());
    expect($delResp->getStatusCode())->toBe(200);
    $delData = json_decode((string) $delResp->getBody(), true);
    expect($delData['deleted'])->toContain($id->get());

    // Verify gone
    expect(fn() => $controller->getLog($id->get()))->toThrow(ApiError::class);
});

test('AdminController system stats', function () {
    $controller = new AdminController();
    $psr7Req = new Request('GET', '/v1/admin/system/stats');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($controller, 'request');
    $reqRef->setValue($controller, new \Hyperf\HttpServer\Request());

    $statsResp = $controller->getSystemStats();
    expect($statsResp->getStatusCode())->toBe(200);
    $stats = json_decode((string) $statsResp->getBody(), true);
    expect($stats['version'])->toBe(\App\Version::VERSION);
    expect($stats['storageBackend'])->toBe('f');
});
