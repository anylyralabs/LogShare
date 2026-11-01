<?php

declare(strict_types=1);

use App\Data\Token;
use Tests\Support\InMemoryStorage;

/**
 * 去重命中时的删除凭证语义。
 *
 * 回归背景：`MariaDbStorage::Put()` 命中内容指纹时不写入新行，但 `Log::put()`
 * 仍会用本次请求的新 token 覆盖 Redis 缓存，第二个上传者因此取得删除凭证。
 */
beforeEach(function () {
    InMemoryStorage::reset();

    $this->configRef = new ReflectionClass(App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $cfg = $this->origData;
    $cfg['storage']['storageId'] = 's';
    $cfg['storage']['storages']['s'] = [
        'name' => 'InMemory',
        'class' => InMemoryStorage::class,
        'enabled' => true,
    ];
    $cfg['filter']['pre'] = [];
    $cfg['cache']['enabled'] = false;
    $this->dataProp->setValue(null, $cfg);

    App\Client\RedisClient::setTestConnection(new Tests\Mocks\RedisMock());
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    App\Client\RedisClient::setTestConnection(null);
    InMemoryStorage::reset();
});

test('deduplicated upload keeps the original deletion token', function () {
    $tokenA = new Token();
    $logA = new App\Log();
    $idA = $logA->put('identical body', $tokenA, [], 'src');

    expect($idA)->not->toBeNull();
    expect($logA->wasDeduplicated())->toBeFalse();

    $tokenB = new Token();
    $logB = new App\Log();
    $idB = $logB->put('identical body', $tokenB, [], 'src');

    expect($idB->get())->toBe($idA->get());
    expect($logB->wasDeduplicated())->toBeTrue();

    $reloaded = new App\Log(new App\Id($idA->get()));
    expect($reloaded->verifyToken((string) $tokenA->get()))->toBeTrue();

    $reloadedAgain = new App\Log(new App\Id($idA->get()));
    expect($reloadedAgain->verifyToken((string) $tokenB->get()))->toBeFalse();
});

test('deduplicated upload does not overwrite the redis cache token', function () {
    $cfg = $this->dataProp->getValue();
    $cfg['cache']['enabled'] = true;
    $cfg['cache']['ttl'] = 1800;
    $cfg['cache']['maxSize'] = 5 * 1024 * 1024;
    $this->dataProp->setValue(null, $cfg);

    $tokenA = new Token();
    $logA = new App\Log();
    $idA = $logA->put('identical cached body', $tokenA, [], 'src');
    expect($idA)->not->toBeNull();

    $cacheKey = 'log:' . $idA->getRaw();
    $cachedAfterFirst = App\Cache\RedisCache::Get($cacheKey);
    expect($cachedAfterFirst)->not->toBeNull();

    $tokenB = new Token();
    (new App\Log())->put('identical cached body', $tokenB, [], 'src');
    $cachedAfterSecond = App\Cache\RedisCache::Get($cacheKey);

    expect($cachedAfterSecond)->toBe($cachedAfterFirst);

    $decoded = json_decode((string) $cachedAfterSecond, true);
    expect($decoded['token'])->toBe(Token::hashValue((string) $tokenA->get()));
    expect($decoded['token'])->not->toBe(Token::hashValue((string) $tokenB->get()));
});
