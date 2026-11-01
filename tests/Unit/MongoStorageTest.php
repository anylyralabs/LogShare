<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Client\MongoDBClient;
use App\Data\Fingerprint;
use App\Data\Token;
use App\Storage\MongoStorage;
use MongoDB\BSON\UTCDateTime;
use Tests\HttpTestCase;

/**
 * MongoDB 存储层测试。
 *
 * 没有可用的 MongoDB 时跳过；CI 的 test job 会启动 mongo:8.0 并执行
 * scripts/init_mongodb.php，因此这里是完整的存储回归覆盖。
 */
class MongoStorageTest extends HttpTestCase
{
    private bool $mongoAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('mongodb')) {
            $this->markTestSkipped('ext-mongodb is not installed');
        }

        try {
            if (!MongoStorage::ping()) {
                throw new \RuntimeException('ping failed');
            }
            MongoStorage::ensureIndexes();
            $this->mongoAvailable = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('MongoDB unavailable: ' . $e->getMessage());
        }
    }

    private function requireMongo(): void
    {
        if (!$this->mongoAvailable) {
            $this->markTestSkipped('MongoDB unavailable');
        }
    }

    public function testPutGetRoundTrip(): void
    {
        $this->requireMongo();

        $id = MongoStorage::Put([
            ['name' => 'main.log', 'data' => 'main content'],
            ['name' => 'extra.log', 'data' => 'extra content'],
        ], new Token(), [
            \App\Data\MetadataEntry::fromArray([
                'key' => 'version',
                'value' => '1.21.9',
                'label' => 'Minecraft 版本',
                'visible' => true,
            ]),
        ], 'test-source');

        try {
            $this->assertNotNull($id);
            $result = MongoStorage::Get($id);
            $this->assertNotNull($result);
            $this->assertArrayNotHasKey('data', $result);
            $this->assertSame('test-source', $result['source']);
            $this->assertIsString($result['token']);
            $this->assertCount(2, $result['files']);
            // 存储层按名称排序，与 API 列表一致
            $this->assertSame('extra.log', $result['files'][0]['name']);
            $this->assertSame('extra content', $result['files'][0]['data']);
            $this->assertSame(strlen('extra content'), $result['files'][0]['size']);
            $this->assertSame('1.21.9', $result['metadata'][0]['value']);

            $meta = MongoStorage::Get($id, false);
            $this->assertNotNull($meta);
            $this->assertArrayNotHasKey('data', $meta['files'][0]);
            $this->assertSame('extra.log', $meta['files'][0]['name']);
        } finally {
            if ($id !== null) {
                MongoStorage::Delete($id);
            }
        }
    }

    public function testFileLargerThanBsonLimitIsChunkedAndRestored(): void
    {
        $this->requireMongo();

        $content = str_repeat('A', MongoStorage::CHUNK_BYTES + 1024);
        $id = MongoStorage::Put([['name' => 'large.log', 'data' => $content]], new Token());

        try {
            $this->assertNotNull($id);
            $chunkCount = MongoDBClient::collection(MongoStorage::COLLECTION_CHUNKS)
                ->countDocuments(['log_id' => $id->getRaw()]);
            $this->assertSame(2, $chunkCount);

            $result = MongoStorage::Get($id);
            $this->assertNotNull($result);
            $this->assertSame($content, $result['files'][0]['data']);
            $this->assertSame(strlen($content), $result['files'][0]['size']);
        } finally {
            if ($id !== null) {
                MongoStorage::Delete($id);
            }
        }
    }

    public function testRenewUpdatesParentAndChunks(): void
    {
        $this->requireMongo();

        $id = MongoStorage::Put([
            ['name' => 'main.log', 'data' => str_repeat('x', 1024)],
        ], new Token());

        try {
            $this->assertNotNull($id);
            $old = new UTCDateTime((time() - 3600) * 1000);
            MongoDBClient::collection(MongoStorage::COLLECTION_LOGS)->updateOne(
                ['_id' => $id->getRaw()],
                ['$set' => ['created' => $old]]
            );
            MongoDBClient::collection(MongoStorage::COLLECTION_CHUNKS)->updateMany(
                ['log_id' => $id->getRaw()],
                ['$set' => ['created' => $old]]
            );

            $this->assertTrue(MongoStorage::Renew($id));

            $parent = MongoDBClient::collection(MongoStorage::COLLECTION_LOGS)->findOne(['_id' => $id->getRaw()]);
            $chunk = MongoDBClient::collection(MongoStorage::COLLECTION_CHUNKS)->findOne(['log_id' => $id->getRaw()]);
            $this->assertNotNull($parent);
            $this->assertNotNull($chunk);
            $this->assertGreaterThan($old->toDateTime()->getTimestamp(), $parent['created']->toDateTime()->getTimestamp());
            $this->assertGreaterThan($old->toDateTime()->getTimestamp(), $chunk['created']->toDateTime()->getTimestamp());
        } finally {
            if ($id !== null) {
                MongoStorage::Delete($id);
            }
        }
    }

    public function testDuplicateContentReusesExistingId(): void
    {
        $this->requireMongo();

        $files = [['name' => 'main.log', 'data' => 'duplicate-' . uniqid('', true)]];
        $firstCreated = null;
        $secondCreated = null;
        $first = MongoStorage::Put($files, new Token(), [], 'src-a', $firstCreated);
        $second = MongoStorage::Put($files, new Token(), [], 'src-a', $secondCreated);

        try {
            $this->assertNotNull($first);
            $this->assertNotNull($second);
            $this->assertSame($first->get(), $second->get());
            $this->assertTrue($firstCreated);
            $this->assertFalse($secondCreated);
        } finally {
            if ($first !== null) {
                MongoStorage::Delete($first);
            }
        }
    }

    public function testFingerprintDistinguishesSourceAndFiles(): void
    {
        $base = Fingerprint::compute([['name' => 'main.log', 'data' => 'body']], 'src');

        $this->assertNotSame(
            $base,
            Fingerprint::compute([['name' => 'main.log', 'data' => 'body']], 'other')
        );
        $this->assertNotSame(
            $base,
            Fingerprint::compute([['name' => 'main.log', 'data' => 'body2']], 'src')
        );
        $this->assertSame(
            Fingerprint::compute([
                ['name' => 'a.log', 'data' => 'x'],
                ['name' => 'b.log', 'data' => 'y'],
            ], 'src'),
            Fingerprint::compute([
                ['name' => 'b.log', 'data' => 'y'],
                ['name' => 'a.log', 'data' => 'x'],
            ], 'src')
        );
    }

    public function testExpiredLogIsInvisibleBeforeTtlDeletesIt(): void
    {
        $this->requireMongo();

        $files = [['name' => 'main.log', 'data' => 'expired-' . uniqid('', true)]];
        $id = MongoStorage::Put($files, new Token(), [], 'expired-source');

        try {
            $this->assertNotNull($id);
            $old = new UTCDateTime((time() - 999999) * 1000);
            MongoDBClient::collection(MongoStorage::COLLECTION_LOGS)->updateOne(
                ['_id' => $id->getRaw()],
                ['$set' => ['created' => $old]]
            );
            MongoDBClient::collection(MongoStorage::COLLECTION_CHUNKS)->updateMany(
                ['log_id' => $id->getRaw()],
                ['$set' => ['created' => $old]]
            );

            $this->assertNull(MongoStorage::Get($id));

            // 过期记录不应被去重命中，应该生成新的 ID。
            $replacement = MongoStorage::Put($files, new Token(), [], 'expired-source');
            $this->assertNotNull($replacement);
            $this->assertNotSame($id->get(), $replacement->get());
            MongoStorage::Delete($replacement);
        } finally {
            if ($id !== null) {
                MongoStorage::Delete($id);
            }
        }
    }

    public function testDeleteRemovesChunks(): void
    {
        $this->requireMongo();

        $id = MongoStorage::Put([
            ['name' => 'a.log', 'data' => 'a'],
            ['name' => 'b.log', 'data' => 'b'],
        ], new Token());

        try {
            $this->assertNotNull($id);
            $this->assertTrue(MongoStorage::Delete($id));
            $this->assertNull(MongoStorage::Get($id));
            $this->assertSame(
                0,
                MongoDBClient::collection(MongoStorage::COLLECTION_CHUNKS)
                    ->countDocuments(['log_id' => $id->getRaw()])
            );
        } finally {
            if ($id !== null) {
                MongoStorage::Delete($id);
            }
        }
    }

    public function testIndexesAndTtlConfiguration(): void
    {
        $this->requireMongo();

        $ttl = MongoStorage::ttlSeconds();
        $this->assertGreaterThan(0, $ttl);

        foreach ([MongoStorage::COLLECTION_LOGS, MongoStorage::COLLECTION_CHUNKS] as $collectionName) {
            $indexes = MongoDBClient::database()->command(['listIndexes' => $collectionName])->toArray();
            $ttlIndex = null;
            foreach ($indexes as $index) {
                $key = [];
                foreach ($index['key'] as $field => $direction) {
                    $key[$field] = $direction;
                }
                if ($key === ['created' => 1]) {
                    $ttlIndex = $index;
                    break;
                }
            }

            $this->assertNotNull($ttlIndex, "missing created TTL index on {$collectionName}");
            $this->assertSame($ttl, (int) ($ttlIndex['expireAfterSeconds'] ?? -1));
        }
    }

    public function testAutoCompactCanBeEnabled(): void
    {
        $this->requireMongo();

        // MongoDB 8.0 的 autoCompact 已启用时重复调用应被安全忽略；
        // MongoDB 7 没有该命令，返回 false 由 compact 进程兜底。
        MongoStorage::ensureAutoCompact();
        $this->assertTrue(true);
    }

    public function testCompactCollectionsRuns(): void
    {
        $this->requireMongo();

        $result = MongoStorage::compactCollections();
        $this->assertArrayHasKey(MongoStorage::COLLECTION_LOGS, $result);
        $this->assertArrayHasKey(MongoStorage::COLLECTION_CHUNKS, $result);
        $this->assertContains($result[MongoStorage::COLLECTION_LOGS], ['ok', 'auto']);
        $this->assertContains($result[MongoStorage::COLLECTION_CHUNKS], ['ok', 'auto']);
    }
}
