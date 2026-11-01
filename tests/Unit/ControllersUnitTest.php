<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiError;
use App\Controller\FiltersController;
use App\Controller\IndexController;
use App\Controller\InsightsController;
use App\Controller\LimitsController;
use App\Controller\LogMetaController;
use App\Controller\RateErrorController;
use App\Controller\RawController;
use App\Data\Token;
use App\Log;
use Hyperf\HttpServer\Contract\RequestInterface;
use Mockery;
use ReflectionClass;

function instantiateController(string $class, ?RequestInterface $request = null): object
{
    $ref = new ReflectionClass($class);
    $instance = $ref->newInstanceWithoutConstructor();

    if ($request === null) {
        $request = Mockery::mock(RequestInterface::class);
    }
    if ($request instanceof \Mockery\MockInterface) {
        $request->shouldReceive('getServerParams')->byDefault()->andReturn(['remote_addr' => '127.0.0.1']);
        $request->shouldReceive('getHeaders')->byDefault()->andReturn([]);
    }

    $reqProp = $ref->getProperty('request');
    $reqProp->setValue($instance, $request);

    return $instance;
}

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $data = $this->origData;
    $data['storage']['storages']['f'] = [
        'name' => 'Filesystem',
        'class' => '\\App\\Storage\\FilesystemStorage',
        'enabled' => true,
    ];
    $data['storage']['storageId'] = 'f';
    $data['cache']['enabled'] = false;

    $this->tmpDir = CORE_PATH . '/tmp/logshare_ctrl_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }
});

test('LimitsController returns storage limits json', function () {
    $controller = instantiateController(LimitsController::class);
    $resp = $controller->limits();

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body)->toHaveKeys(['storageTime', 'maxLength', 'maxLines']);
    expect($body['maxLines'])->toBe(50000);
});

test('FiltersController returns active filter chain', function () {
    $controller = instantiateController(FiltersController::class);
    $resp = $controller->filters();

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body['success'])->toBeTrue();
    expect($body['filters'])->toBeArray();
});

test('IndexController returns all registered public endpoints', function () {
    $controller = instantiateController(IndexController::class);
    $resp = $controller->index();

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body['success'])->toBeTrue();
    $endpoints = $body['endpoints'] ?? $body['data']['endpoints'] ?? [];
    expect($endpoints)->toContain('GET /v1/limits');
    expect($endpoints)->toContain('POST /v1/log');
    expect($endpoints)->toContain('GET /1/limits');
});

test('RateErrorController returns 429 response', function () {
    $controller = instantiateController(RateErrorController::class);
    $resp = $controller->rate();

    expect($resp->getStatusCode())->toBe(429);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body['success'])->toBeFalse();
    expect($body['error'])->toContain('exceeded the rate limit');
});

test('RawController serves raw log and files or throws 404', function () {
    $controller = instantiateController(RawController::class);

    // 1. 404 for nonexistent log
    expect(fn() => $controller->raw('f000000'))->toThrow(ApiError::class);
    expect(fn() => $controller->rawFile('f000000', 'crash.txt'))->toThrow(ApiError::class);

    // 2. Put real log with attachment
    $log = new Log();
    $id = $log->put(
        "Line 1 content\nLine 2 content",
        null,
        [],
        'test-client',
        [['name' => 'crash-reports/crash.txt', 'data' => "Exception in thread\n"]]
    );
    $rawId = $id->get();

    // 3. Serve raw main log
    $mainResp = $controller->raw($rawId);
    expect($mainResp->getStatusCode())->toBe(200);
    expect((string) $mainResp->getBody())->toContain('Line 1 content');

    // 4. Serve raw attachment file
    $fileResp = $controller->rawFile($rawId, 'crash-reports/crash.txt');
    expect($fileResp->getStatusCode())->toBe(200);
    expect((string) $fileResp->getBody())->toContain('Exception in thread');

    // 5. 404 for missing attachment
    expect(fn() => $controller->rawFile($rawId, 'not-exist.txt'))->toThrow(ApiError::class);
});

test('LogMetaController returns metadata or throws 404', function () {
    $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
    $mockUri->shouldReceive('getPath')->andReturn('/v1/log/f123456');
    $mockReq = Mockery::mock(RequestInterface::class);
    $mockReq->shouldReceive('getUri')->andReturn($mockUri);

    $controller = instantiateController(LogMetaController::class, $mockReq);

    // 1. 404 for nonexistent log
    expect(fn() => $controller->meta('f000000'))->toThrow(ApiError::class);

    // 2. Put log with attachment
    $log = new Log();
    $id = $log->put("Meta check log\n", null, [], 'launcher/1.0.0', [
        ['name' => 'extra.txt', 'data' => 'extra'],
    ]);
    $rawId = $id->get();

    // 3. Retrieve metadata
    $resp = $controller->meta($rawId);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body['success'])->toBeTrue();
    expect($body['id'])->toBe($rawId);
    expect($body['source'])->toBe('launcher/1.0.0');
    expect($body['raw'])->toContain('/v1/raw/' . $rawId);
    $fileNames = array_column($body['files'], 'name');
    expect($fileNames)->toContain('extra.txt');
});

test('InsightsController returns analysis json or throws 404', function () {
    $controller = instantiateController(InsightsController::class);

    // 1. 404 for nonexistent log
    expect(fn() => $controller->insights('f000000'))->toThrow(ApiError::class);

    // 2. Put log
    $log = new Log();
    $id = $log->put("Minecraft crash log\n", null, [], null, null);
    $rawId = $id->get();

    // 3. Get insights
    $resp = $controller->insights($rawId);
    expect($resp->getStatusCode())->toBe(200);
    expect((string) $resp->getBody())->not->toBeEmpty();
});

test('LogController creates log and enforces token on delete', function () {
    $jsonPayload = json_encode([
        'content' => "Server start log\n",
        'files' => [
            ['name' => 'crash.txt', 'data' => "Crash content\n"],
        ],
    ]);
    $mockStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $mockStream->shouldReceive('getContents')->andReturn($jsonPayload);
    $mockStream->shouldReceive('__toString')->andReturn($jsonPayload);

    $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
    $mockUri->shouldReceive('getPath')->andReturn('/v1/log');

    $mockReq = Mockery::mock(RequestInterface::class);
    $mockReq->shouldReceive('getUri')->andReturn($mockUri);
    $mockReq->shouldReceive('getServerParams')->andReturn(['remote_addr' => '127.0.0.1']);
    $mockReq->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/json');
    $mockReq->shouldReceive('getHeaderLine')->with('Content-Encoding')->andReturn('');
    $mockReq->shouldReceive('getHeaderLine')->with('User-Agent')->andReturn('fcl/1.2.0');
    $mockReq->shouldReceive('getBody')->andReturn($mockStream);
    $mockReq->shouldReceive('getUploadedFiles')->andReturn([]);
    $mockReq->shouldReceive('getParsedBody')->andReturn([
        'content' => "Server start log\n",
        'files' => [
            ['name' => 'crash.txt', 'data' => "Crash content\n"],
        ],
    ]);

    $controller = instantiateController(\App\Controller\LogController::class, $mockReq);
    $createResp = $controller->create();
    expect($createResp->getStatusCode())->toBe(200);
    $body = json_decode((string) $createResp->getBody(), true);
    expect($body['success'])->toBeTrue();
    $id = $body['id'];
    $token = $body['token'];
    expect($id)->not->toBeEmpty();
    expect($token)->not->toBeEmpty();

    // 2. Delete without authorization header -> 401
    $delReqNoAuth = Mockery::mock(RequestInterface::class);
    $delReqNoAuth->shouldReceive('getHeaderLine')->with('Authorization')->andReturn('');
    $delCtrlNoAuth = instantiateController(\App\Controller\LogController::class, $delReqNoAuth);
    expect(fn() => $delCtrlNoAuth->delete($id))->toThrow(ApiError::class);

    // 3. Delete with invalid token -> 400 with failed item 403
    $delReqBadToken = Mockery::mock(RequestInterface::class);
    $delReqBadToken->shouldReceive('getHeaderLine')->with('Authorization')->andReturn('Bearer invalid_token');
    $delCtrlBadToken = instantiateController(\App\Controller\LogController::class, $delReqBadToken);
    $badTokenResp = $delCtrlBadToken->delete($id);
    expect($badTokenResp->getStatusCode())->toBe(400);
    $badTokenBody = json_decode((string) $badTokenResp->getBody(), true);
    expect($badTokenBody['success'])->toBeFalse();
    expect($badTokenBody['errors'][0]['code'])->toBe(403);

    // 4. Delete with valid token -> 200
    $delReqGood = Mockery::mock(RequestInterface::class);
    $delReqGood->shouldReceive('getHeaderLine')->with('Authorization')->andReturn('Bearer ' . $token);
    $delCtrlGood = instantiateController(\App\Controller\LogController::class, $delReqGood);
    $delResp = $delCtrlGood->delete($id);
    expect($delResp->getStatusCode())->toBe(200);

    // 5. Delete again -> 400 with failed item 404
    $againResp = $delCtrlGood->delete($id);
    expect($againResp->getStatusCode())->toBe(400);
    $againBody = json_decode((string) $againResp->getBody(), true);
    expect($againBody['errors'][0]['code'])->toBe(404);
});

test('TelemetryController accepts batch and single reports', function () {
    // 遥测聚合依赖 Redis；注入 mock 让用例不依赖本机是否启动 redis-server
    \App\Client\RedisClient::setTestConnection(new \Tests\Mocks\RedisMock());

    $mockReq = Mockery::mock(RequestInterface::class);
    $mockReq->shouldReceive('getParsedBody')->andReturn([
        'items' => [
            [
                'type' => 'api',
                'endpoint' => '/v1/limits',
                'method' => 'GET',
                'duration' => 10.0,
                'status' => 200,
                'timestamp' => time(),
            ],
        ],
    ]);

    $controller = instantiateController(\App\Controller\TelemetryController::class, $mockReq);
    $resp = $controller->report();
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body['success'])->toBeTrue();
    expect($body['processed'])->toBe(1);

    \App\Client\RedisClient::setTestConnection(null);
});

test('AnalyseController parses content and returns analysis json', function () {
    $payload = json_encode(['content' => "Test server log\n"]);
    $mockStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $mockStream->shouldReceive('getContents')->andReturn($payload);
    $mockStream->shouldReceive('__toString')->andReturn($payload);

    $mockReq = Mockery::mock(RequestInterface::class);
    $mockReq->shouldReceive('getParsedBody')->andReturn(['content' => "Test server log\n"]);
    $mockReq->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/json');
    $mockReq->shouldReceive('getHeaderLine')->with('Content-Encoding')->andReturn('');
    $mockReq->shouldReceive('getUploadedFiles')->andReturn([]);
    $mockReq->shouldReceive('getBody')->andReturn($mockStream);

    $controller = instantiateController(\App\Controller\AnalyseController::class, $mockReq);
    $resp = $controller->analyse();
    expect($resp->getStatusCode())->toBe(200);
    expect((string) $resp->getBody())->not->toBeEmpty();
    $body = json_decode((string) $resp->getBody(), true);
    expect($body)->toBeArray();
});
