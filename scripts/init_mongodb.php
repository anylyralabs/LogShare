<?php

declare(strict_types=1);

/**
 * 初始化 MongoDB 索引、TTL 与后台自动压缩。
 *
 * 由 Hyperf 容器启动脚本在服务启动前执行，也可手动运行：
 *   php scripts/init_mongodb.php
 *
 * 该脚本幂等：storageTime 变化后重新执行即可用 collMod 更新 TTL。
 */

require_once dirname(__DIR__) . '/core.php';

use App\Storage\MongoStorage;

if (!MongoStorage::ping()) {
    fwrite(STDERR, "MongoDB is not reachable; check mongo.url / MONGODB_URI.\n");
    exit(1);
}

MongoStorage::ensureIndexes();
$autoCompact = MongoStorage::ensureAutoCompact();

$storage = \App\Config::Get('storage');
$storageTime = (int) ($storage['storageTime'] ?? 604800);
$compactMode = $autoCompact ? 'autoCompact=on' : 'autoCompact=unsupported; scheduled compact fallback=on';

fwrite(STDOUT, "MongoDB indexes/TTL ready (storageTime={$storageTime}s, ttl=" . MongoStorage::ttlSeconds() . "s, {$compactMode})\n");
