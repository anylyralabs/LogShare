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
            'm' => ['name' => 'MongoDB',     'class' => '\\App\\Storage\\MongoStorage',       'enabled' => true],
        ],
        'storageId' => 'm',
        // 日志保留时长（秒）。MongoDB 使用 created 上的 TTL 索引滚动过期；
        // 修改后需重启 Hyperf（启动时会执行 scripts/init_mongodb.php 同步 TTL）。
        'storageTime' => 7 * 24 * 60 * 60,
        'uploadFiles' => [
            'maxFiles' => 200,
            // 所有文件展开后的合计上限；不限制单个文件大小或行数。
            // 内容在 MongoDB 中按 8 MiB 分块，避免触发 BSON 16 MiB 单文档限制。
            'maxTotalBytes' => 20 * 1024 * 1024,
            // 解压后的请求体安全上限，需覆盖 20 MiB 文件与 JSON/表单编码开销
            'maxRequestBodyBytes' => 32 * 1024 * 1024,
        ],
    ],

    /* ─── MongoDB ─────────────────────────────────────────── */
    'mongo' => [
        // 生产环境通过 MONGODB_URI 注入带认证的完整 URI；本地开发可直连无认证实例。
        'url' => 'mongodb://127.0.0.1:27017',
        'database' => 'logshare',
        // MongoDB 8.0 且账号具备权限时，autoCompact 持续在后台压缩集合并回收磁盘。
        // 7.x / 权限不足时由 MongoCompactProcess 周期性 compact 兜底。
        'autoCompact' => true,
        'freeSpaceTargetMB' => 20,
        // 没有 autoCompact（MongoDB 7.x）时的兜底：进程内按间隔执行 compact。
        // 默认 6 小时一次；首次等待 2 分钟，避免和启动流量争抢磁盘。
        'compactionEnabled' => true,
        'compactInitialDelay' => 120,
        'compactInterval' => 6 * 60 * 60,
        'serverSelectionTimeoutMs' => 5000,
        'connectTimeoutMs' => 5000,
        'socketTimeoutMs' => 120000,
        'maxPoolSize' => 32,
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

    /* ─── Codex 日志分析 ───────────────────────────────────── */
    'codex' => [
        // aternos/codex-minecraft 的翻译语言；可用值见包的 lang/*.json。
        // zh-CN：简体中文；zh-TW：繁体中文；en：英文。
        'language' => 'zh-CN',
    ],

    /* ─── Filter ───────────────────────────────────────────── */
    'filter' => [
        'pre' => [
            '\\App\\Filter\\EncodingFilter',
            '\\App\\Filter\\TrimFilter',
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

];

