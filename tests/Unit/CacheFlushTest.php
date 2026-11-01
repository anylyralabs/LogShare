<?php

declare(strict_types=1);

use App\System\StorageHealthService;
use Tests\Mocks\RedisMock;

beforeEach(function () {
    RedisMock::reset();
    \App\Client\RedisClient::setTestConnection(new RedisMock());
});

afterEach(function () {
    \App\Client\RedisClient::setTestConnection(null);
});

test('flushCache rejects wildcard prefixes instead of flushing the DB', function () {
    \App\Cache\RedisCache::Set('log:keepme', 'v', 60);
    \App\Cache\RedisCache::Set('events:log:stream', 'v', 60);

    foreach (['*', 'all', ''] as $bad) {
        $result = StorageHealthService::flushCache($bad);
        expect($result['cleared'])->toBeFalse();
        expect($result['error'] ?? null)->not->toBeNull();
    }

    // 未授权的清理不能删掉事件流
    expect(\App\Cache\RedisCache::Exists('events:log:stream'))->toBeTrue();
});

test('flushCache clears only whitelisted prefixes', function () {
    \App\Cache\RedisCache::Set('log:a', 'v', 60);
    \App\Cache\RedisCache::Set('log:b', 'v', 60);
    \App\Cache\RedisCache::Set('events:log:stream', 'v', 60);

    $result = StorageHealthService::flushCache('log:*');

    expect($result['cleared'])->toBeTrue();
    expect($result['deletedKeys'])->toBe(2);
    expect(\App\Cache\RedisCache::Exists('log:a'))->toBeFalse();
    expect(\App\Cache\RedisCache::Exists('events:log:stream'))->toBeTrue();
});
