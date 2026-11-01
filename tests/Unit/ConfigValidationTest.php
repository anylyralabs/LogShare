<?php

declare(strict_types=1);

use App\Config;

/**
 * 配置校验：这些规则守的是「默认凭据」与「误配置把服务打成 500」两类事故。
 */
beforeEach(function () {
    $this->origData = (new ReflectionClass(Config::class))->getProperty('data')->getValue();
});

afterEach(function () {
    (new ReflectionClass(Config::class))->getProperty('data')->setValue(null, $this->origData);
});

function loadConfigArray(array $overrides): void
{
    $base = require CORE_PATH . '/Config.inc.example.php';
    $merged = array_replace_recursive($base, $overrides);

    $tmp = CORE_PATH . '/tmp/cfg_' . uniqid() . '.php';
    file_put_contents($tmp, '<?php return ' . var_export($merged, true) . ';');

    try {
        Config::load($tmp);
    } finally {
        @unlink($tmp);
    }
}

test('Config::Get rejects dotted paths', function () {
    expect(fn() => Config::Get('storage.storageTime'))->toThrow(InvalidArgumentException::class);
});

test('Config::GetPath reads nested values', function () {
    loadConfigArray([]);

    expect(Config::GetPath('storage.storageTime'))->toBeInt();
    expect(Config::GetPath('storage.missing', 'fallback'))->toBe('fallback');
});

test('unknown filter class is rejected', function () {
    loadConfigArray(['filter' => ['pre' => ['App\\Filter\\DoesNotExist']]]);
})->throws(InvalidArgumentException::class, 'does not exist');

test('filter class must extend Filter', function () {
    loadConfigArray(['filter' => ['pre' => [\App\Data\Token::class]]]);
})->throws(InvalidArgumentException::class, 'must extend');

test('storage class must implement StorageInterface', function () {
    loadConfigArray([
        'storage' => [
            'storages' => [
                's' => ['name' => 'Bad', 'class' => \App\Data\Token::class, 'enabled' => true],
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, 'must implement');

test('cache class must implement CacheInterface', function () {
    loadConfigArray(['cache' => ['cacheId' => \App\Data\Token::class]]);
})->throws(InvalidArgumentException::class, 'must implement');

test('Config load drops legacy ai and github sections', function () {
    $tmp = CORE_PATH . '/tmp/legacy_config_' . uniqid() . '.php';
    file_put_contents($tmp, "<?php return ['ai' => ['apiKeys' => ['sk-leaked']], 'github' => ['tokens' => ['ghp_leaked']]];");

    try {
        Config::load($tmp);
        expect(Config::all())->not->toHaveKey('ai')->not->toHaveKey('github');
    } finally {
        @unlink($tmp);
        Config::load(CORE_PATH . '/Config.inc.php');
    }
});
