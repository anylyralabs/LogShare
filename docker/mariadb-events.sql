-- 由 scripts/sync_mariadb_events.php 生成，请勿手工编辑。
--
-- 清理策略：按 created 的天级 RANGE 分区直接 DROP PARTITION，而非 DELETE 逐行删除。
-- InnoDB 的 DELETE 只会在 .ibd 文件内部留下永远不会被回收的空洞，磁盘占用只增不减
-- （线上实测 48GB 表文件占满 59G 系统盘）；只有丢弃分区才能把磁盘空间真正归还
-- 操作系统。该操作是元数据级的瞬时动作，不产生逐行扫描与 purge 压力。
--
-- 分区布局在安装时一次铺满（历史 + 未来共 8+14 天），
-- 运行期只做「丢弃过期分区」，不再有任何 REORGANIZE。

-- ── 安装过程：铺设分区布局 ───────────────────────────────────────────────────
-- 历史分区是空间回收能否生效的前提：TTL 内的每一条日志都必须落在具名分区里，
-- 否则它会滞留在兜底分区 p_future 中，而 p_future 无法按子区间 DROP，最终变成
-- 永不归还磁盘的死数据。
DROP PROCEDURE IF EXISTS logshare_install_partitions;
DELIMITER $$
CREATE PROCEDURE logshare_install_partitions()
BEGIN
    DECLARE v_t VARCHAR(64);
    DECLARE v_done INT DEFAULT 0;

    DECLARE cur CURSOR FOR SELECT t FROM (
        SELECT 'logs' AS t UNION ALL SELECT 'log_files' UNION ALL SELECT 'log_metadata'
    ) x;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;
    t_loop: LOOP
        FETCH cur INTO v_t;
        IF v_done = 1 THEN
            LEAVE t_loop;
        END IF;

        -- 以「最靠近今天的历史分区」为哨兵判断是否已铺过。不用计数或全量比对：
        -- 过期分区会被正常丢弃，任何基于「数量」的判断都会在丢弃之后误判为
        -- 「未铺过」，进而触发重名冲突。
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = v_t
              AND PARTITION_NAME = 'p20261031'
        ) THEN
            -- 一次建出整段：分区上界要求严格递增，分多段执行会因中间边界缺失
            -- 而报 ERROR 1493。
            SET @ddl = CONCAT(
                'ALTER TABLE `', v_t, '` REORGANIZE PARTITION `p_future` INTO (',
                'PARTITION `p20261024` VALUES LESS THAN (1792886400), PARTITION `p20261025` VALUES LESS THAN (1792972800), PARTITION `p20261026` VALUES LESS THAN (1793059200), PARTITION `p20261027` VALUES LESS THAN (1793145600), PARTITION `p20261028` VALUES LESS THAN (1793232000), PARTITION `p20261029` VALUES LESS THAN (1793318400), PARTITION `p20261030` VALUES LESS THAN (1793404800), PARTITION `p20261031` VALUES LESS THAN (1793491200), PARTITION `p20261101` VALUES LESS THAN (1793577600), PARTITION `p20261102` VALUES LESS THAN (1793664000), PARTITION `p20261103` VALUES LESS THAN (1793750400), PARTITION `p20261104` VALUES LESS THAN (1793836800), PARTITION `p20261105` VALUES LESS THAN (1793923200), PARTITION `p20261106` VALUES LESS THAN (1794009600), PARTITION `p20261107` VALUES LESS THAN (1794096000), PARTITION `p20261108` VALUES LESS THAN (1794182400), PARTITION `p20261109` VALUES LESS THAN (1794268800), PARTITION `p20261110` VALUES LESS THAN (1794355200), PARTITION `p20261111` VALUES LESS THAN (1794441600), PARTITION `p20261112` VALUES LESS THAN (1794528000), PARTITION `p20261113` VALUES LESS THAN (1794614400), PARTITION `p20261114` VALUES LESS THAN (1794700800), PARTITION `p_future` VALUES LESS THAN (MAXVALUE))'
            );
            PREPARE stmt FROM @ddl;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END LOOP;
    CLOSE cur;
END$$
DELIMITER ;

CALL logshare_install_partitions();
DROP PROCEDURE IF EXISTS logshare_install_partitions;

-- ── 清理过程 ────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS logshare_cleanup_expired;
DELIMITER $$
CREATE PROCEDURE logshare_cleanup_expired(IN p_ttl INT)
BEGIN
    -- ⚠ 必须声明为 SIGNED：information_schema.PARTITIONS.PARTITION_DESCRIPTION
    -- 是无符号列，与有符号 cutoff 比较时会发生类型提升，负 cutoff 被提升为巨大
    -- 无符号数，导致「没有任何分区可丢弃」且不报错，清理静默失效、磁盘持续膨胀。
    DECLARE v_cutoff BIGINT SIGNED DEFAULT UNIX_TIMESTAMP() - p_ttl;
    DECLARE v_table VARCHAR(64);
    DECLARE v_name VARCHAR(64);
    DECLARE v_done INT DEFAULT 0;

    -- 每张表按各自的边界独立判定，三表互不依赖。这不只是简化，更是正确性要求：
    -- 若改为「跨三表取同名分区的统一上界」，一旦某张表缺该分区，DROP 就会因
    -- 「分区在另一张表不存在」报 ERROR 1507，整轮清理作废。各表自治则天然容忍
    -- 错位，每张表只丢弃自己确实拥有的过期分区。
    SET v_table = 'logs';
    WHILE v_table IS NOT NULL DO
        SET v_done = 0;
        drop_loop: LOOP
            -- 逐个执行并设上限：DDL 之后同会话内的 information_schema 可能返回
            -- 陈旧视图，批量拼接的分区名列表会混入已丢弃的分区，使整条 ALTER
            -- 语法失败（ERROR 1064）；上限则防止陈旧视图下的无界循环卡死
            -- Event Scheduler。
            SET v_done = v_done + 1;
            IF v_done > 400 THEN
                LEAVE drop_loop;
            END IF;

            SELECT PARTITION_NAME INTO v_name
            FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = v_table
              AND PARTITION_NAME <> 'p_future'
              AND PARTITION_DESCRIPTION REGEXP '^[0-9]+$'
              -- 必须转 SIGNED，理由见 v_cutoff 的声明处
              AND CAST(PARTITION_DESCRIPTION AS SIGNED) <= v_cutoff
            ORDER BY PARTITION_ORDINAL_POSITION
            LIMIT 1;

            IF v_name IS NULL THEN
                LEAVE drop_loop;
            END IF;

            SET @ddl = CONCAT('ALTER TABLE `', v_table, '` DROP PARTITION `', v_name, '`');
            PREPARE stmt FROM @ddl;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END LOOP;

        SET v_table = CASE v_table
            WHEN 'logs' THEN 'log_files'
            WHEN 'log_files' THEN 'log_metadata'
            ELSE NULL
        END;
    END WHILE;

    -- 兜底回收滞留在 p_future 中的过期行。兜底分区无法按子区间 DROP，只能逐行
    -- DELETE；这批数据通常极少（只有分区维护中断期间写入的才会滞留），不构成
    -- 空间大头。先删子表再删父表：分区表不支持外键，级联删除必须显式完成。
    DELETE FROM log_metadata WHERE created < v_cutoff AND log_id IN (SELECT id FROM (SELECT id, created FROM logs WHERE created < v_cutoff) AS expired);
    DELETE FROM log_files WHERE created < v_cutoff AND log_id IN (SELECT id FROM (SELECT id, created FROM logs WHERE created < v_cutoff) AS expired);
    DELETE FROM logs WHERE created < v_cutoff;
END$$
DELIMITER ;

DROP EVENT IF EXISTS cleanup_expired_logs;
CREATE EVENT cleanup_expired_logs
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP + INTERVAL 1 HOUR
DO CALL logshare_cleanup_expired(604800);
