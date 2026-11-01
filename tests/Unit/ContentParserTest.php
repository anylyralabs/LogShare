<?php

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
});

function parseJsonViaContentParser(array $data): App\ApiError|array
{
    $request = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $request->shouldReceive('getHeaderLine')->with('User-Agent')->byDefault()->andReturn('');
    $parser = new App\ContentParser($request);
    $ref = new ReflectionClass(App\ContentParser::class);
    $method = $ref->getMethod('parseJsonData');
    return $method->invoke($parser, $data);
}

test('parseJsonData extracts files, metadata and source', function () {
    $result = parseJsonViaContentParser([
        'files' => [
            ['name' => 'latest.log', 'content' => 'log line'],
        ],
        'metadata' => [['key' => 'k', 'value' => 'v']],
        'source' => 'my-server',
    ]);

    expect($result)->toBeArray();
    expect($result)->not->toHaveKey('content');
    expect($result['files'])->toHaveCount(1);
    expect($result['files'][0])->toMatchArray(['name' => 'latest.log', 'data' => 'log line']);
    expect($result['source'])->toBe('my-server');
    expect($result['metadata'])->toHaveCount(1);
});

test('parseJsonData requires a non-empty files array', function () {
    foreach ([[], ['files' => []], ['files' => 'not-an-array'], ['files' => null]] as $payload) {
        $result = parseJsonViaContentParser($payload);
        expect($result)->toBeInstanceOf(App\ApiError::class);
        expect($result->getHttpCode())->toBe(400);
    }
});

test('parseJsonData accepts legacy content and maps it to the main file', function () {
    $result = parseJsonViaContentParser(['content' => 'legacy body']);

    expect($result)->toBeArray();
    expect($result['mainFile'])->toBe('latest.log');
    expect($result['files'])->toHaveCount(1);
    expect($result['files'][0])->toMatchArray([
        'name' => 'latest.log',
        'data' => 'legacy body',
        'size' => strlen('legacy body'),
    ]);
});

test('parseJsonData keeps legacy content and files together', function () {
    $result = parseJsonViaContentParser([
        'content' => 'main body',
        'files' => [
            ['name' => 'crash.txt', 'content' => 'crash body'],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result['mainFile'])->toBe('latest.log');
    expect($result['files'])->toHaveCount(2);
    expect($result['files'][0])->toMatchArray(['name' => 'latest.log', 'data' => 'main body']);
    expect($result['files'][1])->toMatchArray(['name' => 'crash.txt', 'data' => 'crash body']);
});

test('parseJsonData avoids a legacy main file name collision', function () {
    $result = parseJsonViaContentParser([
        'content' => 'main body',
        'files' => [
            ['name' => 'latest.log', 'content' => 'existing file'],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result['mainFile'])->toBe('main.log');
    expect(array_column($result['files'], 'name'))->toBe(['main.log', 'latest.log']);
});

test('parseJsonData still rejects an empty legacy content without files', function () {
    $result = parseJsonViaContentParser(['content' => '']);

    expect($result)->toBeInstanceOf(App\ApiError::class);
    expect($result->getHttpCode())->toBe(400);
});

test('parseJsonData parses files array via App\UploadParser', function () {
    $result = parseJsonViaContentParser([
        'files' => [
            ['name' => 'a.log', 'content' => 'aaa'],
            ['name' => 'b.log', 'content' => 'bbb'],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result['files'])->toHaveCount(2);
    expect($result['files'][0])->toMatchArray(['name' => 'a.log', 'data' => 'aaa']);
});

test('parseJsonData rejects invalid files via App\UploadParser', function () {
    $result = parseJsonViaContentParser([
        'files' => [
            ['name' => '../evil.log', 'content' => 'bad'],
        ],
    ]);

    expect($result)->toBeInstanceOf(App\ApiError::class);
    expect($result->getHttpCode())->toBe(400);
});

test('parseJsonData truncates source to 64 characters', function () {
    $result = parseJsonViaContentParser([
        'files' => [['name' => 'x.log', 'content' => 'x']],
        'source' => str_repeat('源', 100),
    ]);

    expect($result)->toBeArray();
    expect(mb_strlen($result['source'], 'UTF-8'))->toBe(64);
});

test('ContentParser parses form-urlencoded files, metadata and source', function () {
    $payload = http_build_query([
        'files' => [
            ['name' => 'form.log', 'content' => 'form log line'],
        ],
        'metadata' => [
            ['key' => 'version', 'value' => '1.20.1', 'label' => 'Minecraft 版本', 'visible' => true],
            ['key' => 'private', 'value' => 'hidden', 'visible' => false],
        ],
        'source' => 'HMCL/3.5.0',
    ]);

    $request = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $stream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $stream->shouldReceive('getContents')->andReturn($payload);
    $request->shouldReceive('getBody')->andReturn($stream);
    $request->shouldReceive('getHeaderLine')->with('Content-Encoding')->andReturn('');
    $request->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/x-www-form-urlencoded');
    $request->shouldReceive('getHeaderLine')->with('User-Agent')->andReturn('HMCL/3.5.0');

    $parser = new App\ContentParser($request);
    $result = $parser->getContent();

    expect($result)->toBeArray();
    expect($result['files'])->toHaveCount(1);
    expect($result['files'][0])->toMatchArray(['name' => 'form.log', 'data' => 'form log line']);
    expect($result['source'])->toBe('HMCL/3.5.0');
    expect($result['metadata'])->toHaveCount(2);
    expect($result['metadata'][0]->getKey())->toBe('version');
    expect($result['metadata'][1]->isVisible())->toBeFalse();
});

test('ContentParser handles Content-Encoding: br appropriately', function () {
    $request = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $stream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $stream->shouldReceive('getContents')->andReturn('raw-brotli-bytes');
    $request->shouldReceive('getBody')->andReturn($stream);
    $request->shouldReceive('getHeaderLine')->with('Content-Encoding')->andReturn('br');
    $request->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/json');

    $parser = new App\ContentParser($request);
    $result = $parser->getContent();

    if (!function_exists('brotli_uncompress')) {
        expect($result)->toBeInstanceOf(App\ApiError::class);
        expect($result->getHttpCode())->toBe(501);
        expect($result->getMessage())->toContain('Brotli');
    } else {
        expect($result)->toBeInstanceOf(App\ApiError::class);
        expect($result->getHttpCode())->toBe(400);
        expect($result->getMessage())->toContain('Failed to decode request body with encoding: br');
    }
});

test('ContentParser decompresses valid brotli payload when extension is available', function () {
    if (!function_exists('brotli_compress') || !function_exists('brotli_uncompress')) {
        $this->markTestSkipped('brotli extension not loaded');
    }

    $request = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $stream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $payload = json_encode([
        'files' => [['name' => 'brotli.log', 'content' => 'hello brotli world']],
        'source' => 'test',
    ]);
    $compressed = brotli_compress((string) $payload);
    $stream->shouldReceive('getContents')->andReturn($compressed);
    $request->shouldReceive('getBody')->andReturn($stream);
    $request->shouldReceive('getHeaderLine')->with('Content-Encoding')->andReturn('br');
    $request->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/json');
    $request->shouldReceive('getHeaderLine')->with('User-Agent')->byDefault()->andReturn('');

    $parser = new App\ContentParser($request);
    $result = $parser->getContent();

    expect($result)->toBeArray();
    expect($result['files'][0]['data'])->toBe('hello brotli world');
});

test('parseLauncherSource accepts launcher/version formats', function () {
    expect(App\ContentParser::parseLauncherSource('FCL/1.3.3.2'))->toBe('FCL/1.3.3.2');
    expect(App\ContentParser::parseLauncherSource('ZL2/Android_2.5.3'))->toBe('ZL2/Android_2.5.3');
    expect(App\ContentParser::parseLauncherSource('pojav/3.4.0'))->toBe('pojav/3.4.0');
    expect(App\ContentParser::parseLauncherSource('HMCL/3.5.3.245'))->toBe('HMCL/3.5.3.245');
    expect(App\ContentParser::parseLauncherSource('PCL2/2.8.0'))->toBe('PCL2/2.8.0');
    expect(App\ContentParser::parseLauncherSource('  FoldCraft/1.2.0  '))->toBe('FoldCraft/1.2.0');
    expect(App\ContentParser::parseLauncherSource('折叠工艺/1.2.0'))->toBe('折叠工艺/1.2.0');
    expect(App\ContentParser::parseLauncherSource('Launcher/1.0.0+build.42'))->toBe('Launcher/1.0.0+build.42');
});

test('parseLauncherSource rejects browsers, tools, and invalid formats', function () {
    expect(App\ContentParser::parseLauncherSource(null))->toBeNull();
    expect(App\ContentParser::parseLauncherSource(''))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('Mozilla/5.0'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('curl/7.88.1'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('Wget/1.21'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('PostmanRuntime/7.32.3'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('okhttp/4.9.0'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('python-requests/2.31.0'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('plain-string-without-version'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource('too/many/slash/parts/1.0'))->toBeNull();
    expect(App\ContentParser::parseLauncherSource(str_repeat('a', 65) . '/1.0'))->toBeNull();
});

test('parseJsonData falls back to User-Agent launcher source when omitted', function () {
    $request = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $request->shouldReceive('getHeaderLine')->with('User-Agent')->andReturn('FCL/1.3.3.2');

    $parser = new App\ContentParser($request);
    $ref = new ReflectionClass(App\ContentParser::class);
    $method = $ref->getMethod('parseJsonData');
    $result = $method->invoke($parser, [
        'files' => [['name' => 'test.log', 'content' => 'test log']],
    ]);

    expect($result)->toBeArray();
    expect($result['source'])->toBe('FCL/1.3.3.2');
});

test('parseJsonData does not overwrite explicit source with User-Agent', function () {
    $request = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $request->shouldReceive('getHeaderLine')->with('User-Agent')->andReturn('FCL/1.3.3.2');

    $parser = new App\ContentParser($request);
    $ref = new ReflectionClass(App\ContentParser::class);
    $method = $ref->getMethod('parseJsonData');
    $result = $method->invoke($parser, [
        'files' => [['name' => 'test.log', 'content' => 'test log']],
        'source' => 'custom-source',
    ]);

    expect($result)->toBeArray();
    expect($result['source'])->toBe('custom-source');
});

test('parseJsonData falls back to 未指定 when both source and User-Agent launcher are absent', function () {
    $request = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $request->shouldReceive('getHeaderLine')->with('User-Agent')->andReturn('Mozilla/5.0 (Windows NT 10.0; Win64; x64)');

    $parser = new App\ContentParser($request);
    $ref = new ReflectionClass(App\ContentParser::class);
    $method = $ref->getMethod('parseJsonData');
    $result = $method->invoke($parser, [
        'files' => [['name' => 'test.log', 'content' => 'test log']],
    ]);

    expect($result)->toBeArray();
    expect($result['source'])->toBe('未指定');
});

test('parseJsonData falls back to 未指定 when User-Agent is empty', function () {
    $request = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $request->shouldReceive('getHeaderLine')->with('User-Agent')->andReturn('');

    $parser = new App\ContentParser($request);
    $ref = new ReflectionClass(App\ContentParser::class);
    $method = $ref->getMethod('parseJsonData');
    $result = $method->invoke($parser, [
        'files' => [['name' => 'test.log', 'content' => 'test log']],
    ]);

    expect($result)->toBeArray();
    expect($result['source'])->toBe('未指定');
});
