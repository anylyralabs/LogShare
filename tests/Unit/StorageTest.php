<?php

use App\Storage\FilesystemStorage;

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();
    $this->tmpDir = CORE_PATH . '/tmp/logshare_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);
});

afterEach(function () {
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }
    $this->dataProp->setValue(null, $this->origData);
});

test('FilesystemStorage persists additional files under the same id', function () {
    $data = $this->dataProp->getValue();
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);

    $main = "[12:34:56] [Server thread/INFO]: Starting minecraft server version 1.20.1\n";
    $crash = "---- Minecraft Crash Report ----\njava.lang.OutOfMemoryError\nat net.minecraft.class_1000\n";
    $debug = "GL: OpenGL 3.2";

    $id = FilesystemStorage::Put($main, null, [], 'test', [
        ['name' => 'crash-reports/crash-01.txt', 'data' => $crash],
        ['name' => 'debug.txt', 'data' => $debug],
    ]);

    expect($id)->toBeInstanceOf(\App\Id::class);

    $result = FilesystemStorage::Get($id);
    expect($result['data'])->toBe($main);
    expect($result['files'])->toHaveCount(2);

    $crashFile = $result['files'][0];
    expect($crashFile['name'])->toBe('crash-reports/crash-01.txt');
    expect($crashFile['data'])->toBe($crash);
    expect($crashFile['size'])->toBe(strlen($crash));

    $meta = FilesystemStorage::Get($id, false);
    expect($meta['files'][0])->toHaveKeys(['name', 'size']);
    expect($meta['files'][0])->not->toHaveKey('data');

    expect(FilesystemStorage::Delete($id))->toBeTrue();
    expect(FilesystemStorage::Get($id))->toBeNull();
});

test('FilesystemStorage Get omits file content when includeContent is false and keeps data', function () {
    $data = $this->dataProp->getValue();
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);

    $id = FilesystemStorage::Put('main', null, [], null, [
        ['name' => 'extra.log', 'data' => 'content'],
    ]);

    $meta = FilesystemStorage::Get($id, false);
    expect($meta['data'])->toBe('main');
    expect($meta['files'])->toHaveCount(1);
    expect($meta['files'][0])->toMatchArray(['name' => 'extra.log', 'size' => 7]);
    expect($meta['files'][0])->not->toHaveKey('data');

    FilesystemStorage::Delete($id);
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

test('Log put applies pre filters to additional files', function () {
    $data = $this->dataProp->getValue();
    $data['storage']['storages']['f'] = [
        'name' => 'Filesystem',
        'class' => '\\App\\Storage\\FilesystemStorage',
        'enabled' => true,
    ];
    $data['storage']['storageId'] = 'f';
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $data['cache']['enabled'] = false;
    $this->dataProp->setValue(null, $data);

    $ip = 'AccessToken: 550e8400-e29b-41d4-a716-446655440000';
    $log = new \App\Log();
    $id = $log->put('main line ' . $ip, null, [], 's', [
        ['name' => 'extra.log', 'data' => 'extra ' . $ip],
    ]);

    expect($id)->not->toBeNull();
    $loaded = new \App\Log($id);
    expect($loaded->exists())->toBeTrue();
    expect($loaded->getContent())->not->toContain('550e8400');
    expect($loaded->getFile('extra.log'))->not->toContain('550e8400');
    expect($loaded->getFile('extra.log'))->toContain('extra ');

    $loaded->delete();
});
test('FilesystemStorage Renew resets created timestamp', function () {
    $data = $this->dataProp->getValue();
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);

    $id = FilesystemStorage::Put('main', null, [], null);
    $before = FilesystemStorage::Get($id);
    expect($before['created'])->toBeInt();

    sleep(1);
    expect(FilesystemStorage::Renew($id))->toBeTrue();
    $after = FilesystemStorage::Get($id);
    expect($after['created'])->toBeGreaterThanOrEqual($before['created']);

    FilesystemStorage::Delete($id);
});

test('FilesystemStorage CleanupExpired removes expired logs only', function () {
    $data = $this->dataProp->getValue();
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $data['storage']['storageTime'] = 3600;
    $this->dataProp->setValue(null, $data);

    $idExpired = FilesystemStorage::Put('expired', null, [], null);
    $idFresh = FilesystemStorage::Put('fresh', null, [], null);

    // Backdate the expired document's created field. The .meta.json must be
    // backdated too: it is the authoritative `created` source (Renew updates
    // only the meta), so a fresh meta means "renewed" and must NOT be removed.
    $path = $this->tmpDir . '/' . $idExpired->getRaw();
    $doc = json_decode(file_get_contents($path), true);
    $doc['created'] = time() - 7200;
    file_put_contents($path, json_encode($doc));
    file_put_contents($path . '.meta.json', json_encode(['created' => time() - 7200]));

    $deleted = FilesystemStorage::CleanupExpired();
    expect($deleted)->toBe(1);

    expect(FilesystemStorage::Get($idExpired))->toBeNull();
    expect(FilesystemStorage::Get($idFresh))->not->toBeNull();

    FilesystemStorage::Delete($idFresh);
});

test('FilesystemStorage renewed log is not removed by CleanupExpired (S1 regression)', function () {
    $data = $this->dataProp->getValue();
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $data['storage']['storageTime'] = 3600;
    $this->dataProp->setValue(null, $data);

    $id = FilesystemStorage::Put('renewed', null, [], null);
    expect(FilesystemStorage::Renew($id))->toBeTrue();

    // 模拟真实场景：Put 时的 created 已滑出 TTL 窗口，但 Renew 更新过 meta
    // （meta 优先于主文档内嵌字段），因此清理必须跳过该文件
    $path = $this->tmpDir . '/' . $id->getRaw();
    $doc = json_decode(file_get_contents($path), true);
    $doc['created'] = time() - 7200;
    file_put_contents($path, json_encode($doc));

    expect(FilesystemStorage::CleanupExpired())->toBe(0);
    expect(FilesystemStorage::Get($id))->not->toBeNull();

    // meta 也滑出窗口后，文档才真正过期
    file_put_contents($path . '.meta.json', json_encode(['created' => time() - 7200]));
    expect(FilesystemStorage::CleanupExpired())->toBe(1);
    expect(FilesystemStorage::Get($id))->toBeNull();
});
