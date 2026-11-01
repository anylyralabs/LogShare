-- LogShare MariaDB 表结构（首次启动时由 mariadb docker-entrypoint-initdb.d 自动执行）
--
-- 设计要点（详见 AGENTS.md「分区与去重架构」）：
--   1. 三张表均按 created 做天级 RANGE 分区。InnoDB 在 DELETE 之后只会把空闲页
--      留在 .ibd 文件内部等待复用，永远不会把空间归还操作系统，磁盘占用单调
--      递增直至爆满。唯一能立即归还空间的做法是 DROP PARTITION（直接删除
--      分区对应的 .ibd 文件）。分区清理由 MariaDB Event cleanup_expired_logs
--      与 MariaDbStorage::CleanupExpired() 共同驱动。
--   2. MariaDB 分区表不支持外键（ERROR 1506），原有的 ON DELETE CASCADE
--      约束全部移除，级联删除改由应用层（MariaDbStorage::Delete /
--      CleanupExpired）显式保证。
--   3. 分区键必须包含在所有唯一键中，因此主键都追加了 created。
-- 4. 保留 p_future 兜底分区（VALUES LESS THAN MAXVALUE）：它承接所有尚未被
--      日期分区覆盖的行，保证写入永不因「Table has no partition for value」
--      失败；日期分区由事件安装过程铺设（见 scripts/sync_mariadb_events.php），
--      运行期不补齐，只做丢弃。
-- 5. 迁移提示：本结构与旧结构不兼容（移除 logs.data，日志只由 log_files 承载）。
--      旧库需清空重建，新表结构以本文件为准。
--
-- 注：DDL 不接受表达式，分区边界必须是编译期常量，因此这里只声明兜底分区。
-- 首轮事件（cleanup_expired_logs）会为「今天起未来 14 天」建立实际日分区；
-- TTL 窗口内的历史数据即使尚无具名分区，也会由事件中的兜底 DELETE 正常回收。

CREATE TABLE IF NOT EXISTS logs (
    id CHAR(6) NOT NULL,
    -- 删除凭证的存储形态是 `sha256:<64 位十六进制>`（71 字符）。
    -- 列宽必须留足，否则插入会以 1406 Data too long 失败。
    token VARCHAR(80) NULL,
    source VARCHAR(64) NULL,
    created INT UNSIGNED NOT NULL,
    fingerprint CHAR(32) NULL,
    PRIMARY KEY (id, created),
    KEY idx_created (created),
    KEY idx_fingerprint (fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (created) (
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE IF NOT EXISTS log_files (
    log_id CHAR(6) NOT NULL,
    name VARCHAR(512) NOT NULL,
    data LONGTEXT NOT NULL,
    size INT UNSIGNED NOT NULL,
    created INT UNSIGNED NOT NULL,
    PRIMARY KEY (log_id, name, created),
    KEY idx_log_files_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (created) (
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE IF NOT EXISTS log_metadata (
    log_id CHAR(6) NOT NULL,
    `key` VARCHAR(64) NOT NULL,
    `value` TEXT NULL,
    `label` VARCHAR(128) NULL,
    `visible` TINYINT(1) NOT NULL DEFAULT 1,
    created INT UNSIGNED NOT NULL,
    PRIMARY KEY (log_id, `key`, created),
    KEY idx_log_metadata_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (created) (
    PARTITION p_future VALUES LESS THAN (MAXVALUE)
);