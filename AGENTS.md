# LogShare — Agent Guide

面向 Minecraft / Hytale 的日志分享与本地 Codex 解析平台；AI 智能诊断已外包给外部服务。仓库主体是 PHP 后端、边缘 Lua 与 Docker 配置，没有前端构建。技术栈：PHP 8.4+ / Hyperf 3.2 / Swoole 6.2 常驻协程、MariaDB、Redis（可选）。完整目录树与用户文档见 `README.md`；API 契约以 `API.md`、`openapi.yaml`、`postman_collection.json` 为准。

## 入口与结构

- `bin/hyperf.php`：CLI 与服务入口；`core.php`：引导并加载 `Config.inc.php`；PSR-4 将 `App\` 映射到 `app/`。
- HTTP 监听 `0.0.0.0:9501`（`config/autoload/server.php`）。控制器使用 Hyperf 注解路由，前缀 `/{version:v?1}` 同时匹配已弃用的 `/1` 与 `/v1`。
- 存储后端固定为 MariaDB（`storage.storageId` = `s`）；日志 ID 首字符为存储标识。Redis 不是硬依赖，缺失时缓存与队列会降级。
- `OpenLiteWaf/`、`OpenLiteStats/` 是独立仓库的 git submodule（`cnb.cool/MornZe-Dev/*`），有自己的测试与发布流程；改动需在各自仓库提交，再回主仓更新指针。
- 参考文档：`OpenLiteWaf/README.md`、`docs/`、`CHANGELOG.md`；`LOCAL_DEV_NOTES.md` 仅本地、被 `.git/info/exclude` 排除，永不提交。

## 开发命令

```bash
git submodule update --init --recursive   # clone / pull 后必须
composer install
cp Config.inc.example.php Config.inc.php
php bin/hyperf.php start

composer test                 # Pest 全量
composer test:architecture    # 架构约束
composer stan                 # PHPStan level 7（app/ scripts/ bin/）
php vendor/bin/pest tests/Unit/FilterTest.php
php vendor/bin/pest --filter=TestName
```

- 测试引导在 `tests/bootstrap.php`：自动创建 `Config.inc.php`，未加载 ext-redis 时用 `RedisMock`。单测可离线跑；`tests/Integration` 的部分用例需要 MariaDB / Redis，缺失会 skip。`tests/Unit/ControllersUnitTest.php` 用反射直接实例化控制器，避免 Hyperf AOP 代理干扰覆盖率。
- 集成环境：先由 `.env.example` 准备 `.env`，再 `docker compose --env-file .env -f docker/compose.yaml up -d`。
- `RedisClient::setTestConnection()` 可让 Redis 相关单测固定走 `RedisMock`；压缩请求用例需要 ext-brotli（CI 已装）。
- Lua 回归：`php OpenLiteWaf/tests/openlitewaf_regex_test.php`、`lua5.1 OpenLiteWaf/tests/openlitewaf_logic_test.lua`、`lua5.1 OpenLiteStats/tests/openlitestats_logic_test.lua`、`luac5.1 -p OpenLiteWaf/lua/*.lua OpenLiteStats/lua/*.lua`。两套 Lua 测试 stub 了 `ngx`，只能验证流程/计数/作用域，真实 `ngx.re` 与请求体覆盖在 E2E。
- 仓库没有配置 formatter；改完代码至少跑相关 Pest、架构测试、PHPStan。`phpstan.neon` 里的 `ignoreErrors` 与 `SpinYarnClient` 排除是刻意保留的（Codex 类型层级、CI 无 spinyarn 扩展），不要随手删；level 7 已覆盖 `app/`、`scripts/`、`bin/`，新增文件必须保持零告警。

## 请求链路

- 上传：`POST /v1/log` → `RequestParser` 解压 → `UploadParser` 校验 / ZIP 展开（同名条目自动改名为 `name (2)`）→ `Log::put()` 内的 `preFilter` + **仅头部** Codex 解析（64KB，取版本/加载器）+ 存储 → `EventQueue::dispatch(EVENT_LOG_UPLOADED)` → 立即 200。全文分析留给 `/v1/insights` 按需执行。耗时安全审核与 SpinYarn 反混淆在 `EventQueueConsumer` 异步执行。
- 读取：`GET /v1/raw/{id}[/{filename}]` → Redis 缓存 → 存储回源并续期。`DELETE /v1/log/{id}` 校验 Bearer 删除 token：库里只存 `sha256:<hex>`，`Token::fromHash()` 永远只比较哈希，**存储值本身不能当凭证**（历史缺陷已修）。读路径不做分区 DDL，过期回收只走 MariaDB Event。

## 存储 / ID

- 日志 ID 固定 7 位：首字符为存储标识（当前仅 MariaDB）+ 6 位随机。不要改 `id.characters` 或 `id.length`，已有 ID 全依赖它们。
- 上传限制在落库前强制执行：10 MB / 50,000 行，最多 200 个文件、合计 12 MB；ZIP 必须继续防路径穿越与膨胀炸弹。
- `logs` / `log_files` / `log_metadata` 按 `created` 做天级 RANGE 分区，必须保留 `p_future` 兜底。分区表不能有外键，级联删除由 `MariaDbStorage` 显式完成（先子表后父表）；所有唯一键都必须包含 `created`；`Renew()` 必须同步子表 `created`，否则父表分区丢弃后会留下永久孤儿行。
- 过期清理只 `DROP PARTITION`。运行期绝不重建历史分区（会与幸存分区重名，ERROR 1517）；判断历史区间是否已铺过，用「最靠近今天的历史分区」而非分区计数；SQL 里 `PARTITION_DESCRIPTION` 必须 `CAST(... AS SIGNED)`，否则负 cutoff 静默失效；DROP 必须逐个执行（`information_schema` 陈旧视图会拼出已删分区名，ERROR 1064）。
- `logs.token` 存 `sha256:<hex>`（71 字符），列宽为 `VARCHAR(80)`；旧库升级需执行 `php scripts/widen_token_column.php`（幂等、只 ALTER，不清数据），否则上传会以 1406 失败。
- 改 `storage.storageTime` 后必须 `php scripts/sync_mariadb_events.php` 并重启 `mariadb-events` 容器。旧库结构不兼容，迁移用 `php scripts/migrate_partitioned_storage.php --yes`：它会 DROP 三表并重建，现有日志全丢，执行前先确认已备份。
- 上传去重按「正文 + 按名称排序的附件 + source」计算 `xxh128`，命中直接返回旧 ID；旧库缺 `fingerprint` 列时静默降级，不要阻断上传。

## 控制器 / API

- 控制器只用注入的 PSR-7 请求、`ContentParser`、Storage 抽象与 `AbstractController` 助手；禁止读 `$_SERVER` / `$_GET` / `$_POST`，禁止控制器内直接发 SQL（架构测试会挂）。
- 所有控制器必须继承 `AbstractController`；新增 Filter / Storage / Cache 类必须实现对应接口，架构测试会校验命名空间与继承关系。
- JSON / 结构化输入一律用 `$this->getParsedBody()`；它兼容 `text/plain` 等非 JSON Content-Type 的裸 JSON。预期业务失败抛 `App\ApiError`，由 `ApiExceptionHandler` 统一渲染。
- 仓库不提供应用层管理接口；安全能力由上传前置过滤与异步审核承担。旧版 `ai` / `github` 配置段在 `Config::load()` 中丢弃，避免遗留密钥泄露。
- 违规关键词 / 正则使用 AES-256-GCM 密文落盘（`enc:v1:...`），密钥来自 `SECURITY_ENCRYPTION_KEY` 或 `runtime/.security_secret`（0600）；不要改成明文存储，也不要吞掉解密失败异常。
- 内容去重命中时 `Log::put()` **不得**写缓存（`StorageInterface::Put()` 通过 `&$created` 告知是否新建），否则第二个上传者会拿到删除凭证；响应带 `deduplicated: true`。
- `Config::Get()` 只接受顶层段名，点路径会抛异常（用 `Config::GetPath('a.b')`）。
- 客户端真实 IP 只信 `security.trustedProxies` / `rateLimit.trustedProxies`（IPv4 与 IPv6 CIDR 都支持）；`X-Forwarded-For` 从右往左剥离受信代理。`RateLimitMiddleware` 目前未注册（中间件链上只有 `CorsMiddleware`），且 Redis 故障路径为 503 fail-closed，启用前先确认。
- 改路由或响应必须同步 `API.md`、`openapi.yaml`、`postman_collection.json`。

## 事件队列

- `LogController::create` 只做快速 `preFilter` + 轻量 Codex 分析 + 落库，然后派发事件并立即响应；耗时操作在 `EventQueueConsumer`（进程名 `event-queue-consumer`）中执行。异步审核命中违规会物理删除日志、阻断后续 handler 并封禁来源 IP。
- 流水线固定为 `log.uploaded -> SecurityAuditHandler -> DeobfuscateHandler`，不再提供通用监听器注册、优先级或闭包 handler。
- 成功即 `XACK` + `XDEL`；失败保留 pending 等待 reclaim。重试计数只存 `events:attempts:<streamId>`（7 天 TTL），达到 `eventQueue.maxAttempts` 后写入死信 `events:log:dead`；死信写入失败时保留 pending，绝不静默丢事件。
- `EventQueue::dispatch()` 在 Redis 可用时投递 Stream；队列关闭或 Redis 不可用时同步执行同一流水线，避免不可持久化的协程降级。
- 消费者只有 1 个 worker 协程与 1 个 reclaim 协程；`XAUTOCLAIM` 的空闲阈值为 5 分钟，避免长任务被重复认领。每处理 1000 条且无在途任务后优雅退出，由 Swoole manager 拉起释放 RSS。
- 主事件流 `XADD` 不做 MAXLEN 近似裁剪；积压增长是可见、可恢复的，静默丢事件不是。

## SpinYarn（可选反混淆）

- 只解析 `mappings/` 中已有映射，不自动下载；扩展缺失、版本不支持或初始化失败时原样透传（fail-open）。
- `spinyarn_init(...)` 只有 `redis_url` 非空才启用 Redis 共享映射缓存；否则每次按需解析，没有本地缓存。
- `SpinYarnClient::supportsRedisArg()` 通过反射区分 4 参与 5 参签名；v1.0.0 直接传 5 参会 ArgumentCountError。新增调用必须走它，不要假设扩展支持 Redis 参数。
- 初始化失败 60s 节流后重试；成功句柄复用，配置 hash（mappingsDir + redisUrl + 三水位）变化时重建，且新句柄成功才替换。

## 运行时 / 部署

- Swoole 是常驻进程：进程级缓存、扩展句柄、注解代理类都跨请求存活。改解析 / 反混淆 / 进程状态后必须重启，不能只改文件。
- 常驻进程外呼 cURL 一律 `CURLOPT_FORBID_REUSE => true`，并在 `finally` 中 `curl_close` / `$ch = null`，否则 FD 累积到 EMFILE；不要用 Swoole 协程 hook 不支持的选项（如 `CURLOPT_MAXFILESIZE`），在应用层限制载荷。
- `RedisClient` 显式设置 `OPT_READ_TIMEOUT`（默认 10s，高于消费者 2s/5s 阻塞等待），不要调低，否则会周期性断连。
- 诊断日志用 `App\Syslog::error()`，不要直接 `error_log()`；`runtime/logs/system.log` 有 5MB 轮转，不要再整文件 `file()`。
- 请求体解压统一走 `App\Parser\RequestParser`（20 MB 上限）；brotli 用单参 `brotli_uncompress($body)`，第二参是字典，传整数会 TypeError。
- 响应压缩由 Swoole `http_compression`（level 6、min 1024）优先 Brotli；nginx 只做 gzip 兜底并透传 `Accept-Encoding`。
- 配置统一走 `App\Config::Get()`；修改 `Config.inc.php` 后需重启 Swoole 进程生效，不要在进程启动时缓存一份副本。
- Docker 部署中 PHP 代码烘进 hyperf 镜像，改 PHP 必须重建镜像；`Config.inc.php`、`.env`、`mappings/`、`runtime/` 是挂载。重建前先 `rm -rf runtime/container` 清扫描缓存（尤其 `SCAN_CACHEABLE=true`）。
- Compose 的必填密码变量用 `${VAR:?}`，命令带 `--env-file .env`；`docker compose down -v` 会删 `logshare-mariadb-data` / `logshare-redis-data`，不要执行。
- 镜像内的 jemalloc（`LD_PRELOAD` + `MALLOC_CONF`）是为常驻进程 RSS 回落，改 Dockerfile 时保留。

## 边缘 WAF / 统计（submodule）

- WAF 入口在 `docker/nginx/default.conf` 的 `access_by_lua_file` 等；http 级指令在 `OpenLiteWaf/nginx/nginx.conf`（shared dict、`init_by_lua`）。容器镜像是 `openresty/openresty:1.27.1.2-alpine`，不是 stock nginx。
- 路径形态的探测规则（`.php`、`/.git/`、敏感文件扩展名）必须限定 `uri` / `path`；放宽到 body / UA 会封正常客户端。接收任意文本的端点做 body 豁免：log、analyse（URI / UA 仍匹配）。body 只扫前 64KB，`Content-Length > 2MB` 跳过（chunked 仍扫）；`/{version}/raw/{id}/{filename}` 通过 `is_raw_file_request` 绕过扩展名规则。
- CC 超限返回 429 + `Retry-After` 且不封 IP；特征命中返回 403，并按 `sig_strikes`（默认 3）累计才封。CC 阈值必须低于 nginx `limit_req`（30r/s），否则请求先被 503。
- 封禁与计数 60s 快照，重启后恢复；`docker exec logshare-nginx nginx -s reload` 在本部署不可靠，改 Lua 后用 `docker restart logshare-nginx`。重启不能清误封，走带 `OPENLITEWAF_ADMIN_TOKEN` 的 `POST /security/unban`（未配置 token 或长度不足时 404 fail-closed）。
- `OpenLiteStats` 记录通过请求，排除 `/security`、`/stats`；排除按路径段边界匹配，空 prefix 会关闭全站统计。热门端点聚合时模板化 ID，IP 脱敏，`OPTIONS` 不计。

## CI / 发布

- CI 在 CNB：`.cnb.yml` include `.cnb/{ci,docker-e2e,release}.yml`。`ci` 跑 Pest + 覆盖率、composer validate、PHPStan、架构测试、Swoole 启动冒烟；`docker-e2e` 构建生产镜像并跑全栈 E2E；`release` 由 `[Build]` 提交触发。
- 平台坑：子配置按「分支 + 事件」合并，整条流水线数组不能用 `!reference`（会静默产出空流水线）；`script` 由 sh/dash 执行，用 `set -eu`，不要用 `pipefail`；`services` 只有 docker/vscode，MariaDB/Redis 需自行 `docker run`，且 dind 与流水线容器网络不通（用同一自定义网络 + 容器名）；`docker:27-cli` 没有 curl/openssl；Stage 的 `if` 不继承，门控写在真正执行任务的 Stage 上。
- `docker.build` 指定的 Dockerfile 由 CNB 预构建并缓存；Stage 里不要再跑一次 `docker build`（会因缓存包冲突立即失败），用 `${CNB_PIPELINE_DOCKER_IMAGE}` 取已构建镜像。
- 覆盖率先 `--coverage-clover` 再用 `scripts/clover_to_lcov.php` 转 LCOV；`mariadb-events.sql` 含 `DELIMITER` 存储过程，只能由 mysql/mariadb 客户端导入。
- `.github/workflows/` 仍在：CNB 会把 main 同步回 GitHub 镜像仓库，Actions 仍会触发；`scripts/ci_init_mariadb.sh` 刻意剔除 `CALL logshare_install_partitions()` 以匹配既有分区测试，改动前先读脚本注释。
- 版本单一来源是 `app/Version.php`。发版时改 `CHANGELOG.md` 首条并同步 `README.md` / `openapi.yaml`，提交信息以 `[Build]` 开头；tag 与 Release notes 取自 CHANGELOG 首条。
- submodule 先在自身仓库提交推送，再回主仓更新指针（沿用 `chore: 更新 OpenLiteWaf submodule 指针（…）`）。

## 协作约定

- 用简体中文交流与推理；术语可保留英文。
- 开发环境是 Debian 13 容器（不是 Termux）：`/tmp` 可写，临时文件可用 `/tmp/` 或项目根 `tmp/`；兼容性问题先查证，拿不准先问。
- 涉及 SQL 注入、XSS、CSRF、路径遍历、敏感信息泄露时，先说明风险并征询处理意见，不得擅自忽略或掩盖。
- 任何可能破坏全局环境、数据完整性或安全性的操作（清库、`docker compose down -v`、重启生产等）必须先取得明确授权。
- 生产是 `api.logshare.cn`；服务器上的 `docker/nginx/default.conf` 域名与证书路径与仓库（`api.test.logshare.cn`）不同，是否合入等用户决定，不要擅自“修正”。仓库即线上。
- `/security` 拦截页卡片（橙色 `#ea580c`、三角 SVG、“我们认为您的请求是恶意的”）是用户亲自提供的设计，改前先问。
- 文档与文案用书面化开发者风格，平铺直叙，不写营销腔；本文件是项目上下文约定来源，行为变更时同步更新。
