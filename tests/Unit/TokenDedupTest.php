<?php

declare(strict_types=1);

use App\Data\Token;
use App\Storage\StorageInterface;

/**
 * 去重命中时的删除凭证语义。
 *
 * 回归背景：`MariaDbStorage::Put()` 命中内容指纹时不写入新行，但 `Log::put()`
 * 仍会用本次请求的新 token 覆盖 Redis 缓存，第二个上传者因此取得删除凭证。
 * 这里用一个最小内存存储复现该路径，断言两次相同内容上传后：
 *   1. 第二次被标记为去重；
 *   2. 第一次的 token 仍然有效；
 *   3. 第二次的 token 无效。
 */
class InMemoryDedupStorage implements StorageInterface
{
    /** @var array<string, array{data: string, token: ?string}> */
    public static array $rows = [];

    public static function reset(): void
    {
        self::$rows = [];
    }

    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null, ?bool &$created = null): ?App\Id
    {
        foreach (self::$rows as $rawId => $row) {
            if ($row['data'] === $data) {
                $created = false;
                return App\Id::fromRaw('s', $rawId);
            }
        }

        $created = true;
        self::$rows['ABC123'] = ['data' => $data, 'token' => $token?->get()];
        return App\Id::fromRaw('s', 'ABC123');
    }

    public static function Update(App\Id $id, string $data, ?array $files = null): bool
    {
        return false;
    }

    public static function UpgradeToken(App\Id $id, string $normalizedToken): bool
    {
        $rawId = $id->getRaw();
        if (!isset(self::$rows[$rawId])) {
            return false;
        }
        self::$rows[$rawId]['token'] = $normalizedToken;
        return true;
    }

    public static function Get(App\Id $id, bool $includeContent = true): ?array
    {
        $row = self::$rows[$id->getRaw()] ?? null;
        if ($row === null) {
            return null;
        }
        return [
            'data' => $row['data'],
            'token' => $row['token'],
            'metadata' => [],
            'source' => 'test',
            'created' => time(),
            'files' => [],
        ];
    }

    public static function Renew(App\Id $id): bool
    {
        return true;
    }

    public static function Delete(App\Id $id): bool
    {
        unset(self::$rows[$id->getRaw()]);
        return true;
    }

    public static function List(int $limit = 20, int $offset = 0, ?string $source = null, ?int $since = null, ?int $until = null, ?string $keyword = null): array
    {
        return [];
    }

    public static function Count(?string $source = null, ?int $since = null, ?int $until = null, ?string $keyword = null): int
    {
        return count(self::$rows);
    }
}

beforeEach(function () {
    InMemoryDedupStorage::reset();

    $this->configRef = new ReflectionClass(App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $cfg = $this->origData;
    // 复用 's' 作为存储标识：App\Id 会把标识编码进首字符，重建 Id 时
    // 需要能解回同一个 storage key。
    $cfg['storage']['storageId'] = 's';
    $cfg['storage']['storages']['s'] = [
        'name' => 'InMemory',
        'class' => InMemoryDedupStorage::class,
        'enabled' => true,
    ];
    $cfg['filter']['pre'] = [];
    // 关闭缓存：本用例要验证的是「去重时不得覆盖缓存」，
    // 缓存本身用 RedisMock 单独断言，见下一条用例。
    $cfg['cache']['enabled'] = false;
    $this->dataProp->setValue(null, $cfg);

    App\Client\RedisClient::setTestConnection(new Tests\Mocks\RedisMock());
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    App\Client\RedisClient::setTestConnection(null);
    InMemoryDedupStorage::reset();
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
    (new App\Log())->put('identical cached body', $tokenA, [], 'src');
    $cachedAfterFirst = App\Cache\RedisCache::Get('log:ABC123');
    expect($cachedAfterFirst)->not->toBeNull();

    $tokenB = new Token();
    (new App\Log())->put('identical cached body', $tokenB, [], 'src');
    $cachedAfterSecond = App\Cache\RedisCache::Get('log:ABC123');

    // 第二次上传不得改写缓存：解密后的 token 仍应是 A 的哈希
    expect($cachedAfterSecond)->toBe($cachedAfterFirst);

    $decoded = json_decode((string) $cachedAfterSecond, true);
    expect($decoded['token'])->toBe(Token::hashValue((string) $tokenA->get()));
    expect($decoded['token'])->not->toBe(Token::hashValue((string) $tokenB->get()));
});
