<?php

declare(strict_types=1);

namespace App\System;

use Hyperf\DbConnection\Db;

/**
 * 日志表分区管理。
 *
 * 背景：InnoDB 在 DELETE 后只会把空闲页留在 .ibd 文件内部等待复用，
 * 永远不会把空间归还操作系统，磁盘占用呈单调递增，最终爆满。
 * 唯一能立即归还空间的做法是 DROP PARTITION（删除 .ibd 文件本身）。
 *
 * 分区布局由安装期一次铺满（scripts/sync_mariadb_events.php 生成
 * docker/mariadb-events.sql，其中的安装过程铺好历史与未来共约 22 个日分区），
 * 运行期只负责丢弃，不做任何 REORGANIZE。这样取舍是被线上事故逼出来的：
 * 运行期补齐需要 REORGANIZE，而 REORGANIZE 会与并发的 DROP 相互干扰
 * （ERROR 1517 / 1507 / 1493），且 DDL 之后同会话内的 information_schema
 * 返回陈旧视图，使「查一次改一次」的循环无法可靠收敛。
 *
 * 因此本类只承担两件事：
 *   - dropExpiredPartitions() 丢弃已过期的整日分区，真正归还磁盘空间；
 *   - cleanupStrays() 兜底回收滞留在兜底分区里的过期行。
 *
 * 跨天后的分区延伸由重新生成 SQL 并重装事件完成，不在本类职责内。
 *
 * 分区表约束（MariaDB 硬性限制，违反即 ERROR 1506）：
 *   - 分区表不支持外键，级联删除必须由应用层显式完成；
 *   - 所有唯一键（含主键）都必须包含分区键，因此主键形如 (id, created)。
 *
 * 三张表使用一致的分区边界；即便因故错位，本类也是各表自治地丢弃自己确实
 * 拥有的过期分区，不会因「某张表缺该分区」而报错作废整轮清理。
 */
final class LogPartitionManager
{
    /** 参与分区的日志表，顺序与分区维护顺序一致 */
    public const TABLES = ['logs', 'log_files', 'log_metadata'];

    /** 表名常量：与 TABLES 对应，供本类内部的查询拼接使用 */
    private const TABLE_LOGS = 'logs';
    private const TABLE_FILES = 'log_files';
    private const TABLE_METADATA = 'log_metadata';

    /** 兜底分区名：承接所有尚未被具体日期分区覆盖的行，写入永不失败 */
    private const FALLBACK = 'p_future';

    /** 单次清理允许丢弃的分区数上限，防止一次 DROP 过于激进 */
    private const MAX_DROP_PER_RUN = 60;

    /** 兜底分区存在性检查的进程内缓存时长（秒）：避免每次清理都扫 information_schema */
    private const FALLBACK_CHECK_TTL = 300;


    /**
     * 分区名：p + 日期（UTC），便于按名字直接定位某个整日的分区。
     */
    public static function partitionName(int $boundary): string
    {
        return 'p' . gmdate('Ymd', $boundary);
    }


    /**
     * 按行回收滞留在兜底分区中的过期数据。
     *
     * 兜底分区无法按子区间 DROP，只能逐行删除。这批数据通常极少：只有分区
     * 维护中断（磁盘满 / ALTER 失败）期间写入的过期日志才会滞留于此。真正
     * 的空间大头由 DROP PARTITION 回收，不受此路径影响。
     *
     * 分区表无外键，级联删除必须显式完成，且顺序为「先子表后父表」。
     *
     * @return int 删除的父表行数
     */
    public static function cleanupStrays(int $expireBefore, int $limit = 500): int
    {
        try {
            return Db::transaction(function () use ($expireBefore, $limit) {
                $expiredIds = Db::table(self::TABLE_LOGS)
                    ->where('created', '<', $expireBefore)
                    ->limit($limit)
                    ->pluck('id')
                    ->all();

                if (empty($expiredIds)) {
                    return 0;
                }

                Db::table(self::TABLE_METADATA)->whereIn('log_id', $expiredIds)->delete();
                Db::table(self::TABLE_FILES)->whereIn('log_id', $expiredIds)->delete();

                return Db::table(self::TABLE_LOGS)->whereIn('id', $expiredIds)->delete();
            });
        } catch (\Throwable $e) {
            \App\Syslog::error('LogPartitionManager', 'stray cleanup failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 丢弃覆盖范围已完全早于过期时刻的分区。
     *
     * 只处理边界严格小于 expireBefore 的分区：落在边界当天或之后的分区
     * 仍可能含有未过期数据，绝不能丢弃。
     *
     * @return int 实际丢弃的分区数（按表计）
     */
    public static function dropExpiredPartitions(int $expireBefore): int
    {
        $dropped = 0;
        $limit = self::MAX_DROP_PER_RUN;
        $existing = self::existingPartitions();

        foreach (self::TABLES as $table) {
            foreach (self::droppablePartitionNames($existing[$table] ?? [], $expireBefore) as $name) {
                if ($limit-- <= 0) {
                    return $dropped;
                }
                // 只统计真正删掉的分区：失败却被计入会让面板谎报回收成功，
                // 同时也掩盖「父表已删、子表仍在」的孤儿行来源。
                if (self::dropPartition($table, $name)) {
                    $dropped++;
                }
            }
        }

        return $dropped;
    }

    /**
     * 兜底分区必须始终存在，否则超出最后一个边界的写入会以
     * 「Table has no partition for value」直接失败。
     */
    public static function ensureFallbackPartition(): void
    {
        static $checkedAt = 0;
        if ($checkedAt !== 0 && (time() - $checkedAt) < self::FALLBACK_CHECK_TTL) {
            return;
        }
        $checkedAt = time();

        $existing = self::existingPartitions();

        foreach (self::TABLES as $table) {
            if (isset($existing[$table][self::FALLBACK])) {
                continue;
            }
            try {
                Db::statement("ALTER TABLE `{$table}` ADD PARTITION (PARTITION `" . self::FALLBACK . "` VALUES LESS THAN (MAXVALUE))");
            } catch (\Throwable $e) {
                \App\Syslog::error('LogPartitionManager', "failed to add fallback partition on {$table}: " . $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, array<string, int>> 表名 => 分区名 => 边界值
     */
    private static function existingPartitions(): array
    {
        $rows = Db::select(
            'SELECT TABLE_NAME as t, PARTITION_NAME as p, PARTITION_DESCRIPTION as d
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND PARTITION_NAME IS NOT NULL
               AND TABLE_NAME IN ("logs","log_files","log_metadata")'
        );

        $result = [];
        foreach ($rows as $row) {
            $table = (string) $row->t;
            $description = strtoupper(trim((string) $row->d));
            // MAXVALUE 不是数值边界，记为 PHP_INT_MAX 表示「无上界」
            $result[$table][(string) $row->p] = ($description === 'MAXVALUE') ? PHP_INT_MAX : (int) $description;
        }

        return $result;
    }


    /**
     * @param array<string, int> $partitions 表的分区名 => 上界
     * @return string[] 可安全丢弃的分区名（已按边界升序）
     */
    private static function droppablePartitionNames(array $partitions, int $expireBefore): array
    {
        $candidates = [];
        foreach ($partitions as $name => $boundary) {
            if ($name === self::FALLBACK) {
                // 兜底分区承载所有未覆盖的行，任何情况下都不能丢弃
                continue;
            }
            if ($boundary <= $expireBefore) {
                $candidates[$name] = $boundary;
            }
        }

        asort($candidates);

        return array_keys($candidates);
    }

    private static function dropPartition(string $table, string $partitionName): bool
    {
        try {
            Db::statement("ALTER TABLE `{$table}` DROP PARTITION `{$partitionName}`");
            return true;
        } catch (\Throwable $e) {
            \App\Syslog::error('LogPartitionManager', "failed to drop {$table}.{$partitionName}: " . $e->getMessage());
            return false;
        }
    }
}