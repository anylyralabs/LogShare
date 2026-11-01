<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Token;
use App\Storage\MariaDbStorage;
use Hyperf\DbConnection\Db;
use Tests\HttpTestCase;

class MariaDbStorageTest extends HttpTestCase
{
    private bool $dbAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Db::statement('SELECT 1');
            $this->ensureSchema();
            $this->dbAvailable = true;
        } catch (\Throwable $e) {
            $this->dbAvailable = false;
        }
    }

    /**
 * 建立与 docker/mariadb-init.sql 一致的分区表结构。
 *
 * 三张表均按 created 做天级 RANGE 分区，主键包含分区键，且不声明外键
 * （MariaDB 分区表不支持外键，级联删除由 MariaDbStorage 显式完成）。
 */
private function ensureSchema(): void
    {
        Db::statement("CREATE TABLE IF NOT EXISTS logs (
            id CHAR(6) NOT NULL,
            data LONGTEXT NOT NULL,
            token VARCHAR(80) NULL,
            source VARCHAR(64) NULL,
            created INT UNSIGNED NOT NULL,
            fingerprint CHAR(32) NULL,
            PRIMARY KEY (id, created),
            KEY idx_created (created),
            KEY idx_fingerprint (fingerprint)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        PARTITION BY RANGE (created) (PARTITION p_future VALUES LESS THAN (MAXVALUE))");

        Db::statement("CREATE TABLE IF NOT EXISTS log_files (
            log_id CHAR(6) NOT NULL,
            name VARCHAR(512) NOT NULL,
            data LONGTEXT NOT NULL,
            size INT UNSIGNED NOT NULL,
            created INT UNSIGNED NOT NULL,
            PRIMARY KEY (log_id, name, created),
            KEY idx_log_files_created (created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        PARTITION BY RANGE (created) (PARTITION p_future VALUES LESS THAN (MAXVALUE))");

        Db::statement("CREATE TABLE IF NOT EXISTS log_metadata (
            log_id CHAR(6) NOT NULL,
            `key` VARCHAR(64) NOT NULL,
            `value` TEXT NULL,
            `label` VARCHAR(128) NULL,
            `visible` TINYINT(1) NOT NULL DEFAULT 1,
            created INT UNSIGNED NOT NULL,
            PRIMARY KEY (log_id, `key`, created),
            KEY idx_log_metadata_created (created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        PARTITION BY RANGE (created) (PARTITION p_future VALUES LESS THAN (MAXVALUE))");
    }

    private function requireDb(): void
    {
        if (!$this->dbAvailable) {
            $this->markTestSkipped('MariaDB unavailable — skipping MariaDbStorage test');
        }
    }

    public function testPutGetRoundTrip(): void
    {
        $this->requireDb();

        $id = MariaDbStorage::Put('main content', new Token(), [], 'test', [
            ['name' => 'extra.log', 'data' => 'extra content'],
        ]);

        expect($id)->toBeInstanceOf(\App\Id::class);

        $result = MariaDbStorage::Get($id);
        expect($result)->not->toBeNull();
        expect($result['data'])->toBe('main content');
        expect($result['source'])->toBe('test');
        expect($result['token'])->toBeString();
        expect($result['files'])->toHaveCount(1);
        expect($result['files'][0]['name'])->toBe('extra.log');
        expect($result['files'][0]['data'])->toBe('extra content');
        expect($result['files'][0]['size'])->toBe(strlen('extra content'));

        $meta = MariaDbStorage::Get($id, false);
        expect($meta['files'][0])->toHaveKeys(['name', 'size']);
        expect($meta['files'][0])->not->toHaveKey('data');

        expect(MariaDbStorage::Delete($id))->toBeTrue();
        expect(MariaDbStorage::Get($id))->toBeNull();
    }

    public function testRenewUpdatesCreated(): void
    {
        $this->requireDb();

        $id = MariaDbStorage::Put('renew me', new Token());
        $before = MariaDbStorage::Get($id);
        expect($before['created'])->toBeInt();

        sleep(1);
        expect(MariaDbStorage::Renew($id))->toBeTrue();
        $after = MariaDbStorage::Get($id);
        expect($after['created'])->toBeGreaterThanOrEqual($before['created']);

        MariaDbStorage::Delete($id);
    }

    /**
     * 相同内容的重复上传必须复用既有 ID，而不是产生新行。
     *
     * 这是空间控制的一部分：客户端重试、重复提交是日志表膨胀的主要来源之一。
     */
    public function testDuplicateContentReusesExistingId(): void
    {
        $this->requireDb();

        $first = MariaDbStorage::Put('duplicate content', new Token(), [], 'src-a');
        $second = MariaDbStorage::Put('duplicate content', new Token(), [], 'src-a');

        expect($second->get())->toBe($first->get());

        $count = Db::table('logs')
            ->where('fingerprint', MariaDbStorage::fingerprint('duplicate content', null, 'src-a'))
            ->count();
        expect((int) $count)->toBe(1);

        MariaDbStorage::Delete($first);
    }

    /**
     * 指纹判定范围为「正文 + 附加文件 + source」，任一项不同都应视为不同日志。
     */
    public function testFingerprintDistinguishesContentSourceAndFiles(): void
    {
        $this->requireDb();

        $base = MariaDbStorage::fingerprint('body', null, 'src');

        // source 不同 → 指纹不同
        expect(MariaDbStorage::fingerprint('body', null, 'other'))->not->toBe($base);
        // 正文不同 → 指纹不同
        expect(MariaDbStorage::fingerprint('body2', null, 'src'))->not->toBe($base);
        // 附加文件不同 → 指纹不同
        $withFile = MariaDbStorage::fingerprint('body', [['name' => 'a.log', 'data' => 'x']], 'src');
        expect($withFile)->not->toBe($base);

        // 附件顺序不同但内容相同 → 指纹相同（排序保证顺序无关）
        $f1 = MariaDbStorage::fingerprint('body', [
            ['name' => 'a.log', 'data' => 'x'],
            ['name' => 'b.log', 'data' => 'y'],
        ], 'src');
        $f2 = MariaDbStorage::fingerprint('body', [
            ['name' => 'b.log', 'data' => 'y'],
            ['name' => 'a.log', 'data' => 'x'],
        ], 'src');
        expect($f2)->toBe($f1);
    }

    /**
     * 三张表必须存在具名日期分区与 p_future 兜底分区：
     * 缺少具名分区则过期日志无法被 DROP 回收，缺少兜底分区则新写入直接失败。
     */
    public function testAllLogTablesArePartitionedWithFallback(): void
    {
        $this->requireDb();

        // 安装期才铺具名分区，单元测试里按同样手法（REORGANIZE 兜底分区）
        // 自建一个，避免依赖已删除的运行时补齐方法。
        foreach (\App\System\LogPartitionManager::TABLES as $table) {
            $exists = Db::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.PARTITIONS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME = ?',
                [$table, 'p_test']
            );
            if ((int) ($exists->c ?? 0) === 0) {
                $boundary = (int) (floor((time() + 86400) / 86400) * 86400);
                Db::statement(
                    "ALTER TABLE `{$table}` REORGANIZE PARTITION `p_future` INTO ("
                    . "PARTITION `p_test` VALUES LESS THAN ({$boundary}), "
                    . 'PARTITION `p_future` VALUES LESS THAN (MAXVALUE))'
                );
            }
        }

        // DDL 之后 information_schema 在同一连接内可能返回陈旧视图，
        // 用独立 PDO 连接读取可拿到最新分区布局。
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            (string) (getenv('DB_HOST') ?: '127.0.0.1'),
            (string) (getenv('DB_PORT') ?: '3306'),
            (string) (getenv('DB_DATABASE') ?: 'logshare')
        );
        $pdo = new \PDO($dsn, (string) (getenv('DB_USERNAME') ?: 'root'), (string) (getenv('DB_PASSWORD') ?: ''));
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        foreach (\App\System\LogPartitionManager::TABLES as $table) {
            $stmt = $pdo->prepare(
                'SELECT PARTITION_NAME FROM information_schema.PARTITIONS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL'
            );
            $stmt->execute([$table]);
            $names = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            expect($names)->toContain('p_future');
            expect(count($names))->toBeGreaterThan(1, "{$table} must have named day partitions");
        }
    }

    /**
     * 子表的 created 必须与父表一致：分区回收按同一 created 边界进行，
     * 两者错位会导致父表分区被丢弃后子表残留成为永久无法回收的孤儿数据。
     */
    public function testChildTablesShareParentCreatedValue(): void
    {
        $this->requireDb();

        $id = MariaDbStorage::Put('parent sync', new Token(), [
            \App\Data\MetadataEntry::fromArray(['key' => 'version', 'value' => '1.20.1', 'label' => 'v', 'visible' => true]),
        ], 'sync-src', [
            ['name' => 'extra.log', 'data' => 'extra body'],
        ]);

        try {
            $parentCreated = (int) Db::table('logs')->where('id', $id->getRaw())->value('created');

            $fileCreated = Db::table('log_files')->where('log_id', $id->getRaw())->value('created');
            expect((int) $fileCreated)->toBe($parentCreated);

            $metaCreated = Db::table('log_metadata')->where('log_id', $id->getRaw())->value('created');
            expect((int) $metaCreated)->toBe($parentCreated);
        } finally {
            MariaDbStorage::Delete($id);
        }
    }

    /**
     * Renew 必须同步推进子表的 created，否则父子行会落入不同分区。
     */
    public function testRenewKeepsChildTablesInSync(): void
    {
        $this->requireDb();

        $id = MariaDbStorage::Put('renew sync', new Token(), [
            \App\Data\MetadataEntry::fromArray(['key' => 'version', 'value' => '1.20.1', 'label' => 'v', 'visible' => true]),
        ], 'renew-src', [
            ['name' => 'extra.log', 'data' => 'extra body'],
        ]);

        try {
            Db::table('logs')->where('id', $id->getRaw())->update(['created' => time() - 99999999]);
            Db::table('log_files')->where('log_id', $id->getRaw())->update(['created' => time() - 99999999]);
            Db::table('log_metadata')->where('log_id', $id->getRaw())->update(['created' => time() - 99999999]);

            expect(MariaDbStorage::Renew($id))->toBeTrue();

            $parentCreated = (int) Db::table('logs')->where('id', $id->getRaw())->value('created');
            expect((int) Db::table('log_files')->where('log_id', $id->getRaw())->value('created'))->toBe($parentCreated);
            expect((int) Db::table('log_metadata')->where('log_id', $id->getRaw())->value('created'))->toBe($parentCreated);
        } finally {
            MariaDbStorage::Delete($id);
        }
    }

    public function testCleanupExpiredRemovesOnlyExpired(): void
    {
        $this->requireDb();

        $expired = MariaDbStorage::Put('expired', new Token());
        $fresh = MariaDbStorage::Put('fresh', new Token());
        Db::table('logs')->where('id', $expired->getRaw())->update(['created' => time() - 99999999]);

        $deleted = MariaDbStorage::CleanupExpired();
        expect($deleted)->toBeGreaterThanOrEqual(1);
        expect(MariaDbStorage::Get($expired))->toBeNull();
        expect(MariaDbStorage::Get($fresh))->not->toBeNull();

        MariaDbStorage::Delete($fresh);
    }

    public function testRenewedLogIsNotRemovedAsExpired(): void
    {
        $this->requireDb();

        $id = MariaDbStorage::Put('renewed', new Token());
        Db::table('logs')->where('id', $id->getRaw())->update(['created' => time() - 99999999]);
        expect(MariaDbStorage::Renew($id))->toBeTrue();

        MariaDbStorage::CleanupExpired();
        expect(MariaDbStorage::Get($id))->not->toBeNull();

        MariaDbStorage::Delete($id);
    }

    public function testCleanupEventIsEnabledWhenConfigured(): void
    {
        $this->requireDb();

        # MariaDB 的 information_schema.EVENTS 列名为 INTERVAL_VALUE/INTERVAL_FIELD
        # （MySQL 风格的 EVENT_INTERVAL_* 在 MariaDB 中不存在）
        $event = Db::selectOne("SELECT EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = 'cleanup_expired_logs'");
        if ($event === null) {
            $this->markTestSkipped('cleanup_expired_logs event is not installed');
        }

        expect($event->STATUS)->toBe('ENABLED');
        expect($event->INTERVAL_VALUE)->toBe('1');
        expect($event->INTERVAL_FIELD)->toBe('HOUR');
    }

    public function testListAndCountFilters(): void
    {
        $this->requireDb();

        $prefix = 'mdb_test_' . uniqid();
        $id1 = MariaDbStorage::Put('log content 1', new Token(), [], "{$prefix}_client");
        $id2 = MariaDbStorage::Put('log content 2', new Token(), [], "{$prefix}_server");
        $id3 = MariaDbStorage::Put('log content 3', new Token(), [], '未指定');

        try {
            $totalCount = MariaDbStorage::Count();
            expect($totalCount)->toBeGreaterThanOrEqual(3);

            // Filter by source
            $clientCount = MariaDbStorage::Count("{$prefix}_client");
            expect($clientCount)->toBe(1);

            $clientList = MariaDbStorage::List(10, 0, "{$prefix}_client");
            expect($clientList)->toHaveCount(1);
            expect($clientList[0]['id'])->toBe($id1->get());
            expect($clientList[0]['source'])->toBe("{$prefix}_client");

            // Filter by '未指定'
            $unspecList = MariaDbStorage::List(10, 0, '未指定');
            expect(count($unspecList))->toBeGreaterThanOrEqual(1);

            // Filter by keyword (ID matching)
            $kwList = MariaDbStorage::List(10, 0, null, null, null, $id2->get());
            expect($kwList)->toHaveCount(1);
            expect($kwList[0]['id'])->toBe($id2->get());

            // Filter by time range
            $now = time();
            $timeCount = MariaDbStorage::Count(null, $now - 60, $now + 60);
            expect($timeCount)->toBeGreaterThanOrEqual(3);
        } finally {
            MariaDbStorage::Delete($id1);
            MariaDbStorage::Delete($id2);
            MariaDbStorage::Delete($id3);
        }
    }

    public function testMetadataSerializationAndRoundTrip(): void
    {
        $this->requireDb();

        $entries = [
            \App\Data\MetadataEntry::fromArray(['key' => 'version', 'value' => '1.20.1', 'label' => 'MC Version', 'visible' => true]),
            \App\Data\MetadataEntry::fromArray(['key' => 'mods', 'value' => ['fabric', 'sodium'], 'label' => 'Installed Mods', 'visible' => true]),
            \App\Data\MetadataEntry::fromArray(['key' => 'active', 'value' => true, 'label' => 'Active Status', 'visible' => false]),
        ];

        $id = MariaDbStorage::Put('log with metadata', new Token(), $entries);
        try {
            $result = MariaDbStorage::Get($id);
            expect($result)->not->toBeNull();
            expect($result['metadata'])->toBeArray();
            expect(count($result['metadata']))->toBe(3);

            $metaByKey = [];
            foreach ($result['metadata'] as $meta) {
                $metaByKey[$meta['key']] = $meta;
            }

            expect($metaByKey['version']['value'])->toBe('1.20.1');
            expect($metaByKey['version']['label'])->toBe('MC Version');
            expect($metaByKey['version']['visible'])->toBeTrue();

            expect($metaByKey['mods']['value'])->toBe(['fabric', 'sodium']);
            expect($metaByKey['active']['value'])->toBeTrue();
            expect($metaByKey['active']['visible'])->toBeFalse();
        } finally {
            MariaDbStorage::Delete($id);
        }
    }
}

