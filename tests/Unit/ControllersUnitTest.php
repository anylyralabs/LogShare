<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiError;
use App\Controller\IndexController;
use App\Controller\InsightsController;
use App\Controller\LimitsController;
use App\Controller\LogMetaController;
use App\Controller\RawController;
use App\Data\Token;
use App\Log;
use Hyperf\HttpServer\Contract\RequestInterface;
use Mockery;
use ReflectionClass;
use Tests\Support\InMemoryStorage;

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
    $data['storage']['storages']['s'] = [
        'name' => 'InMemory',
        'class' => InMemoryStorage::class,
        'enabled' => true,
    ];
    $data['storage']['storageId'] = 's';
    $data['cache']['enabled'] = false;
    $this->dataProp->setValue(null, $data);
    InMemoryStorage::reset();
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    InMemoryStorage::reset();
});

test('LimitsController returns storage limits json', function () {
    $controller = instantiateController(LimitsController::class);
    $resp = $controller->limits();

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body)->toHaveKeys(['storageTime', 'maxFiles', 'maxTotalBytes', 'maxRequestBodyBytes']);
    expect($body['maxFiles'])->toBe(200);
    expect($body['maxTotalBytes'])->toBe(20971520);
    expect($body['maxRequestBodyBytes'])->toBe(33554432);
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
    expect($endpoints)->toContain('GET /v1/insights/{id}/{filename:.+}');
    expect($endpoints)->not->toContain('POST /v1/analyse');
});

test('RawController serves single files and lists multiple files', function () {
    $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
    $mockUri->shouldReceive('getPath')->andReturn('/v1/raw/f123456');
    $mockReq = Mockery::mock(RequestInterface::class);
    $mockReq->shouldReceive('getUri')->andReturn($mockUri);
    $controller = instantiateController(RawController::class, $mockReq);

    // 1. 404 for nonexistent log
    expect(fn() => $controller->raw('f000000'))->toThrow(ApiError::class);
    expect(fn() => $controller->rawFile('f000000', 'crash.txt'))->toThrow(ApiError::class);

    // 2. Single file: raw/{id} serves the file directly
    $log = new Log();
    $id = $log->put([
        ['name' => 'latest.log', 'data' => "Line 1 content\nLine 2 content"],
    ], null, [], 'test-client');
    $rawId = $id->get();

    $mainResp = $controller->raw($rawId);
    expect($mainResp->getStatusCode())->toBe(200);
    expect((string) $mainResp->getBody())->toContain('Line 1 content');

    // 3. Multiple files: raw/{id} returns a 200 file list
    $log2 = new Log();
    $id2 = $log2->put([
        ['name' => 'latest.log', 'data' => "main log\n"],
        ['name' => 'crash-reports/crash.txt', 'data' => "Exception in thread\n"],
    ], null, [], 'test-client');
    $multiId = $id2->get();

    $multiResp = $controller->raw($multiId);
    expect($multiResp->getStatusCode())->toBe(200);
    $multiBody = json_decode((string) $multiResp->getBody(), true);
    expect($multiBody['fileCount'])->toBe(2);
    expect($multiBody['requiresFileSelection'])->toBeTrue();
    expect(array_column($multiBody['files'], 'name'))->toContain('crash-reports/crash.txt');

    // 4. Explicit filename always serves content
    $fileResp = $controller->rawFile($multiId, 'crash-reports/crash.txt');
    expect($fileResp->getStatusCode())->toBe(200);
    expect((string) $fileResp->getBody())->toContain('Exception in thread');

    // 5. 404 for missing file
    expect(fn() => $controller->rawFile($multiId, 'not-exist.txt'))->toThrow(ApiError::class);
});

test('LogMetaController returns metadata and the file list', function () {
    $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
    $mockUri->shouldReceive('getPath')->andReturn('/v1/log/f123456');
    $mockReq = Mockery::mock(RequestInterface::class);
    $mockReq->shouldReceive('getUri')->andReturn($mockUri);

    $controller = instantiateController(LogMetaController::class, $mockReq);

    // 1. 404 for nonexistent log
    expect(fn() => $controller->meta('f000000'))->toThrow(ApiError::class);

    // 2. Put log with two files
    $log = new Log();
    $id = $log->put([
        ['name' => 'latest.log', 'data' => "Meta check log\n"],
        ['name' => 'extra.txt', 'data' => 'extra'],
    ], null, [], 'launcher/1.0.0');
    $rawId = $id->get();

    // 3. Retrieve metadata
    $resp = $controller->meta($rawId);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getBody(), true);
    expect($body['success'])->toBeTrue();
    expect($body['id'])->toBe($rawId);
    expect($body['source'])->toBe('launcher/1.0.0');
    expect($body['filesUrl'])->toContain('/v1/log/' . $rawId);
    expect($body)->not->toHaveKey('raw');
    $fileNames = array_column($body['files'], 'name');
    expect($fileNames)->toContain('extra.txt');
    expect($body['files'][0])->toHaveKeys(['name', 'size', 'lines', 'raw', 'insights']);
});

test('InsightsController analyses single files and lists multiple files', function () {
    $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
    $mockUri->shouldReceive('getPath')->andReturn('/v1/insights/f123456');
    $mockReq = Mockery::mock(RequestInterface::class);
    $mockReq->shouldReceive('getUri')->andReturn($mockUri);
    $controller = instantiateController(InsightsController::class, $mockReq);

    // 1. 404 for nonexistent log
    expect(fn() => $controller->insights('f000000'))->toThrow(ApiError::class);

    // 2. Single file
    $log = new Log();
    $id = $log->put([
        ['name' => 'latest.log', 'data' => "Minecraft crash log\n"],
    ], null, [], null);
    $rawId = $id->get();

    $resp = $controller->insights($rawId);
    expect($resp->getStatusCode())->toBe(200);
    expect((string) $resp->getBody())->not->toBeEmpty();

    // 3. Multiple files return a 200 list instead of guessing
    $log2 = new Log();
    $id2 = $log2->put([
        ['name' => 'latest.log', 'data' => "Minecraft crash log\n"],
        ['name' => 'crash.txt', 'data' => "Crash\n"],
    ], null, [], null);
    $multiResp = $controller->insights($id2->get());
    expect($multiResp->getStatusCode())->toBe(200);
    $multiBody = json_decode((string) $multiResp->getBody(), true);
    expect($multiBody['fileCount'])->toBe(2);
    expect($multiBody['requiresFileSelection'])->toBeTrue();

    // 4. Explicit filename analyses that file
    $fileResp = $controller->insightsFile($id2->get(), 'crash.txt');
    expect($fileResp->getStatusCode())->toBe(200);
    expect((string) $fileResp->getBody())->not->toBeEmpty();
});

test('LogController creates log and enforces token on delete', function () {
    $jsonPayload = json_encode([
        'files' => [
            ['name' => 'server.log', 'content' => "Server start log\n"],
            ['name' => 'crash.txt', 'content' => "Crash content\n"],
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
        'files' => [
            ['name' => 'server.log', 'content' => "Server start log\n"],
            ['name' => 'crash.txt', 'content' => "Crash content\n"],
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
