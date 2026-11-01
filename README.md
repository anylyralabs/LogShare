# LogShare

LogShare 是一个面向 Minecraft 与 Hytale 等沙盒游戏的高性能日志智能分析与安全分享云平台。使用者通过 HTTP 接口上传服务端、客户端日志或崩溃转储包，即可获得高可用分享链接；系统采用**极速上传解耦**设计，上传时仅执行轻量 Codex 特征检测并毫秒级响应，将高耗时的 SpinYarn 混淆堆栈反解与全量敏感内容审核交由**统一异步事件队列（EventQueue）**排队调度处理。

AI 智能诊断已从本仓库剥离，由外部服务提供；本仓库保留日志上传、分享、Codex 本地结构化解析，以及异步安全审核与 SpinYarn 反混淆流水线。`/v1/ai/*`、`/rag` 及相关管理接口不再提供，统一返回 404。

服务端基于 Hyperf 3.2 框架与 Swoole 6.2 高性能协程常驻进程引擎，原生要求 PHP 8.4 及以上。存储后端使用 MariaDB，Redis 作为缓存、限流与事件流消息总线。当前版本 `v1.8.4`，更新记录见 [`CHANGELOG.md`](CHANGELOG.md)。

## 环境要求

- PHP 8.4+，扩展 ext-json、ext-zlib、ext-mbstring、ext-pdo_mysql
- Swoole 6.2（常驻进程运行时）
- MariaDB（主存储）
- Redis（可选，用于缓存、限流与统一异步事件队列；不可用时缓存降级、队列事件改为同步执行）
- SpinYarn 扩展（可选，原生 Rust 编写，用于混淆堆栈反解；缺失时日志原样透传）

## 安装与启动

```bash
composer install
cp Config.inc.example.php Config.inc.php
php bin/hyperf.php start
```

从 v1.8.4 或更早版本升级时，先执行一次 token 列宽迁移（幂等、只 ALTER，不清数据）：

```bash
php scripts/widen_token_column.php
```

`Config.inc.php` 是唯一的应用配置文件（已被 Git 忽略），数据库与 Redis 连接信息可通过 `DB_*`、`REDIS_*` 环境变量覆盖。服务监听 `9501` 端口。

## 配置

`Config.inc.php` 的主要配置段：

| 配置段 | 说明 |
|---|---|
| `storage` | MariaDB 存储、日志保留时间（TTL）、多文件与 ZIP 上传限制（`uploadFiles`） |
| `cache` | Redis 缓存：开关、TTL、大小限制与连接信息 |
| `filter` | 上传前的预处理过滤链（脱敏规则），按配置顺序执行 |
| `id` | 日志 ID 的字符集与长度（修改会破坏已有 ID） |
| `security` | 动态合规防护：AES-256-GCM 密文存储违规关键词与正则、IP 黑名单与反向代理真实 IP 判定 |
| `eventQueue` | 统一日志事件队列：Redis Stream 键名与消费组、最大重试次数（`maxAttempts`）、异步反混淆与异步安全审核开关 |
| `rateLimit` | 应用层限流（limit / window，Redis INCR 实现） |
| `spinyarn` | 反混淆扩展：映射目录与缓存水位 |
| `urls` | 前端与 API 的基础 URL |

## Docker 部署

> 仓库以 submodule 引用边缘 WAF（`OpenLiteWaf/`，当前 1.3.1，独立仓库 [anylyralabs/OpenLiteWaf](https://github.com/anylyralabs/OpenLiteWaf)，MIT）与站点统计（`OpenLiteStats/`，当前 1.2.0，[anylyralabs/OpenLiteStats](https://github.com/anylyralabs/OpenLiteStats)，MIT）。clone 后先执行 `git submodule update --init --recursive`；已部署机器可 `git config submodule.recurse true` 让后续 `git pull` 自动跟随 submodule 更新。

```bash
docker compose -f docker/compose.yaml up -d --build
```

启动前先将 `.env.example` 复制为 `.env` 并填写数据库密码。`.env` 由 Hyperf 进程读取，同时供 Compose 初始化 MariaDB 与 Redis 服务，其中 `MARIADB_PASSWORD`、`MARIADB_ROOT_PASSWORD` 与 `REDIS_PASSWORD` 必须在 Hyperf、MariaDB、Redis 与定时清理服务之间保持一致。

完整的业务配置（数据库密码、生产域名等）通过挂载项目根目录的 `Config.inc.php` 注入容器，服务器上放置好该文件后执行上面的命令即可。该文件包含密钥，已被 Git 与 Docker 构建上下文排除，只需在服务器上手动放置，不要提交进仓库；`DB_*` 与 `REDIS_*` 的容器网络寻址仍由 compose 环境变量提供。

Compose 定义六个服务：**nginx**（OpenResty 反向代理，对外监听 80/443，承载 OpenLiteWaf 与 TLS 证书）；**acme**（证书续期）；**hyperf**（应用主进程，仅在内部网络监听 9501）；**mariadb**（数据存储，表结构由 `docker/mariadb-init.sql` 在首次创建卷时初始化）；**mariadb-events**（在数据库可用后创建过期日志清理 Event）；**redis**（缓存与限流）。

OpenLiteWaf（`OpenLiteWaf/`）是 nginx 容器内的 OpenResty Lua 模块，在反向代理层做按 IP 的固定窗口 CC 限速（超限返回 429，不封 IP）与攻击特征检查（SQL 注入、XSS、路径穿越、命令执行、扫描器探测）；检查范围包括 URL 原始与解码形态、请求路径、User-Agent 和请求体（接收任意用户文本的日志与分析端点豁免请求体扫描），特征命中返回 403 并按窗口累计次数封禁 IP（默认累计 3 次才封，避免一次误判让该来源十分钟内在所有端点不可用）。统计页 `https://<域名>/security`（JSON 汇总：`/security/stats`，攻击日志分页：`/security/logs`，现存封禁与封禁原因：`/security/bans`）展示内存计数与脱敏后的攻击记录，含命中的规则序号与匹配对象；计数与封禁都会快照到挂载目录，重启后恢复（即“重启不是解封手段”）。误封的运行时解封走特权端点 `POST /security/unban`，令牌为 `.env` 中的 `OPENLITEWAF_ADMIN_TOKEN`，未配置时该端点一律 404。工作方式、配置与规则编写见 `OpenLiteWaf/README.md`；改动 Lua 文件后执行 `docker compose -f docker/compose.yaml restart nginx` 生效（`git pull` 只更新文件，不重载不生效；容器内 `nginx -s reload` 在本部署不可靠）。应用层安全由 Hyperf 负责，参数化查询、输出转义与脱敏过滤链不依赖本模块。

### HTTPS 证书

将 `docker/nginx/default.conf` 中两个 `server_name` 改为实际域名，并将 HTTPS 证书路径中的域名同步修改。首次签发前，先临时注释 HTTPS server 并启动 nginx，然后执行：

```bash
docker compose -f docker/compose.yaml run --rm acme certonly --webroot -w /var/www/acme -d api.example.com --email admin@example.com --agree-tos --no-eff-email
```

恢复 HTTPS 配置后重新加载 nginx：

```bash
docker compose -f docker/compose.yaml up -d nginx
docker compose -f docker/compose.yaml exec nginx nginx -s reload
```

`acme` 容器每 12 小时检查续期，续期完成后需要执行一次 nginx reload 使新证书生效。证书目录不提交到 Git；域名需解析到服务器且公网开放 80 端口。

### 日志过期清理

MariaDB 的 Event Scheduler 每小时删除超过 `Config.inc.php` 中 `storage.storageTime` 秒的日志，默认 604800 秒（7 天）。仓库内的 `docker/mariadb-events.sql` 是与默认 TTL 对应的默认版本，由 `mariadb-events` 服务在数据库可用后执行；修改 TTL 后先执行 `php scripts/sync_mariadb_events.php` 重新生成该 SQL，再重启 `mariadb-events` 服务，重新生成的文件按需提交以保持与部署一致。可用以下命令检查 Event 是否启用及下次执行时间：

```bash
docker compose -f docker/compose.yaml exec mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" logshare -e "SHOW EVENTS LIKE 'cleanup_expired_logs'\\G"
```

mariadb 的 healthcheck 也会验证该 Event 存在且状态为 `ENABLED`。MariaDB 使用固定名称的卷 `logshare-mariadb-data`，重建容器不会删除数据；不要执行 `docker compose down -v`，否则持久化卷会被删除。生产环境禁止使用默认密码，建议通过部署环境或 Docker secrets 注入凭据。

### 手动部署（无 Docker）

直接运行 `php bin/hyperf.php start` 并使用任意反向代理转发到 9501 即可，nginx 参考配置：

```nginx
location / {
    proxy_pass http://127.0.0.1:9501;
    proxy_buffering off;
    proxy_read_timeout 300s;
}
```

### 发布包

GitHub Releases 提供 `LogShare-v<version>.tar.gz` 源码部署包，由 `git archive` 分别导出主仓与 `OpenLiteWaf` / `OpenLiteStats` 子模块后合并而成，并附带 `.sha256` 校验文件。部署包不包含 `vendor/`、`runtime/`、`Config.inc.php` 或 `.env`；这些仍在目标机器上生成或挂载。

## API

同时提供 `/1/`（已弃用）与 `/v1/` 两组路径，响应格式统一为 JSON（`/raw` 端点返回纯文本），建议新集成使用 `/v1/`。完整规范见 [`API.md`](API.md)、[`openapi.yaml`](openapi.yaml) 与 [`postman_collection.json`](postman_collection.json)。

```
POST   /1/log | /v1/log                          上传日志（极速解耦，支持多文件与 ZIP）
DELETE /1/log/{id} | /v1/log/{id}                删除日志（Bearer Token 鉴权）
GET    /1/raw/{id} | /v1/raw/{id}                获取原始日志（主文件）
GET    /v1/raw/{id}/{filename}                   获取指定子文件（支持子路径）
GET    /1/log/{id} | /v1/log/{id}                获取日志元信息与文件列表
GET    /1/insights/{id} | /v1/insights/{id}      获取 Codex 结构化分析结果
POST   /1/analyse | /v1/analyse                  直接分析日志内容（不存储）
GET    /1/limits | /v1/limits                    获取上传限制与存储 TTL
```

上传接口接受 `application/x-www-form-urlencoded` 与 `application/json`，支持 gzip / deflate / brotli 压缩请求体；`files` 数组可附加多个文件，`.zip` 压缩包自动展开并保留内部相对路径，展开后每个文件独立经过脱敏过滤链。客户端接入时建议同时上传「游戏主日志 + 崩溃报告 + 启动器日志」并注明 `source` 来源标识。上传响应中的 `token` 是删除该日志的唯一凭证；命中内容去重时响应会带 `deduplicated: true`，此时返回的 `token` 未写入存储、不能用于删除，请使用首次上传保存的凭证。Codex 结构化解析结果通过 `/v1/insights/{id}` 返回；AI 智能诊断由外部服务提供，本仓库不再暴露 `/v1/ai/*` 端点。

## 架构

### 目录结构

```
bin/hyperf.php            入口文件（Hyperf Application）
core.php                  引导文件（定义 CORE_PATH 并加载 Config）
app/                      核心类库（App\ 命名空间）
├── Cache/                Redis 缓存实现与协程连接池
├── Client/               Redis、SpinYarn 客户端
├── Controller/           HTTP 控制器（注解路由）
├── Data/                 数据模型（Token、MetadataEntry）
├── Filter/               预处理过滤链（敏感信息脱敏）
├── Middleware/           CORS 与限流中间件
├── Parser/               请求体解码（Brotli / Gzip / Deflate 自动解压与炸弹防护）
├── Process/              常驻事件消费者进程（EventQueueConsumer）
├── Queue/                统一日志异步事件队列（EventQueue、QueueEvent、DeadLetterQueue、Handler/）
├── ApiResponse.php       统一响应结构封装
├── Storage/              持久化存储后端（MariaDbStorage）
├── System/               系统核心服务（SecurityService、LogPartitionManager）
├── ApiError.php          API 业务异常（ApiExceptionHandler 统一渲染）
├── Config.php            配置加载器与环境变量覆盖
├── ContentParser.php     请求正文与启动器特征识别
├── Detective.php         日志类型与服务端版本探测
├── UploadParser.php      多文件安全校验与 ZIP 展开引擎
├── Log.php               日志核心模型与反混淆持久化
└── Id.php                ID 生成与存储后端编解码
config/autoload/          Hyperf 框架配置（server、databases、middlewares 等）
docker/                   Compose 编排、nginx 站点配置、镜像构建
OpenLiteWaf/              边缘 WAF 独立子模块（OpenResty Lua，CC 防御与攻击特征拦截）
OpenLiteStats/            边缘站点访问统计独立子模块（无锁位图 UV、流量统计与日志分析）
Config.inc.php            全部配置（gitignored）
Config.inc.example.php    配置模板
.env.example              环境变量模板（数据库、Redis 覆盖）
AGENTS.md                 项目核心架构约定与上下文文档
API.md                    完整 API 文档
CHANGELOG.md              更新日志
docs/                     历史过程文档与设计规划
```

### 请求处理流程

1. **上传**：`POST /v1/log` → RequestParser 解压请求体 → UploadParser 校验并展开 ZIP → 过滤链快速脱敏 → 执行轻量 Codex 特征分析 → 写入 MariaDB 与 Redis 缓存 → **即刻派发 `EVENT_LOG_UPLOADED` 事件** → 毫秒级向客户端返回 200 响应。
2. **异步流水线处理**：常驻进程 `EventQueueConsumer` 拉取事件 → 执行 `SecurityAuditHandler`（主正文与全部附件敏感规则检测，若违规即刻物理清除日志并通过 Syslog 记录原因、封禁恶意 IP，阻断后续流转）→ 执行 `DeobfuscateHandler`（调用 SpinYarn 原生反混淆并原子回写存储与刷新缓存）→ 异常自动重试，耗尽转入死信流 `events:log:dead`。
3. **读取**：`GET /v1/raw/{id}`（或子文件路径）→ Redis 缓存查询（如启用）→ 未命中时回源存储 → TTL 续期。
4. **分析**：`GET /v1/insights/{id}` → 加载日志 → Detective 检测服务端类型与版本 → Codex 引擎解析 → 格式化输出。
5. **删除**：`DELETE /v1/log/{id}` → Bearer Token 鉴权（SHA-256 哈希比对）→ 删除存储与缓存。

### ID 编码

日志 ID 为 7 位字符，首字符编码存储标识（当前仅 MariaDB）；其余 6 位为随机字符。字符集与长度可配置，但修改会破坏所有已有 ID，配置项 `id.characters` 与 `id.length` 不应改动。

### 限速

公开流量的限速由边缘承担：nginx `limit_req`（30r/s burst=60）与 OpenLiteWaf 的 CC 限流（10 秒窗口 240 次，超限返回 429 + `Retry-After`，**不封禁 IP**）。仓库中存在 `RateLimitMiddleware`（Redis INCR 按应用层计数，默认 600 次/60 秒，键为 IP + 方法 + 归一化路径，动态资源段折叠共享计数桶），但当前未注册到中间件链，属预留实现；如启用需注意其 Redis 故障路径为返回 503（fail-closed），与注释中的 fail-open 描述不符，见 `app/Middleware/RateLimitMiddleware.php`。

## 开发说明

### 测试

```bash
composer test               # Pest 测试套件
composer test:architecture  # Pest 架构约束测试
composer stan               # PHPStan 静态分析（level 7，覆盖 app/ scripts/ bin/）
```

测试覆盖过滤器、存储、上传解析、Codex 分析、EventQueue、Controller 集成与架构约束。

- 纯单测无需任何外部服务（Redis 缺失时自动使用内置 `RedisMock`）。
- 涉及真实 MariaDB / Redis 的用例会自动 `skip`：本地想跑全量请先

  ```bash
  docker compose --env-file .env -f docker/compose.yaml up -d mariadb redis
  php scripts/sync_mariadb_events.php
  ```

  再执行 `composer test`；`-` 与 `skipped` 数量会随本机服务状态变化，属预期。
- 可观测性口径：上传阶段只做 64KB 头部解析，全文 Codex 分析在首次访问 `/v1/insights/{id}` 时执行。

### 路由注册

在 `app/Controller/` 中新增控制器，通过注解声明路由：

```php
#[Controller(prefix: '/{version:v?1}')]
class ExampleController extends AbstractController
{
    #[GetMapping(path: 'example')]
    public function example(): ResponseInterface { ... }
}
```

`/{version:v?1}` 前缀同时匹配 `/1/` 与 `/v1/`；路径参数支持 `{param}`（单段）与 `{param:.+}`（含斜杠的通配段，用于子文件名）。

## 许可证

MIT
