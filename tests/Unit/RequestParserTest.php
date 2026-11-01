<?php

declare(strict_types=1);

use App\Parser\RequestParser;

test('RequestParser parses uncompressed json', function () {
    $parser = new RequestParser();
    $result = $parser->parse('{"files":[{"name":"x.log","content":"hello"}]}', 'application/json');

    expect($result)->toBe(['files' => [['name' => 'x.log', 'content' => 'hello']]]);
});

test('RequestParser parses gzipped json', function () {
    $parser = new RequestParser();
    $raw = (string) gzencode('{"files":[{"name":"x.log","content":"hello gzip"}]}');
    $result = $parser->parse($raw, 'application/json');

    expect($result)->toBe(['files' => [['name' => 'x.log', 'content' => 'hello gzip']]]);
});

test('RequestParser parses deflated json', function () {
    $parser = new RequestParser();
    $raw = (string) gzdeflate('{"files":[{"name":"x.log","content":"hello deflate"}]}');
    $result = $parser->parse($raw, 'application/json');

    expect($result)->toBe(['files' => [['name' => 'x.log', 'content' => 'hello deflate']]]);
});

test('RequestParser returns empty array for malformed json without throwing', function () {
    $parser = new RequestParser();
    $result = $parser->parse('invalid-json', 'application/json');

    expect($result)->toBe([]);
});

test('RequestParser returns empty array for empty body', function () {
    $parser = new RequestParser();
    $result = $parser->parse('', 'application/json');

    expect($result)->toBe([]);
});

test('RequestParser identifies supported content types', function () {
    $parser = new RequestParser();

    expect($parser->has('application/json'))->toBeTrue();
    expect($parser->has('text/json'))->toBeTrue();
    expect($parser->has('application/x-www-form-urlencoded'))->toBeFalse();
});

test('container resolves RequestParserInterface to RequestParser', function () {
    $dependencies = require __DIR__ . '/../../config/autoload/dependencies.php';
    expect($dependencies[\Hyperf\HttpMessage\Server\RequestParserInterface::class] ?? null)
        ->toBe(RequestParser::class);
});

test('RequestParser rejects gzip bomb exceeding max decompressed bytes', function () {
    $parser = new class extends RequestParser {
        // 测试时将上限临时调低以快速验证截断逻辑
        protected const int MAX_DECOMPRESSED_BYTES = 100;
    };

    // 构造展开后 500 字节的 gzip 数据
    $largeString = json_encode(['files' => [['name' => 'x.log', 'content' => str_repeat('a', 500)]]]);
    $raw = (string) gzencode($largeString);

    $result = $parser->parse($raw, 'application/json');
    // 超限后 decodeBody 返回 null，parse 返回空数组，不展开到内存
    expect($result)->toBe([]);
});

test('RequestParser supports brotli decompression when extension is available', function () {
    if (!function_exists('brotli_compress') || !function_exists('brotli_uncompress')) {
        $this->markTestSkipped('brotli extension not loaded in current environment');
    }

    $parser = new RequestParser();
    $data = ['files' => [['name' => 'x.log', 'content' => 'brotli compressed content']], 'source' => 'client'];
    $raw = (string) brotli_compress(json_encode($data));

    $result = $parser->parse($raw, 'application/json');
    expect($result)->toBe($data);
});


