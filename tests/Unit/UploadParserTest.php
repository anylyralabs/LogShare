<?php

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
});

test('parseFiles normalizes plain text entries', function () {
    $result = App\UploadParser::parseFiles([
        ['name' => 'latest.log', 'content' => "line1\nline2"],
        ['name' => 'debug.txt', 'content' => 'debug'],
    ]);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
    expect($result[0])->toMatchArray(['name' => 'latest.log', 'data' => "line1\nline2"]);
    expect($result[1])->toMatchArray(['name' => 'debug.txt', 'data' => 'debug']);
});

test('parseFiles returns empty array for null or empty input', function () {
    expect(App\UploadParser::parseFiles(null))->toBe([]);
    expect(App\UploadParser::parseFiles([]))->toBe([]);
});

test('parseFiles expands zip archives into entries', function () {
    $zipData = makeZip([
        'latest.log' => "[12:00] INFO: starting\n",
        'crash-reports/crash-1.txt' => "java.lang.Error\nat a.b.c\n",
        'empty-dir/' => '',
    ]);

    $result = App\UploadParser::parseFiles([
        ['name' => 'server-logs.zip', 'content' => $zipData],
    ]);

    expect($result)->toHaveCount(2);
    expect($result[0])->toMatchArray(['name' => 'latest.log', 'data' => "[12:00] INFO: starting\n"]);
    expect($result[1])->toMatchArray(['name' => 'crash-reports/crash-1.txt', 'data' => "java.lang.Error\nat a.b.c\n"]);
});

test('parseFiles mixes plain text and zip entries', function () {
    $zipData = makeZip([
        'a.txt' => 'aaa',
        'b.txt' => 'bbb',
    ]);

    $result = App\UploadParser::parseFiles([
        ['name' => 'main.log', 'content' => 'main'],
        ['name' => 'extra.zip', 'content' => $zipData],
    ]);

    expect($result)->toHaveCount(3);
    expect($result[0])->toMatchArray(['name' => 'main.log', 'data' => 'main']);
    expect(array_column($result, 'name'))->toContain('a.txt');
    expect(array_column($result, 'name'))->toContain('b.txt');
});

test('parseFiles rejects invalid zip data', function () {
    $result = App\UploadParser::parseFiles([
        ['name' => 'bad.zip', 'content' => 'this is not a zip file at all'],
    ]);

    expect($result)->toBeInstanceOf(App\ApiError::class);
    expect($result->getHttpCode())->toBe(400);
});

test('parseFiles enforces max file count', function () {
    $data = $this->dataProp->getValue();
    $data['storage']['uploadFiles'] = ['maxFiles' => 2, 'maxTotalBytes' => 1024 * 1024];
    $this->dataProp->setValue(null, $data);

    $result = App\UploadParser::parseFiles([
        ['name' => 'a.log', 'content' => 'a'],
        ['name' => 'b.log', 'content' => 'b'],
        ['name' => 'c.log', 'content' => 'c'],
    ]);

    expect($result)->toBeInstanceOf(App\ApiError::class);
    expect($result->getHttpCode())->toBe(413);
});

test('parseFiles enforces max total size', function () {
    $data = $this->dataProp->getValue();
    $data['storage']['uploadFiles'] = ['maxFiles' => 10, 'maxTotalBytes' => 10];
    $this->dataProp->setValue(null, $data);

    $result = App\UploadParser::parseFiles([
        ['name' => 'a.log', 'content' => str_repeat('x', 6)],
        ['name' => 'b.log', 'content' => str_repeat('y', 6)],
    ]);

    expect($result)->toBeInstanceOf(App\ApiError::class);
    expect($result->getHttpCode())->toBe(413);
});

test('parseFiles rejects path traversal names', function () {
    foreach (['../evil.log', '/absolute/path.log', 'a/b/../../c.log', '..\\win.log', ''] as $badName) {
        $result = App\UploadParser::parseFiles([
            ['name' => $badName, 'content' => 'x'],
        ]);
        expect($result)->toBeInstanceOf(App\ApiError::class, "name should be rejected: {$badName}");
        expect($result->getHttpCode())->toBe(400);
    }
});

test('parseFiles rejects path traversal inside zip archives', function () {
    $data = $this->dataProp->getValue();
    $this->dataProp->setValue(null, $data);

    $zipData = makeZip([
        '../evil.txt' => 'bad',
        'ok.log' => 'fine',
    ]);

    $result = App\UploadParser::parseFiles([
        ['name' => 'x.zip', 'content' => $zipData],
    ]);

    expect($result)->toBeInstanceOf(App\ApiError::class);
    expect($result->getHttpCode())->toBe(400);
});

test('validateFileName accepts safe relative paths', function () {
    expect(App\UploadParser::validateFileName('latest.log'))->toBeTrue();
    expect(App\UploadParser::validateFileName('crash-reports/crash-1.txt'))->toBeTrue();
    expect(App\UploadParser::validateFileName('sub/dir/file.log'))->toBeTrue();
});

test('validateFileName rejects unsafe names', function () {
    expect(App\UploadParser::validateFileName('../x'))->toBeFalse();
    expect(App\UploadParser::validateFileName('/abs/x'))->toBeFalse();
    expect(App\UploadParser::validateFileName('a/../../b'))->toBeFalse();
    expect(App\UploadParser::validateFileName(''))->toBeFalse();
    expect(App\UploadParser::validateFileName("nul\x00byte"))->toBeFalse();
    expect(App\UploadParser::validateFileName("evil\r\nheader.txt"))->toBeFalse();
    expect(App\UploadParser::validateFileName("test\nfile.log"))->toBeFalse();
    expect(App\UploadParser::validateFileName("test\x1bfile.log"))->toBeFalse();
});
test('parseFiles renames duplicate file names instead of colliding', function () {
    $result = App\UploadParser::parseFiles([
        ['name' => 'main.log', 'content' => 'a'],
        ['name' => 'main.log', 'content' => 'b'],
        ['name' => 'main.log', 'content' => 'c'],
    ]);

    expect($result)->toBeArray();
    expect(array_column($result, 'name'))->toBe(['main.log', 'main (2).log', 'main (3).log']);
    expect(array_column($result, 'data'))->toBe(['a', 'b', 'c']);
});

test('parseFiles dedupes names across zip and plain entries', function () {
    $zip = makeZip(['latest.log' => 'from zip']);

    $result = App\UploadParser::parseFiles([
        ['name' => 'latest.log', 'content' => 'from body'],
        ['name' => 'bundle.zip', 'content' => $zip],
    ]);

    expect($result)->toBeArray();
    expect(array_column($result, 'name'))->toBe(['latest.log', 'latest (2).log']);
});

test('parseFiles keeps names without extension readable when deduped', function () {
    $result = App\UploadParser::parseFiles([
        ['name' => 'crash-reports/report', 'content' => 'a'],
        ['name' => 'crash-reports/report', 'content' => 'b'],
    ]);

    expect(array_column($result, 'name'))->toBe(['crash-reports/report', 'crash-reports/report (2)']);
});
