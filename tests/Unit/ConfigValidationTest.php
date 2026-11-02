<?php

declare(strict_types=1);

use App\Config;

/**
 * 配置校验：这些规则守的是「默认凭据」与「误配置把服务打成 500」两类事故。
 */
beforeEach(function () {
    $this->origData = (new ReflectionClass(Config::class))->getProperty('data')->getValue();
    $this->origMongoUri = getenv('MONGODB_URI');
    $this->origMongoDatabase = getenv('MONGODB_DATABASE');
    $this->origMongoDriver = getenv('MONGODB_DRIVER');
    // 配置校验用例要验证 in-config 的非法值，不能被 CI 注入的 MONGODB_* 环境变量覆盖。
    putenv('MONGODB_URI');
    putenv('MONGODB_DATABASE');
    putenv('MONGODB_DRIVER');
});

afterEach(function () {
    (new ReflectionClass(Config::class))->getProperty('data')->setValue(null, $this->origData);
    if ($this->origMongoUri === false) {
        putenv('MONGODB_URI');
    } else {
        putenv('MONGODB_URI=' . $this->origMongoUri);
    }
    if ($this->origMongoDatabase === false) {
        putenv('MONGODB_DATABASE');
    } else {
        putenv('MONGODB_DATABASE=' . $this->origMongoDatabase);
    }
    if ($this->origMongoDriver === false) {
        putenv('MONGODB_DRIVER');
    } else {
        putenv('MONGODB_DRIVER=' . $this->origMongoDriver);
    }
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

test('mongo uri and database are validated', function () {
    loadConfigArray(['mongo' => ['url' => 'http://127.0.0.1:27017']]);
})->throws(InvalidArgumentException::class, 'mongodb://');

test('mongo database must not be empty', function () {
    loadConfigArray(['mongo' => ['database' => '']]);
})->throws(InvalidArgumentException::class, 'mongo.database is required');

test('mongo driver must be native or gotask', function () {
    loadConfigArray(['mongo' => ['driver' => 'bogus']]);
})->throws(InvalidArgumentException::class, 'mongo.driver');

test('cache class must implement CacheInterface', function () {
    loadConfigArray(['cache' => ['cacheId' => \App\Data\Token::class]]);
})->throws(InvalidArgumentException::class, 'must implement');

test('Config load drops removed eventQueue and contentRules sections', function () {
    $tmp = CORE_PATH . '/tmp/legacy_removed_' . uniqid() . '.php';
    file_put_contents($tmp, "<?php return ['eventQueue' => ['enabled' => true], 'security' => ['contentRules' => ['keywords' => ['secret']]]];");

    try {
        Config::load($tmp);
        expect(Config::all())->not->toHaveKey('eventQueue');
        expect(Config::Get('security'))->not->toHaveKey('contentRules');
    } finally {
        @unlink($tmp);
        Config::load(CORE_PATH . '/Config.inc.php');
    }
});

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
