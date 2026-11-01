<?php

use Tests\Support\InMemoryStorage;

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $data = $this->origData;
    $data['storage']['storageId'] = 's';
    $data['storage']['storages']['s'] = [
        'name' => 'InMemory',
        'class' => InMemoryStorage::class,
        'enabled' => true,
    ];
    $data['cache']['enabled'] = false;
    $this->dataProp->setValue(null, $data);
    InMemoryStorage::reset();
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    InMemoryStorage::reset();
});

test('Log exposes additional file accessors', function () {
    $log = new \App\Log();
    $ref = new ReflectionClass(\App\Log::class);
    $prop = $ref->getProperty('files');
    $prop->setValue($log, [
        ['name' => 'a.log', 'data' => "line1\nline2\nline3", 'size' => 17],
        ['name' => 'sub/b.log', 'data' => 'solo', 'size' => 4],
    ]);

    expect($log->getFiles())->toHaveCount(2);
    expect($log->getFiles()[0])->toMatchArray(['name' => 'a.log', 'size' => 17]);
    expect($log->getFiles()[0])->not->toHaveKey('data');

    expect($log->getFile('a.log'))->toBe("line1\nline2\nline3");
    expect($log->getFile('sub/b.log'))->toBe('solo');
    expect($log->getFile('missing.log'))->toBeNull();

    expect($log->hasFile('a.log'))->toBeTrue();
    expect($log->hasFile('nope'))->toBeFalse();

    expect($log->getFileLineNumbers('a.log'))->toBe(3);
    expect($log->getFileLineNumbers('sub/b.log'))->toBe(1);
    expect($log->getFileLineNumbers('missing'))->toBe(0);
});

test('Log allows a single file larger than the legacy body limit', function () {
    $cfg = $this->dataProp->getValue();
    $cfg['storage']['uploadFiles']['maxTotalBytes'] = 1000;
    $this->dataProp->setValue(null, $cfg);

    $log = new \App\Log();
    $id = $log->put([
        ['name' => 'attachment.txt', 'data' => str_repeat('x', 50)],
    ], null, [], 'src');

    expect($id)->not->toBeNull();
    $loaded = new \App\Log($id);
    expect(strlen((string) $loaded->getFile('attachment.txt')))->toBe(50);
});

test('Log put applies pre filters to additional files', function () {
    $ip = 'AccessToken: 550e8400-e29b-41d4-a716-446655440000';
    $log = new \App\Log();
    $id = $log->put([
        ['name' => 'main.log', 'data' => 'main line ' . $ip],
        ['name' => 'extra.log', 'data' => 'extra ' . $ip],
    ], null, [], 's');

    expect($id)->not->toBeNull();
    $loaded = new \App\Log($id);
    expect($loaded->exists())->toBeTrue();
    expect($loaded->getFile('main.log'))->not->toContain('550e8400');
    expect($loaded->getFile('extra.log'))->not->toContain('550e8400');
    expect($loaded->getFile('extra.log'))->toContain('extra ');

    $loaded->delete();
});
