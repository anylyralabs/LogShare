<?php

declare(strict_types=1);

namespace App\System;

use App\Config;
use App\Storage\MongoStorage;
use Hyperf\Process\AbstractProcess;

/**
 * MongoDB 磁盘回收兜底进程。
 *
 * MongoDB 8.0 的 autoCompact 能持续压缩；MongoDB 7.x 没有该命令，TTL 删除后
 * WiredTiger 只复用空闲页，不能保证把空间还给 OS。本进程按固定间隔执行
 * compact，保证磁盘占用自动收缩。
 *
 * 进程运行在独立子进程中，compact 的阻塞不会影响 HTTP worker。
 */
final class MongoCompactProcess extends AbstractProcess
{
    public string $name = 'mongo-compact';

    public int $nums = 1;

    public bool $enableCoroutine = false;

    public function isEnable($server): bool
    {
        return MongoStorage::compactionEnabled();
    }

    public function handle(): void
    {
        $config = Config::Get('mongo');
        if (($config['compactionEnabled'] ?? true) !== true) {
            return;
        }

        $initialDelay = max(0, (int) ($config['compactInitialDelay'] ?? 120));
        $interval = max(300, (int) ($config['compactInterval'] ?? 6 * 60 * 60));

        if ($initialDelay > 0) {
            sleep($initialDelay);
        }

        while (MongoStorage::compactionEnabled()) {
            if (!MongoStorage::autoCompactAvailable()) {
                MongoStorage::compactCollections();
            }
            sleep($interval);
        }
    }
}
