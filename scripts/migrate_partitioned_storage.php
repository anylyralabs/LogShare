<?php

declare(strict_types=1);

/**
 * 一次性迁移脚本：把旧版非分区表结构重建为按 created 的天级 RANGE 分区表。
 *
 * 背景：InnoDB 在 DELETE 之后只会在 .ibd 文件内部留下空洞，磁盘占用只增不减，
 * 服务器空间最终爆满。按天分区 + DROP PARTITION 是唯一能真正归还磁盘空间的手段。
 *
 * ⚠️ 本脚本会 DROP 现有的 logs / log_files / log_metadata 三张表并重建，
 *    现有日志数据将全部丢失。执行前必须确认已备份、或已确认数据可丢弃。
 *
 * 用法：
 *   php scripts/migrate_partitioned_storage.php --yes
 *
 * 幂等：若三表已是分区结构且含 fingerprint 列，则直接退出。
 */

$root = dirname(__DIR__);
$argv = $_SERVER['argv'] ?? [];

if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "本脚本会 DROP 并重建 logs / log_files / log_metadata，现有日志数据将全部丢失。\n");
    fwrite(STDERR, "确认无误后追加 --yes 重新执行：php scripts/migrate_partitioned_storage.php --yes\n");
    exit(1);
}

// 本脚本只用 PDO 直连，不依赖应用运行时，因此不 require core.php ——
// 线上 PHP 代码构建在镜像内且可能仍是旧版本，宿主 / 临时容器挂载环境下
// vendor/ 未必存在，引入 core.php 反而会让脚本无法执行。

// 连接参数直接读环境变量，与 config/autoload/databases.php 的 DB_* 约定一致，
// 但不 require 该文件：它依赖 Hyperf 的 env() 辅助函数，需要 vendor/ 存在，
// 而线上 PHP 构建在镜像内，宿主与临时容器挂载环境下未必可用。
$env = static fn(string $key, string $default): string => (string) (getenv($key) !== false ? getenv($key) : $default);

$host = $env('DB_HOST', '127.0.0.1');
$port = (int) $env('DB_PORT', '3306');
$database = $env('DB_DATABASE', 'logshare');
$username = $env('DB_USERNAME', 'root');
$password = $env('DB_PASSWORD', '');
$charset = $env('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "无法连接数据库 {$username}@{$host}:{$port}/{$database}: {$e->getMessage()}\n");
    exit(1);
}

$tables = ['log_metadata', 'log_files', 'logs'];

/**
 * 表是否已具备分区结构。
 *
 * 判据是「存在分区 且 分区数 > 1」：全新建表只有 p_future 一个分区，
 * 此时结构虽已是分区表，但日期分区尚未铺设，仍需走一次初始化补齐。
 */
$isPartitioned = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.PARTITIONS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL'
    );
    $stmt->execute([$table]);
    return ((int) $stmt->fetchColumn()) > 1;
};

$hasFingerprint = static function (PDO $pdo): bool {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logs' AND COLUMN_NAME = 'fingerprint'"
    );
    if ($stmt === false) {
        return false;
    }
    return ((int) $stmt->fetchColumn()) > 0;
};

$notReady = array_values(array_filter(
    $tables,
    static fn(string $t): bool => !($isPartitioned)($pdo, $t) || !($hasFingerprint)($pdo)
));

if (empty($notReady)) {
    fwrite(STDOUT, "三张表已是分区结构且含 fingerprint 列，无需重建。\n");
    fwrite(STDOUT, "若日期分区尚未铺设，请执行：\n");
    fwrite(STDOUT, "  php scripts/sync_mariadb_events.php\n");
    exit(0);
}

// DROP 顺序与依赖方向一致：分区表无外键，按 子表 → 父表 顺序删除最安全
foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    fwrite(STDOUT, "已删除旧表 {$table}\n");
}

// 以 docker/mariadb-init.sql 为唯一结构来源，避免两份 schema 漂移
$initSqlPath = $root . '/docker/mariadb-init.sql';
if (!is_file($initSqlPath)) {
    fwrite(STDERR, "找不到 {$initSqlPath}\n");
    exit(1);
}

$pdo->exec((string) file_get_contents($initSqlPath));
fwrite(STDOUT, "已按 docker/mariadb-init.sql 重建三张分区表\n");

fwrite(STDOUT, "\n下一步：重新执行事件安装以铺设历史分区与定时清理任务\n");
fwrite(STDOUT, "  php scripts/sync_mariadb_events.php\n");
fwrite(STDOUT, "  docker compose --env-file .env up -d --force-recreate mariadb-events\n");