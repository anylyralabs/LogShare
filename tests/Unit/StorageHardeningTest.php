<?php

declare(strict_types=1);

use App\Data\MetadataEntry;
use App\Storage\FilesystemStorage;

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $this->tmpDir = CORE_PATH . '/tmp/hardening_' . uniqid();
    mkdir($this->tmpDir, 0777, true);

    $cfg = $this->origData;
    $cfg['storage']['storageId'] = 'f';
    $cfg['storage']['storages']['f']['enabled'] = true;
    $cfg['cache']['enabled'] = false;
    $cfg['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $cfg);
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        rmdir($this->tmpDir);
    }
});

test('FilesystemStorage reports created=false only for deduped writes', function () {
    $createdA = null;
    FilesystemStorage::Put('body', null, [], 'src', null, $createdA);
    expect($createdA)->toBeTrue();

    // 文件系统后端不做内容去重，每次都是新建
    $createdB = null;
    FilesystemStorage::Put('body', null, [], 'src', null, $createdB);
    expect($createdB)->toBeTrue();
});

test('FilesystemStorage truncates source by characters, not bytes', function () {
    $source = str_repeat('中文启动器', 20);
    $id = FilesystemStorage::Put('body', null, [], $source);
    $doc = FilesystemStorage::Get($id);

    expect($doc)->not->toBeNull();
    expect($doc['source'])->toStartWith('中文启动器');
    expect(mb_check_encoding($doc['source'], 'UTF-8'))->toBeTrue();
    expect(strlen($doc['source']))->toBeLessThanOrEqual(64);
});

test('MetadataEntry serializes only visible entries for public output', function () {
    $entries = [
        MetadataEntry::fromArray(['key' => 'public', 'value' => 'v', 'visible' => true]),
        MetadataEntry::fromArray(['key' => 'private', 'value' => 'secret', 'visible' => false]),
    ];

    $serialized = MetadataEntry::serializeVisible($entries);

    expect($serialized)->toHaveCount(1);
    expect($serialized[0]['key'])->toBe('public');
});
