# LogShare

面向 Minecraft / Hytale 的服务端日志分享与解析平台。上传日志后返回 7 位短 ID、纯文本访问地址和删除凭证；服务同时使用 Aternos Codex 解析游戏版本、模组加载器与崩溃信息，并在扩展可用时通过 SpinYarn 还原 Fabric / Vanilla 的混淆栈。

后端是 PHP 8.4+、Hyperf 3.2、Swoole 6.2 的常驻协程应用：日志文件写入 MongoDB（TTL 索引滚动过期，autoCompact（若可用）或周期 compact 归还磁盘），Redis 承担读缓存，OpenResty 上的 OpenLiteWaf / OpenLiteStats 子模块负责边缘防护与访问统计。

## 目录

- [主要能力](#主要能力)
- [技术栈](#技术栈)
- [目录结构](#目录结构)
- [快速开始](#快速开始)
- [配置](#配置)
- [API](#api)
- [处理流程](#处理流程)
- [存储与过期](#存储与过期)
- [反混淆](#反混淆)
- [隐私与安全](#隐私与安全)
- [开发与测试](#开发与测试)
- [运维脚本](#运维脚本)
- [版本与许可](#版本与许可)

## 主要能力

- **日志分享**：`POST /v1/log` 接收文件列表，返回短 ID、文件列表与删除凭证；`GET /v1/log/{id}` 输出文件列表、大小、行数、来源与可见元信息。
- **多文件与压缩上传**：支持 JSON / 表单上传、`gzip` / `deflate` / `br` 请求体、ZIP 自动展开；文件数 ≤ 200，展开后合计 ≤ 20 MiB，不限制单个文件大小。
- **按文件读取与分析**：多文件时直接访问 ID 返回文件列表；带文件名才读取指定文件或执行 Codex 分析。
- **结构化解析**：使用 `aternos/codex-minecraft` 与 `aternos/codex-hytale` 识别日志类型、版本、加载器与崩溃原因；`GET /v1/insights/{id}/{filename}` 返回分析 JSON，Minecraft 分析默认使用 `codex-minecraft` 的简体中文翻译。
- **删除凭证**：上传时生成明文 token，仅在响应中返回一次；服务端只保存 `sha256:<hex>`，`DELETE /v1/log/{id}` 使用 `Authorization: Bearer <token>` 校验。
- **内容去重**：按来源与按名称排序的文件集合计算 `xxh128` 指纹，重复上传直接复用已有 ID。
- **隐私过滤**：入库前统一脱敏 IPv4 / IPv6、UUID、XUID、会话 token、客户端 ID、访问 token、坐标与用户名路径。
- **上传期反混淆**：逐文件识别 Fabric / Vanilla 日志并调用 SpinYarn；扩展缺失或失败时原样透传。全文分析只在显式请求分析接口时执行。
- **自动过期与回收**：`logs` 与内容分块都带 `created` TTL；优先使用 `autoCompact`（MongoDB 8.0 且账号有权限时），不可用时由 `MongoCompactProcess` 周期 `compact`，把可回收磁盘归还操作系统。
- **边缘防护**：OpenResty 侧提供 CC 限流、攻击特征拦截、封禁管理与访问统计；应用层保留 IP 黑名单；应用层内容审核已移除。

## 技术栈

| 层级 | 技术 |
| --- | --- |
| 运行时 | PHP >= 8.4（生产镜像使用 PHP 8.5）、Hyperf 3.2、Swoole 6.2 |
| 数据库 | MongoDB 7.0+，`logs` 父文档 + `log_chunks` 内容分块，TTL + 自动 compact |
| 缓存 | Redis 7.4（可选），只用于读缓存 |
| 日志解析 | `aternos/codex-minecraft` ^5.1、`aternos/codex-hytale` ^2.0 |
| 反混淆 | SpinYarn PHP 扩展（可选，Docker 镜像内编译） |
| 边缘层 | OpenResty 1.27 + OpenLiteWaf + OpenLiteStats（git submodule） |
| 测试与静态分析 | Pest 3、PHPStan 2（level 7）、PHPUnit 11 兼容配置 |

## 目录结构

```text
LogShare/
├── bin/hyperf.php              # CLI 与服务入口
├── core.php                    # CORE_PATH、.env 加载、Config 初始化
├── Config.inc.example.php      # 配置模板（Config.inc.php 的默认值来源）
├── config/                     # Hyperf 配置：server、database、middleware、exceptions
├── app/
│   ├── Controller/             # HTTP 控制器（注解路由）
│   ├── Storage/                # StorageInterface 与 MongoStorage
│   ├── Cache/                  # CacheInterface 与 RedisCache
│   ├── Client/                 # RedisClient、SpinYarnClient
│   ├── Filter/                 # 上传前置脱敏过滤器
│   ├── System/                 # SecurityService、MongoCompactProcess
│   ├── Data/                   # Token、MetadataEntry
│   ├── Parser/                 # RequestParser（压缩请求体）
│   ├── Exception/Handler/      # ApiError 与兜底异常渲染
│   ├── Log.php                 # 日志领域模型、逐文件反混淆、按需分析、缓存读写
│   ├── ContentParser.php       # 上传请求解码与 files/metadata/source 解析
│   ├── UploadParser.php        # 文件校验、ZIP 展开、重名处理
│   ├── Id.php / Config.php     # ID 编解码与配置访问
│   └── Version.php             # 版本号单一来源
├── docker/
│   ├── compose.yaml            # nginx / hyperf / mongo / redis / acme
│   ├── hyperf.Dockerfile       # PHP 8.5 + Swoole + SpinYarn 构建
│   ├── mongo-init.js           # 首次启动创建应用用户
│   └── nginx/default.conf      # TLS、反向代理、WAF/统计入口
├── mappings/                   # Yarn（*.tiny.gz）与 Vanilla（vanilla/*.txt）映射
├── OpenLiteWaf/                # 边缘 WAF（独立仓库 submodule）
├── OpenLiteStats/              # 访问统计（独立仓库 submodule）
├── scripts/                    # 迁移、事件生成、映射下载、端到端测试
├── tests/                      # Pest 单元 / 集成 / 架构测试
└── runtime/                    # 日志、注解缓存、安全密钥（运行期生成）
```

`vendor/`、`runtime/`、`tmp/`、`testlogs/` 属于运行或本地目录，不参与业务代码组织。

## 快速开始

### 环境要求

- PHP >= 8.4：`ext-json`、`ext-zlib`、`ext-mbstring`、`ext-curl`、`ext-openssl`、`ext-zip`。
- 运行服务还需要 `ext-swoole`（>= 6.2）、`ext-mongodb`（>= 2.5）；`ext-redis` 用于读缓存，缺失时自动降级；`ext-brotli` 用于 Brotli 请求体与响应压缩，缺失时返回 501。
- MongoDB 7.0+。TTL 索引负责过期；8.0 且账号有权限时使用 `autoCompact`，否则由应用进程周期 `compact`。单文件内容超过 16 MiB 时由应用拆成 8 MiB 分块写入。
- Redis 7.x 可选。即使不启用缓存，`Config::validate()` 仍要求 `cache.redis.host` 与端口非空。
- Composer 2；容器方式需要 Docker 与 Compose 插件。

### 本地运行

```bash
composer install
cp Config.inc.example.php Config.inc.php

# 启动本地 MongoDB 7.0+（8.0 会自动启用 autoCompact）
mongod --dbpath ./tmp/mongo-data

# 初始化索引、TTL 与磁盘回收能力
php scripts/init_mongodb.php

# 按需在 .env 或进程环境中设置 MONGODB_URI / MONGODB_DATABASE
php bin/hyperf.php start
```

服务默认监听 `0.0.0.0:9501`。启动后可访问：

```bash
curl http://127.0.0.1:9501/v1/limits
```

本地没有 Redis 时，缓存读取会失败并回源 MongoDB；Redis 扩展也缺失时 `RedisClient::getRedis()` 返回 `null`，不会阻断上传。`Config.inc.php` 不存在时会回退到 `Config.inc.example.php` 的默认值，但生产环境应显式提供完整配置。

### Docker Compose

仓库包含 WAF / 统计子模块，首次检出后先初始化：

```bash
git submodule update --init --recursive
```

准备配置与密码：

```bash
cp Config.inc.example.php Config.inc.php
cp .env.example .env
# 编辑 .env：MONGO_ROOT_PASSWORD、MONGO_PASSWORD、REDIS_PASSWORD
```

启动：

```bash
docker compose --env-file .env -f docker/compose.yaml up -d
```

Compose 使用 `mongo:7.0` 以兼容 ARM64 39-bit VA 内核；首次启动由 `docker/mongo-init.js` 创建 `logshare` 应用用户（`readWrite` + `dbAdmin`）。启动后执行 `php scripts/init_mongodb.php` 初始化索引/TTL，`MongoCompactProcess` 负责周期压缩。MongoDB 8.0 且账号具备 `autoCompact` 权限时会自动启用，否则同样走 compact 进程。

| 服务 | 说明 |
| --- | --- |
| `nginx` | OpenResty，监听 80 / 443，终止 TLS，加载 WAF 与统计 Lua，反向代理到 `hyperf:9501` |
| `hyperf` | 应用本体，仅容器网络内暴露 9501 |
| `mongo` | MongoDB 7.0+，首次启动创建应用用户；TTL + 自动 compact |
| `redis` | Redis 7.4，启用 `requirepass` |
| `acme` | Certbot，定期续期证书 |

`Config.inc.php`、`.env`、`mappings/`、`runtime/` 以挂载方式进入 `hyperf` 容器；PHP 代码与 `vendor/` 烘进镜像。修改 PHP 后需要重建镜像：

```bash
docker compose --env-file .env -f docker/compose.yaml build --no-cache hyperf
docker compose --env-file .env -f docker/compose.yaml up -d --force-recreate hyperf
```

镜像启动命令会先执行 `rm -rf /app/runtime/container`，避免沿用宿主机的陈旧注解扫描缓存，然后执行 `php scripts/init_mongodb.php` 初始化索引/TTL。MongoDB / Redis 使用命名卷 `logshare-mongo-data`、`logshare-redis-data`，删除卷会丢失全部日志与封禁状态，操作前需单独备份。

## 配置

### 配置文件

加载顺序由 `core.php` 与 `App\Config` 决定：

1. `core.php` 读取根目录 `.env`。仅支持 `KEY=VALUE` 形式，已存在的进程环境变量优先，不支持行内注释。值两侧的引号会被去除。
2. `App\Config::load()` 先加载 `Config.inc.example.php` 作为默认值，再深度合并 `Config.inc.php`；列表数组整体覆盖，不逐项合并。
3. 环境变量 `STORAGE_TIME`、`REDIS_HOST`、`REDIS_PORT`、`REDIS_TIMEOUT`、`REDIS_PASSWORD` 覆盖对应配置项。
4. 校验失败会抛出 `InvalidArgumentException`，进程无法启动。

业务代码读取配置统一使用：

```php
Config::Get('storage');            // 顶层段
Config::GetPath('urls.apiBaseUrl'); // 嵌套项
```

`Config::Get()` 只接受顶层段名，传入点路径会直接抛错。

### 配置段

| 配置段 | 作用 | 主要键 |
| --- | --- | --- |
| `storage` | 存储后端、保留时长与上传限制 | `storages`、`storageId`、`storageTime`、`uploadFiles` |
| `codex` | Codex 日志翻译语言 | `language` |
| `mongo` | MongoDB 连接与磁盘回收 | `url`、`database`、`autoCompact`、`freeSpaceTargetMB`、`compactInterval`、连接超时 |
| `cache` | Redis 缓存 | `cacheId`、`enabled`、`redis`、`ttl`、`maxSize` |
| `id` | 短 ID 字符集与长度 | `characters`、`length` |
| `filter` | 上传前置过滤器链 | `pre` |
| `urls` | 对外展示地址 | `baseUrl`、`apiBaseUrl` |
| `rateLimit` | Redis 限流参数 | `enabled`、`trustedProxies`、`default`、`routes` |
| `security` | IP 封禁与可信代理 | `enabled`、`trustedProxies`、`ipBans` |
| `legal` | 滥用联系与合规链接 | `abuseEmail`、`imprint`、`privacy` |
| `spinyarn` | 反混淆扩展 | `mappings_dir`、`redis_enabled`、`cache_*` |

关键默认值：

- `storage.storageTime`：`604800` 秒（7 天）。
- `codex.language`：`zh-CN`（简体中文）；可改为 `zh-TW` 或 `en`。
- `storage.uploadFiles.maxFiles`：200；`maxTotalBytes`：20 MiB；`maxRequestBodyBytes`：32 MiB。
- `mongo.compactInterval`：21,600 秒（6 小时），MongoDB 7.x / autoCompact 不可用时由 `MongoCompactProcess` 执行 `compact`。
- `cache.ttl`：1,800 秒；`cache.maxSize`：5 MiB（全部文件合计）。
- `id.length`：6；对外 ID 为 1 位存储标识编码 + 6 位随机字符，共 7 位。

`filter.pre` 是类名数组，顺序即执行顺序；新增过滤器必须继承 `App\Filter\Filter`，否则配置校验失败。

### 环境变量

| 变量 | 作用 |
| --- | --- |
| `APP_ENV` | `prod` 时关闭错误显示，并调整 Hyperf 日志级别 |
| `APP_NAME` | Hyperf 应用名，默认 `logshare` |
| `SCAN_CACHEABLE` | 是否缓存注解扫描结果 |
| `MONGODB_URI` / `MONGODB_DATABASE` | MongoDB 连接 URI 与数据库名 |
| `CODEX_LANGUAGE` | 覆盖 `codex.language`，如 `zh-CN`、`zh-TW`、`en` |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_TIMEOUT` / `REDIS_PASSWORD` | 覆盖 `cache.redis` |
| `STORAGE_TIME` | 覆盖 `storage.storageTime`（秒） |
| `WAF_SNAPSHOT_PATH` | 指定 OpenLiteWaf 快照文件路径 |
| `OPENLITEWAF_ADMIN_TOKEN` | WAF 运维端点令牌，Compose 中透传给 nginx |
| `MONGO_ROOT_PASSWORD` / `MONGO_PASSWORD` / `REDIS_PASSWORD` | Compose 必填密码变量 |

### 配置校验

启动阶段会检查：

- `storage.storageTime > 0`。
- `mongo.url` 必须是 `mongodb://` 或 `mongodb+srv://`，`mongo.database` 非空。
- `cache.redis.host` 非空且端口大于 0。
- `filter.pre` 中每个类存在且继承 `App\Filter\Filter`。
- `storage.storages.*.class` 存在且实现 `App\Storage\StorageInterface`。
- `cache.cacheId` 存在且实现 `App\Cache\CacheInterface`。
- `rateLimit.enabled` 为真时，`trustedProxies` 与 `default` 结构合法。

## API

### 约定

- 默认地址示例为 `https://api.logshare.cn`；路径前缀为 `/{version}`，`v1` 为推荐写法，`1` 为兼容旧客户端的别名。
- 上传支持 `application/json` 与 `application/x-www-form-urlencoded`，字段为 `files`、`metadata` 与 `source`；旧版 `content` 字段继续兼容。
- 请求体支持 `Content-Encoding: gzip`、`deflate`、`br`，多层编码按逆序解压，最多 5 层。
- 成功响应统一为 `success: true` 加业务字段；失败响应为 `success: false`、`error`、`code`。`/v1/limits` 与单文件 `/v1/insights/{id}` 例外，直接返回原始 JSON。
- 读接口与上传接口不要求鉴权；删除接口要求 Bearer token。
- CORS 允许所有来源（`Access-Control-Allow-Origin: *`），并缓存预检结果 24 小时。

### 端点

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `POST` | `/v1/log` | 上传文件列表，返回 ID、文件列表与删除凭证 |
| `DELETE` | `/v1/log/{id}` | 按 Bearer token 删除一个或多个日志 |
| `GET` | `/v1/log/{id}` | 查询日志元信息与文件列表 |
| `GET` | `/v1/raw/{id}` | 单文件返回内容；多文件返回 `200` + 文件列表 |
| `GET` | `/v1/raw/{id}/{filename}` | 读取指定文件；文件名按 URL 解码后精确匹配 |
| `GET` | `/v1/insights/{id}` | 单文件返回 Codex 分析；多文件返回 `200` + 文件列表 |
| `GET` | `/v1/insights/{id}/{filename}` | 对指定文件执行 Codex 分析 |
| `GET` | `/v1/limits` | 返回保留时长与文件上传限制 |
| `GET` | `/` | 返回端点清单 |

### 上传日志

```bash
curl -X POST https://api.logshare.cn/v1/log \
  -H 'Content-Type: application/json' \
  --data '{
    "files": [
      {"name": "latest.log", "content": "..."},
      {"name": "crash-2026-01-01.txt", "content": "..."}
    ],
    "source": "HMCL/3.5.9",
    "metadata": [
      {"key": "version", "value": "1.21.1", "label": "Minecraft 版本"}
    ]
  }'
```

请求字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `files` | array | 新契约。每项为 `{"name": "...", "content": "..."}`；文件数 ≤ 200，展开后合计 ≤ 20 MiB。与 `content` 至少提供一个 |
| `content` | string | 旧版兼容字段。不为空时映射为主文件 `latest.log`；与 `files` 同时存在时作为主文件，若重名则使用 `main.log` |
| `metadata` | array | 分析核心输入。最多 100 条；每项支持 `key`（trim 后 1–64 字符）、`value`（字符串最长 1024 字符，其他类型 JSON 序列化）、`label`（最长 128 字符）、`visible`（默认 true）；重复 `key` 保留首条 |
| `source` | string | 来源标识，按 UTF-8 边界截断到 64 字符；省略时尝试从 User-Agent 解析 `名称/版本`，否则记为 `未指定` |

限制与处理：

- 请求体解压后上限 32 MiB；文件数 ≤ 200，展开后合计 ≤ 20 MiB；不限制单个文件大小或行数。
- 旧客户端可以继续提交 `content`（可带 `files` 附件）；新客户端应只提交 `files`。上传响应会同时返回文件列表与顶层 `raw` 链接。
- `.zip` 会逐条流式解压，并检查路径穿越、控制字符、超长文件名与解压膨胀。
- 同名文件会重命名为 `名字 (2).扩展名`、`名字 (3).扩展名`，避免与数据库主键冲突。
- 每个文件独立经过 `filter.pre` 过滤链并尝试 SpinYarn 反混淆；不会执行全文 Codex 分析。
- **metadata 很重要**：请尽量提交 `version`、`loader`、`launcher`、`modpack` 与崩溃上下文；`GET /v1/log/{id}` 会展示（`visible=false` 除外），分析与排查会依赖这些字段。
- 来源与按名称排序的文件集合共同计算 `xxh128` 指纹；命中已有指纹时直接返回旧 ID，并带 `deduplicated: true`。去重不会写入新的删除凭证。

成功响应：

```json
{
  "success": true,
  "message": "Log submitted successfully",
  "id": "s4Kd9xQ",
  "url": "https://logshare.cn/s4Kd9xQ",
  "filesUrl": "https://api.logshare.cn/v1/log/s4Kd9xQ",
  "raw": "https://api.logshare.cn/v1/raw/s4Kd9xQ",
  "token": "64 位十六进制字符串",
  "deduplicated": false,
  "fileCount": 2,
  "requiresFileSelection": true,
  "files": [
    {
      "name": "latest.log",
      "size": 2048,
      "lines": 40,
      "raw": "https://api.logshare.cn/v1/raw/s4Kd9xQ/latest.log",
      "insights": "https://api.logshare.cn/v1/insights/s4Kd9xQ/latest.log"
    }
  ]
}
```

### 删除日志

```bash
curl -X DELETE https://api.logshare.cn/v1/log/s4Kd9xQ \
  -H 'Authorization: Bearer <token>'
```

`{id}` 支持逗号分隔的多个 ID。逐个校验存在性与 token 后返回删除结果；缺少 Bearer token 时返回 401。

### 查询元信息与文件列表

```bash
curl https://api.logshare.cn/v1/log/s4Kd9xQ
```

返回字段：

| 字段 | 说明 |
| --- | --- |
| `id` | 7 位日志 ID |
| `fileCount` | 文件数量 |
| `requiresFileSelection` | 是否需要在多个文件中选择 |
| `size` | 全部文件总字节数 |
| `lines` | 全部文件总行数 |
| `created` / `expires` | 创建/最近续期时间与预计过期时间 |
| `metadata` | 公开元数据数组，自动过滤 `visible=false` 的条目 |
| `source` | 来源标识 |
| `files` | 文件列表 `[{name, size, lines, raw, insights}]` |
| `filesUrl` | 文件列表地址 |

元信息接口不会续期；读取文件或分析结果才会延长保留时间。

### 读取文件

```bash
curl https://api.logshare.cn/v1/raw/s4Kd9xQ/latest.log
```

- 单文件日志可省略文件名，`GET /v1/raw/{id}` 直接返回 `text/plain`。
- 多文件日志省略文件名时返回 `200` + 文件列表，不会擅自选择文件。
- 读取成功会调用 `renew()`，顺延存储过期时间与 Redis 缓存 TTL。文件名可以是包含 `/` 的相对路径；服务端仅按数据库中的 `name` 精确匹配。

### 分析结果

```bash
curl https://api.logshare.cn/v1/insights/s4Kd9xQ/latest.log
```

使用 Aternos Codex 对指定文件执行 `detect → parse → analyse`，直接返回分析 JSON（不包 `success` 外壳，且不包含原始条目）。分析结果在进程内按内容 MD5 做 LRU 缓存：最多 32 条、单条 4 MiB、总计 32 MiB。

问题、解决方案、日志类型与信息标签默认使用简体中文，由 `aternos/codex-minecraft` 的 `lang/zh-CN.json` 提供，语言由 `codex.language`（或 `CODEX_LANGUAGE`）控制。`aternos/codex` 核心本身不带翻译层；`aternos/codex-hytale` 当前没有语言包，Hytale 的版本标签仍是英文。

- 单文件日志可省略文件名。
- 多文件日志省略文件名时返回 `200` + 文件列表，客户端选择后再请求。

### 限额

```bash
curl https://api.logshare.cn/v1/limits
```

```json
{
  "storageTime": 604800,
  "maxFiles": 200,
  "maxTotalBytes": 20971520,
  "maxRequestBodyBytes": 33554432
}
```

### 错误码

| 状态码 | 含义 |
| --- | --- |
| `400` | 字段缺失、格式错误、文件总量超限、删除全部失败 |
| `401` | 删除请求缺少 Bearer token |
| `403` | token 不匹配，或客户端 IP 已被封禁 |
| `404` | 日志或文件不存在 |
| `413` | 文件数量或总大小超过限制，或请求体超限 |
| `415` | 不支持的 `Content-Encoding` |
| `429` | 触发限流 |
| `500` | 持久化失败、JSON 编码失败或其他内部错误 |
| `501` | 服务端未安装 Brotli 扩展 |
| `503` | 限流依赖的 Redis 不可用（限流中间件启用时） |

## 处理流程

### 上传

1. `CorsMiddleware` 处理跨域；`OPTIONS` 请求直接返回。
2. `ContentParser` 读取请求体，先检查原始大小，再按 `Content-Encoding` 逆序解压，最后解析 JSON 或表单。
3. 校验 `files` 或旧版 `content` 至少有一个，检查客户端 IP 是否在配置、Redis、本地封禁文件或 WAF 快照中。
4. `Log::put()` 对每个文件执行前置过滤链，识别 Minecraft 日志并尝试 SpinYarn 反混淆；反混淆失败按原文保留。
5. 反混淆阶段识别出的第一份 Minecraft 日志可补全 `version` / `loader` 元数据；客户端显式提交的同名键优先。
6. `MongoStorage::Put()` 计算 `xxh128` 指纹，先写 `pending` 父文档占住 ID，再写内容分块，最后把父文档置为 `ready`；主存储成功后按大小决定是否写 Redis 缓存。去重命中时不写缓存。
7. 返回 200，响应包含 ID、文件列表、`filesUrl`、顶层 `raw`、token 与 `deduplicated`。全文 Codex 分析不会在这里执行。

### 读取

1. 解析 7 位 ID，还原存储标识与 6 位原始 ID。
2. Redis 缓存键为 `log:<id>`；存在删除墓碑 `log:<id>:deleted` 时跳过缓存，避免已删日志在 TTL 内复活。
3. 缓存未命中则从 `MongoStorage::Get()` 读取 token 哈希、元数据与文件集合，并在总大小不超过 `cache.maxSize` 时回填缓存。
4. 单文件直接返回内容；多文件返回 `200` + 文件列表。带文件名时按数据库 `name` 精确匹配。
5. 读取文件或分析结果成功后调用 `Renew()`：父文档与内容分块的 `created` 同步更新，重置 TTL；Redis 缓存 TTL 同步续期。元信息接口不续期。

## 存储与过期

### 文档模型

| 集合 | 字段 | 说明 |
| --- | --- | --- |
| `logs` | `_id`、`status`、`token`、`source`、`fingerprint`、`created`、`metadata`、`files` | 父文档；不保存文件内容 |
| `log_chunks` | `log_id`、`file_index`、`seq`、`data`、`created` | 文件内容分块；单块 8 MiB |

父文档先以 `status=pending` 占住 `_id`，分块写完后才切换为 `ready`。读取只认 `ready`，因此写入中断不会暴露半条日志；pending 文档和分块由 TTL 兜底回收。`metadata` 与文件描述嵌在父文档中，父文档体积很小；20 MiB 单文件会被拆成最多 3 个分块，规避 MongoDB 16 MiB BSON 文档上限。

> `ext-mongodb` 基于 libmongoc，Swoole 的协程 hook 无法拦截其网络 I/O；大文件写入会同步占用当前 worker。当前版本使用直连实现，上线前应做并发压测；若阻塞成为瓶颈，再评估 RemoteObject/GoTask 方案。

### ID 与去重

- 原始 ID 为 6 位，对外 ID 为 7 位；首位由存储标识与随机字符共同编码。当前存储后端为 MongoDB，`storageId` 为 `m`。
- ID 生成后先做存在性预检；并发冲突时重新生成，最多重试 5 轮。
- 指纹使用非加密哈希 `xxh128`，计算范围是 `source` 与按名称排序的文件集合；`logs` 上建立 `{fingerprint, status, created}` 索引。
- 去重只匹配 `ready` 且未过期的文档，返回最新一条，保证剩余 TTL 最长。
- 删除凭证为 64 位十六进制随机串；库中存 `sha256:<hex>`（71 字符），比较使用 `hash_equals()`。旧记录的无前缀裸哈希在验证成功后惰性补前缀。

### TTL 与磁盘回收

- `logs.created` 与 `log_chunks.created` 均建立 TTL 索引，`expireAfterSeconds = storageTime + 120` 秒宽限；逻辑过期仍按 `storageTime` 过滤，宽限只用于避免续期和 TTL 扫描竞争。
- 读取文件或分析结果时，`Renew()` 先更新分块、再更新父文档的 `created`；TTL 监控约每 60 秒跑一轮，删除不是秒级精确。
- `scripts/init_mongodb.php` 负责创建索引、用 `collMod` 同步 TTL，并尝试启用 MongoDB 8.0 `autoCompact`；Hyperf 容器启动时自动执行。
- `autoCompact`（需 MongoDB 8.0 与相应权限）会持续压缩；不可用时由 `MongoCompactProcess` 按 `mongo.compactInterval` 周期性执行集合级 `compact`，把 TTL 删除后可回收的空间归还操作系统。
- 修改 `storageTime` 后重启 Hyperf 即可让启动脚本更新 TTL。

### 结构升级

- 本版本不兼容旧 MariaDB 日志；旧 `s` 开头 ID 不可读，旧 `logshare-mariadb-data` 卷需自行备份后清理。
- 没有旧日志迁移脚本；如需保留历史数据，应单独做 MariaDB → MongoDB ETL。

## 反混淆

`App\Client\SpinYarnClient` 封装 SpinYarn PHP 扩展，只在以下条件同时满足时生效：

- 日志被 Codex 识别为 Minecraft 日志。
- 类型为 Fabric（`yarn`）或 Vanilla 服务端/客户端/崩溃报告/网络协议错误报告（`vanilla`）。
- 能解析出合法版本号，且 `mappings/` 下存在对应映射。
- 扩展已加载，`spinyarn_init()` 初始化成功。

映射表由脚本预先生成并提交：

```bash
scripts/download_mappings.sh          # Yarn，写入 mappings/<version>.tiny.gz
python3 scripts/download_vanilla_mappings.py  # Mojang 官方映射，写入 mappings/vanilla/
```

当前映射覆盖 1.14 至 1.21.11。服务不会自动下载映射；目录为空或版本缺失时反混淆失败并按原样透传（fail-open）。

其他行为：

- 扩展未加载、版本非法、映射类型非法、初始化失败或反混淆返回异常时，均返回 `null`，调用方保留原文。
- 初始化失败后，在没有可用旧句柄的情况下 60 秒内不再重试；成功句柄跨请求复用，`mappings_dir`、Redis URL 或缓存水位变化时重建，新句柄成功才替换旧句柄。
- 只有 `spinyarn_init` 接受第 5 个参数时才传 Redis URL；旧版 4 参扩展自动退回按需解析（无缓存），避免 `ArgumentCountError` 导致进程生命周期内永久失效。
- 反混淆在入库前完成，MongoDB 与 Redis 中保存的就是可读文本。

## 隐私与安全

### 入库前脱敏

`filter.pre` 默认按以下顺序执行：

| 过滤器 | 作用 |
| --- | --- |
| `EncodingFilter` | 非法 UTF-8 字节替换为 `U+FFFD` |
| `TrimFilter` | 去除文件首尾空白 |
| `IPv4Filter` | IPv4 地址替换为 `**.**.**.**`，保留回环、`0.0.0.0`、`1.1.1.1` / `1.0.0.1` 与 `8.8.8.8` / `8.8.4.4` |
| `IPv6Filter` / `IPv6ShortFilter` | 完整与缩写 IPv6 地址打码，保留 `::1` 等回环形态 |
| `UuidFilter` | 带/不带连字符、带花括号、`urn:uuid:` 形式的 UUID 打码 |
| `XuidFilter` | `xuid` / `XUID` / `xboxUserId` 等 16–19 位 ID 打码 |
| `SessionTokenFilter` | `accessToken`、`Authorization: Bearer`、`sessionId` 等会话凭证打码 |
| `ClientIdFilter` | `clientId`、`deviceId`、`instanceId`、`launcherId` 及命令行参数打码 |
| `CoordinateFilter` | `BlockPos`、`Vec3d`、`position`、`looking at` 等坐标打码 |
| `UsernameFilter` | `C:\Users\<name>\`、`/home/<name>/`、`/Users/<name>/`、`USERNAME=` 打码 |
| `AccessTokenFilter` | 兜底处理其余 access token 形态 |

正则执行失败（例如回溯超限）时，过滤器返回原文并写入 `runtime/logs/system.log`，属于可观测的 fail-open 行为。

### 删除凭证

- 上传响应返回 64 位十六进制明文 token，服务端只保存 `sha256:<hex>`。
- 校验使用常量时间比较；库中的哈希本身不能作为凭证提交。
- 去重命中不会写入新 token，重复上传者无法获得删除权限。
- 删除成功后清理缓存；缓存删除失败会重试，仍失败则写入 `log:<id>:deleted` 墓碑，TTL 与缓存一致。

### IP 与代理

- 只有 `security.trustedProxies` 与 `rateLimit.trustedProxies` 中的地址才被视为可信代理，支持 IPv4 与 IPv6 CIDR。
- 优先读取 `X-Real-IP`；否则从右向左剥离 `X-Forwarded-For` 中的可信代理，取最外层不可信地址。
- 封禁状态依次检查配置 `ipBans`、Redis `security:ban:<ip>`、`runtime/security_bans.json` 与 OpenLiteWaf 快照。
- 封禁默认 24 小时，`ttlSeconds <= 0` 视为 30 天，上限 365 天。

### 上传与边缘防护

- 上传路径不再执行应用层关键词/正则内容审核；内容防护由 OpenLiteWaf 的边缘规则承担。

- ZIP 解压会拒绝空路径段、`.` / `..`、NUL、控制字符与超过 512 字节的文件名；按块读取，超过 20 MiB 总预算立即中止。
- 文件总量、文件数、metadata、来源与请求体均有独立上限，避免超长字段或超大请求触发数据库错误。
- `RateLimitMiddleware` 已实现但**未注册**到全局中间件链；启用前需确认 Redis 故障时的 503 fail-closed 行为是否符合预期。
- 仓库默认的 nginx 配置对 API 做 30 r/s、burst 60 的基础限流，并加载 OpenLiteWaf 做 CC 与特征拦截；特征命中返回 403，CC 超限返回 429。
- WAF 的 `/security`、`/security/stats`、`/security/logs` 与运维端点 `/security/unban`、`/security/bans` 由 nginx Lua 直接响应；未配置 `OPENLITEWAF_ADMIN_TOKEN`（至少 16 字符）时管理端点返回 404。

## 开发与测试

```bash
composer install
cp Config.inc.example.php Config.inc.php

composer test                 # Pest 全量测试
composer test:architecture    # 架构约束
composer test:coverage        # 覆盖率（需要 Xdebug / PCOV）
composer stan                 # PHPStan level 7
php vendor/bin/pest tests/Unit/FilterTest.php
php vendor/bin/pest --filter=Token
```

测试引导 `tests/bootstrap.php` 会：

- 加载 `core.php` 并定义 `BASE_PATH`。
- 自动创建 `tmp/` 与 `Config.inc.php`（复制示例配置）。
- 在未加载 `ext-redis` 时把 `Tests\Mocks\RedisMock` 别名成 `Redis`。
- 提供 `createTestLog()`、`createTestToken()` 等辅助函数。

`tests/Unit` 以离线单元测试为主（涉及 MongoDB 的用例在数据库不可用时跳过）；`tests/Integration` 中的部分用例需要可用的 MongoDB / Redis。架构测试会检查：

- 控制器不直接读取 `$_SERVER`、`$_GET`、`$_POST`。
- 控制器不包含裸 SQL。
- 所有控制器继承 `App\Controller\AbstractController`。
- 所有 Filter 继承 `App\Filter\Filter`，Storage 实现 `StorageInterface`，Cache 实现 `CacheInterface`。

开发约定：

- 控制器只使用注入的 PSR-7 请求、`ContentParser`、Storage 抽象与 `AbstractController` 助手；结构化输入用 `getParsedBody()`。
- 新增存储后端实现 `StorageInterface`，新增缓存实现 `CacheInterface`，新增过滤器继承 `Filter`。
- 诊断日志统一使用 `App\Syslog`，写入 `runtime/logs/system.log`，文件超过 5 MiB 自动轮转。
- Swoole 是常驻进程：修改解析器、反混淆、进程状态或注解后必须重启；修改 `Config.inc.php` 后同样需要重启。
- 常驻进程内的 cURL 调用应设置 `CURLOPT_FORBID_REUSE` 并在 `finally` 中释放句柄，避免 FD 累积。
- 仓库未配置统一格式化工具，提交前至少运行相关 Pest 用例、架构测试与 PHPStan。

CI 位于 `.github/workflows/`：PHP 8.4 / 8.5 测试矩阵、PHPStan、架构测试，以及 Hyperf + Swoole 启动冒烟。发布流水线在 `main` 分支收到以 `[Build]` 开头的提交信息（或手动触发）时运行测试与静态分析，并按版本号创建 tag 与 Release。

## 运维脚本

| 脚本 | 用途 |
| --- | --- |
| `scripts/init_mongodb.php` | 创建/同步 MongoDB 索引、TTL，并启用可用的磁盘回收能力 |
| `scripts/ci_e2e_test.php` | 对真实实例执行端到端冒烟，支持 `--base-url` 与 `--waf-test` |
| `scripts/test_online_apis.php` | 经 `127.0.0.1` 与指定 Host 探测线上接口 |
| `scripts/test_endpoints_host.sh` | 用 curl 批量检查端点状态码 |
| `scripts/download_mappings.sh` | 下载 Fabric Yarn 映射到 `mappings/` |
| `scripts/download_vanilla_mappings.py` | 下载 Mojang 官方 Vanilla 映射到 `mappings/vanilla/` |
| `scripts/deploy_remote.sh` | 服务器侧拉取代码并重建 `hyperf` 容器（含生产配置保护） |
| `scripts/clover_to_lcov.php` | 把 Clover 覆盖率转换为 LCOV |

## 版本与许可

版本号唯一来源是 `app/Version.php`，当前为 `1.8.5`。对外暴露版本信息的位置应引用该常量，避免多处硬编码漂移。

项目使用 MIT 许可证，详见 `LICENSE`。
