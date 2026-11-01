<?php

/**
 * LogShare Configuration
 *
 * Copy this file to Config.inc.php and fill in your values.
 * Config.inc.php is gitignored.
 */

return [

    /* ─── Storage ─────────────────────────────────────────── */
    'storage' => [
        'storages' => [
            's' => ['name' => 'MariaDB',     'class' => '\\App\\Storage\\MariaDbStorage',      'enabled' => true],
        ],
        'storageId' => 's',
        // 日志保留时长（秒）。MariaDB 后端按 created 的天级 RANGE 分区存储，
        // 到期由 MariaDB Event cleanup_expired_logs 丢弃整日分区，
        // 磁盘空间会被真正归还操作系统（DELETE 只会留下永不回收的空洞）。
        // 修改后需执行 php scripts/sync_mariadb_events.php 并重启 mariadb-events 容器生效。
        'storageTime' => 7 * 24 * 60 * 60,
        'maxLength' => 10 * 1024 * 1024,
        'maxLines' => 50_000,
        'uploadFiles' => [
            'maxFiles' => 200,
            'maxTotalBytes' => 12 * 1024 * 1024,
        ],
    ],

    /* ─── Cache (Redis) ──────────────────────────────────── */
    'cache' => [
        'cacheId' => '\\App\\Cache\\RedisCache',
        'enabled' => true,
        // password / database / username 为可选项：Redis 开启 requirepass 或 ACL 时填 password（ACL 用户填 username）
        'redis' => ['host' => 'redis', 'port' => 6379, 'username' => '', 'password' => '${REDIS_PASSWORD}', 'database' => 0],
        'ttl' => 30 * 60,
        'maxSize' => 5 * 1024 * 1024,
    ],

    /* ─── ID ───────────────────────────────────────────────── */
    'id' => [
        'characters' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
        'length' => 6,
    ],

    /* ─── Filter ───────────────────────────────────────────── */
    'filter' => [
        'pre' => [
            '\\App\\Filter\\EncodingFilter',
            '\\App\\Filter\\TrimFilter',
            '\\App\\Filter\\LimitBytesFilter',
            '\\App\\Filter\\LimitLinesFilter',
            '\\App\\Filter\\IPv4Filter',
            '\\App\\Filter\\IPv6Filter',
            '\\App\\Filter\\IPv6ShortFilter',
            '\\App\\Filter\\UuidFilter',
            '\\App\\Filter\\XuidFilter',
            '\\App\\Filter\\SessionTokenFilter',
            '\\App\\Filter\\ClientIdFilter',
            '\\App\\Filter\\CoordinateFilter',
            '\\App\\Filter\\UsernameFilter',
            '\\App\\Filter\\AccessTokenFilter',
        ],
    ],

    /* ─── URLs ─────────────────────────────────────────────── */
    'urls' => [
        'baseUrl' => 'https://logshare.cn',
        'apiBaseUrl' => 'https://api.logshare.cn',
    ],

    /* ─── Rate limit（Redis INCR 限流，按 IP + method + path）── */
    'rateLimit' => [
        'enabled' => false,
        'trustedProxies' => ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
        'default' => [60, 60],
        'routes' => [
            '/v1/log' => ['limit' => 30, 'window' => 60],
            '/1/log' => ['limit' => 30, 'window' => 60],
        ],
    ],

    /* ─── Security（安全防御与合规规则）──────────────────── */
    'security' => [
        'enabled' => true,
        // 受信任反向代理列表（用于识别客户端真实 IP，支持 CIDR 网段）
        'trustedProxies' => ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
        // 违规内容前置过滤规则
        'contentRules' => [
            'enabled' => true,
            'keywords' => [],
            'patterns' => [],
        ],
        // 预设封禁 IP 列表与策略
        'ipBans' => [],
    ],

    /* ─── Legal ────────────────────────────────────────────── */
    'legal' => [
        'abuseEmail' => 'mengze2@foxmail.com',
        'imprint' => 'https://aternos.gmbh/imprint/',
        'privacy' => 'https://aternos.gmbh/en/mclogs/privacy',
    ],

    /* ─── SpinYarn（反混淆 PHP 扩展）────────────────────── */
    'spinyarn' => [
        // Yarn/Vanilla 映射目录，相对项目根 ./mappings。
        // 映射表由下载脚本（scripts/download_mappings.sh + download_vanilla_mappings.py）
        // 预先生成并提交进仓库；Docker 部署时 bind mount 宿主机 ./mappings。
        'mappings_dir' => 'mappings',
        // 是否允许 SpinYarn 使用 Redis 共享映射缓存。默认 true：跟随 cache.enabled
        // 并用 cache.redis 拼出 redis_url 透给扩展；设为 false 则强制按需解析
        // （on-demand，无缓存）：每次反混淆独立加载映射表，用完即释。
        'redis_enabled' => true,
        // Redis 共享缓存池水位（仅 redis_url 非空时生效）：解析后的映射表序列化存
        // Redis（key 为 spinyarn:mapping/<type>:<version>，7 天 TTL），recency 经
        // spinyarn:mapping:lru 有序集合维护；成员数达 high 时批量淘汰最旧者至 low。
        // 生产水位 10/10/7；无 redis_url 时三值无意义（按需解析，无缓存）。
        'cache_max_entries' => 10,
        'cache_high_watermark' => 10,
        'cache_low_watermark' => 7,
    ],

    /* ─── 统一事件队列（EventQueue）────────────────────────── */
    'eventQueue' => [
        // 是否启用异步事件队列（关闭时降级为同步执行）
        'enabled' => true,
        // Redis Stream 键名与消费组名
        'stream' => 'events:log:stream',
        'group' => 'log-event-workers',
        // 是否异步执行 SpinYarn 反混淆
        'asyncDeobfuscate' => true,
        // 是否异步执行关键词与正则安全审计
        'asyncSecurityAudit' => true,
    ],

];

