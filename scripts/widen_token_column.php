<?php

declare(strict_types=1);

/**
 * 一次性结构升级：把 `logs.token` 从 VARCHAR(64) 扩到 VARCHAR(80)。
 *
 * 背景：删除凭证的存储形态改为带前缀的 `sha256:<64 位十六进制>`（71 字符）。
 * 沿用 VARCHAR(64) 会让上传在插入阶段直接 1406 Data too long 失败。
 *
 * 该脚本只做 ALTER TABLE，不改数据、不重建分区，可重复执行：
 *   php scripts/widen_token_column.php
 *
 * 与 migrate_partitioned_storage.php 的区别：后者会 DROP 三表（数据全丢），
 * 本脚本是纯增量升级，已有部署应当执行这一个。
 */

$root = dirname(__DIR__);

$env = static fn(string $key, string $default): string => (string) (getenv($key) !== false ? getenv($key) : $default);

$host = $env('DB_HOST', '127.0.0.1');
$port = (int) $env('DB_PORT', '3306');
$database = $env('DB_DATABASE', 'logshare');
$username = $env('DB_USERNAME', 'root');
$password = $env('DB_PASSWORD', '');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "无法连接数据库 {$username}@{$host}:{$port}/{$database}: {$e->getMessage()}\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logs' AND COLUMN_NAME = 'token'"
);
$stmt->execute();
$length = $stmt->fetchColumn();

if ($length === false) {
    fwrite(STDERR, "logs.token 列不存在，请先按 docker/mariadb-init.sql 初始化表结构。\n");
    exit(1);
}

if ((int) $length >= 80) {
    fwrite(STDOUT, "logs.token 已是 VARCHAR({$length})，无需变更。\n");
    exit(0);
}

$pdo->exec('ALTER TABLE `logs` MODIFY COLUMN `token` VARCHAR(80) NULL');
fwrite(STDOUT, "已把 logs.token 从 VARCHAR({$length}) 扩至 VARCHAR(80)。\n");
