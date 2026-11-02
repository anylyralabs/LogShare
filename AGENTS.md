# LogShare — Agent Guide

面向 Minecraft / Hytale 的日志分享与本地 Codex 解析平台；AI 智能诊断已外包给外部服务。仓库主体是 PHP 后端、边缘 Lua 与 Docker 配置，没有前端构建。技术栈：PHP 8.4+ / Hyperf 3.2 / Swoole 6.2 常驻协程、MongoDB 7.0+、Redis（可选）；MongoDB 网络 I/O 由 GoTask Go 边车承担。完整目录树与用户文档见 `README.md`；API 契约以 `API.md`、`openapi.yaml`、`postman_collection.json` 为准。

## 入口与结构

- `bin/hyperf.php`：CLI 与服务入口；`core.php`：引导并加载 `Config.inc.php`；PSR-4 将 `App\` 映射到 `app/`。
- HTTP 监听 `0.0.0.0:9501`（`config/autoload/server.php`）。控制器使用 Hyperf 注解路由，前缀 `/{version:v?1}` 同时匹配已弃用的 `/1` 与 `/v1`。
- 存储后端固定为 MongoDB（`storage.storageId` = `m`）；日志 ID 首字符为存储标识。Redis 不是硬依赖，缺失时读缓存降级。
- MongoDB 访问分两条路径：HTTP CRUD 经 `App\Client\Mongo\MongoGateway`（生产为 GoTask 边车）；索引 / TTL / compact / ping 由 `MongoStorage` 保留的运维方法走 ext-mongodb，只在 CLI 与独立进程调用。
- `gotask/` 是独立 Go module，编译 `bin/logshare-gotask`；`app/Client/GoTask/` 是可靠的 Goridge 传输实现（循环写满 frame），`config/autoload/gotask.php` 控制边车生命周期。
- `OpenLiteWaf/`（当前 1.3.1）、`OpenLiteStats/`（当前 1.2.0）是独立仓库的 git submodule（GitHub `anylyralabs/*`），有自己的测试与发布流程；改动需在各自仓库提交，再回主仓更新指针。
- 参考文档：`OpenLiteWaf/README.md`、`docs/`、`CHANGELOG.md`；`LOCAL_DEV_NOTES.md` 仅本地、被 `.git/info/exclude` 排除，永不提交。

## 开发命令

```bash
git submodule update --init --recursive   # clone / pull 后必须
composer install
cp Config.inc.example.php Config.inc.php
php scripts/init_mongodb.php   # 索引 / TTL / 磁盘回收
php bin/hyperf.php start

./scripts/build_gotask.sh      # 可选：本地编译 GoTask 边车
GOTASK_ENABLE=true MONGODB_DRIVER=gotask php bin/hyperf.php start

composer test                 # Pest 全量
composer test:architecture    # 架构约束
composer stan                 # PHPStan level 7（app/ scripts/ bin/）
php vendor/bin/pest tests/Unit/FilterTest.php
php vendor/bin/pest --filter=TestName
```

- 测试引导在 `tests/bootstrap.php`：自动创建 `Config.inc.php`，未加载 ext-redis 时用 `RedisMock`。单测可离线跑；`tests/Integration` 与 `MongoStorageTest` 需要 MongoDB / Redis，缺失会 skip。默认 `mongo.driver=native`；验证 GoTask 时设置 `MONGODB_DRIVER=gotask`、`GOTASK_SOCKET=...` 并先启动边车。`tests/Unit/ControllersUnitTest.php` 用反射直接实例化控制器，避免 Hyperf AOP 代理干扰覆盖率。
- 集成环境：先由 `.env.example` 准备 `.env`，再 `docker compose --env-file .env -f docker/compose.yaml up -d`。
- `RedisClient::setTestConnection()` 可让 Redis 相关单测固定走 `RedisMock`；压缩请求用例需要 ext-brotli（CI 已装）。
- Lua 回归：`php OpenLiteWaf/tests/openlitewaf_regex_test.php`、`lua5.1 OpenLiteWaf/tests/openlitewaf_logic_test.lua`、`lua5.1 OpenLiteStats/tests/openlitestats_logic_test.lua`、`luac5.1 -p OpenLiteWaf/lua/*.lua OpenLiteStats/lua/*.lua`。两套 Lua 测试 stub 了 `ngx`，只能验证流程/计数/作用域，真实 `ngx.re` 与请求体覆盖在 E2E。
- 修改 Go 代码后跑 `cd gotask && go vet ./... && go build ./cmd/logshare-gotask`；CI 会安装 Go 1.24 并编译边车。
- 仓库没有配置 formatter；改完代码至少跑相关 Pest、架构测试、PHPStan。`phpstan.neon` 只排除 `SpinYarnClient`（CI 无 spinyarn 扩展）；level 7 已覆盖 `app/`、`scripts/`、`bin/`，新增文件必须保持零告警。

## 请求链路

- Codex 翻译：`app/Detective.php` 在构造时读取 `codex.language`（默认 `zh-CN`）并调用 `codex-minecraft` 的 `Translator::setLanguage()`；问题/解决方案/类型标签才会是中文。`aternos/codex` 核心无翻译层，`aternos/codex-hytale` 没有语言包。
- 上传：`POST /v1/log` → `RequestParser` 解压 → `ContentParser` 解析 `files` / 旧版 `content` / `metadata` / `source` → `UploadParser` 校验 / ZIP 展开（同名条目自动改名为 `name (2)`）→ `Log::put()` 逐文件 `preFilter` + SpinYarn 反混淆 → `MongoStorage::Put()` 经 `MongoGateway` 存储 → 200。旧版 `content` 会映射为 `latest.log`，与 `files` 同时存在时作为主文件；没有异步队列、没有应用层内容审核；全文 Codex 分析只在 `/v1/insights/{id}[/{filename}]` 按需执行。
- 读取：`GET /v1/log/{id}` 返回文件列表；`GET /v1/raw/{id}` 单文件返回内容、多文件返回 200 + 文件列表；带文件名才取指定文件。`GET /v1/insights/{id}` 同理，多文件先列文件，`/insights/{id}/{filename}` 才执行 Codex。`DELETE /v1/log/{id}` 校验 Bearer 删除 token：库里只存 `sha256:<hex>`，`Token::fromHash()` 永远只比较哈希，**存储值本身不能当凭证**。读路径不做清理或 DDL；过期由 MongoDB TTL 索引处理，`autoCompact` 可用时由其持续回收（MongoDB 8.0 + 相应权限）；否则由 `MongoCompactProcess` 周期性 `compact` 兜底。

## Codex 中文本地化

- 只有 Fabric / Quilt Loader 的 Mod Resolution 文本会随 Locale 输出中文；Fabric/Quilt 的中文问题由 `app/Codex/Analysis/Problem/Fabric/` 下的子类直接解析，不修改 `vendor/`。
- `Log::analyseContent()` 会按日志类型选择 `App\Codex\Analyser\FabricAnalyser`、`FabricCrashReportAnalyser`、`QuiltAnalyser`、`QuiltCrashReportAnalyser`；新增 Fabric/Quilt 变体时同步扩展这些 analyser 与自定义 Insight。
- 启动器可能只保存无日志头的 resolution 文本，`App\Codex\FabricResolutionReport` 负责回退识别；不要把普通文本误判为 Fabric。
- `App\Codex\Translation\TranslatorOverlay` 在 `App\Detective` 初始化时补齐 Codex 缺失的 `datapack-parsing-problem` 与 Fabric dependency 文案；升级 `codex-minecraft` 后必须重跑翻译覆盖测试。
- 输入覆盖矩阵与维护步骤见 `docs/codex-zh-coverage.md`；回归用例见 `tests/Unit/CodexChineseAnalysisTest.php` 与 `tests/Unit/CodexTranslationCoverageTest.php`。
- 非 Fabric/Quilt 的 Codex pattern 是英文技术日志/异常类名，不因为客户端语言变化而本地化；不要为了“全中文”给它们编造中文正则，输出翻译由 Translator 统一处理。

## 存储 / ID

- 日志 ID 固定 7 位：首字符为存储标识（当前仅 MongoDB）+ 6 位随机。不要改 `id.characters` 或 `id.length`。
- 上传限制在落库前强制执行：最多 200 个文件、展开后合计 20 MiB、请求体解压后 32 MiB；不限制单个文件大小或行数。ZIP 必须继续防路径穿越与膨胀炸弹。单文件可能超过 16 MiB，`MongoStorage` 按 8 MiB 拆块写入 `log_chunks`。
- `logs` 父文档保存 token、来源、指纹、metadata 与文件描述；文件内容写入 `log_chunks`，单块 8 MiB，规避 MongoDB 16 MiB BSON 上限。父文档先以 `status=pending` 占住 `_id`，分块完成后再置为 `ready`；读取只认 `ready`。
- 分块写入按 `MongoStorage::CHUNK_BATCH_BYTES`（约 4 MiB）分批，单个 8 MiB 分块仍会单独成批；不要退回一次性把全部文件塞进一个 IPC frame。
- 父文档与分块都带 BSON Date 类型的 `created`，各自建立 TTL 索引；TTL 的 `expireAfterSeconds` 是 `storageTime + 120` 秒宽限，查询仍按 `storageTime` 过滤逻辑过期。`Renew()` 先更新分块、再更新父文档。
- `scripts/init_mongodb.php` 创建/同步索引与 TTL，并尝试启用 MongoDB 8.0 `autoCompact`；`MongoCompactProcess` 在 autoCompact 不可用（含 7.x）时按 `mongo.compactInterval` 周期性执行 `compact`。磁盘自动收缩依赖二者，不要删除。
- `logs.token` 存 `sha256:<hex>`（71 字符）；MongoDB 字符串没有列宽限制，但格式与哈希比较逻辑保持不变。
- 改 `storage.storageTime` 后重启 Hyperf，启动脚本会通过 `collMod` 更新 TTL。旧 MariaDB 日志不迁移，旧 `s` 开头 ID 不可读。
- 上传去重按「按名称排序的文件集合 + source」计算 `xxh128`，只匹配 `ready` 且未过期的父文档。

## 控制器 / API

- 控制器只用注入的 PSR-7 请求、`ContentParser`、Storage 抽象与 `AbstractController` 助手；禁止读 `$_SERVER` / `$_GET` / `$_POST`，禁止控制器内直接发 SQL（架构测试会挂）。
- 所有控制器必须继承 `AbstractController`；新增 Filter / Storage / Cache 类必须实现对应接口，架构测试会校验命名空间与继承关系。
- JSON / 结构化输入一律用 `$this->getParsedBody()`；它兼容 `text/plain` 等非 JSON Content-Type 的裸 JSON。预期业务失败抛 `App\ApiError`，由 `ApiExceptionHandler` 统一渲染。
- 仓库不提供应用层管理接口；应用层内容审核已移除，安全能力由 IP 黑名单与边缘 WAF 承担。旧版 `ai` / `github` 配置段在 `Config::load()` 中丢弃，避免遗留密钥泄露。
- 内容去重命中时 `Log::put()` **不得**写缓存（`StorageInterface::Put()` 通过 `&$created` 告知是否新建），否则第二个上传者会拿到删除凭证；响应带 `deduplicated: true`。
- `Config::Get()` 只接受顶层段名，点路径会抛异常（用 `Config::GetPath('a.b')`）。
- 客户端真实 IP 只信 `security.trustedProxies` / `rateLimit.trustedProxies`（IPv4 与 IPv6 CIDR 都支持）；`X-Forwarded-For` 从右往左剥离受信代理。`RateLimitMiddleware` 目前未注册（中间件链上只有 `CorsMiddleware`），且 Redis 故障路径为 503 fail-closed，启用前先确认。
- 改路由或响应必须同步 `API.md`、`openapi.yaml`、`postman_collection.json`。

## SpinYarn（可选反混淆）

- 只解析 `mappings/` 中已有映射，不自动下载；扩展缺失、版本不支持或初始化失败时原样透传（fail-open）。
- `spinyarn_init(...)` 只有 `redis_url` 非空才启用 Redis 共享映射缓存；否则每次按需解析，没有本地缓存。
- `SpinYarnClient::supportsRedisArg()` 通过反射区分 4 参与 5 参签名；v1.0.0 直接传 5 参会 ArgumentCountError。新增调用必须走它，不要假设扩展支持 Redis 参数。
- 初始化失败 60s 节流后重试；成功句柄复用，配置 hash（mappingsDir + redisUrl + 三水位）变化时重建，且新句柄成功才替换。

## 运行时 / 部署

- Swoole 是常驻进程：进程级缓存、扩展句柄、注解代理类都跨请求存活。改解析 / 反混淆 / 进程状态后必须重启，不能只改文件。
- `ext-mongodb` 基于 libmongoc，Swoole 的协程 hook 不生效；HTTP worker 内不要直接调用 `MongoDBClient` / `NativeGateway`。生产固定 `MONGODB_DRIVER=gotask`，GoTask 边车不可用时返回 503，不静默回退原生驱动。
- `App\Client\GoTask\ReliableCoroutineSocketRelay` 必须保留循环 send / 定长 recv：上游 `hyperf/gotask` 的 `SocketTransporter::send()` 只调用一次 `send()`，数百 KB 以上 payload 会写不完整并导致 60s 超时。
- 索引 / TTL / autoCompact / compact 仍使用 ext-mongodb，但只允许在 `scripts/init_mongodb.php`、`MongoCompactProcess` 和测试中使用，不要放进 HTTP 请求链路。
- 常驻进程外呼 cURL 一律 `CURLOPT_FORBID_REUSE => true`，并在 `finally` 中 `curl_close` / `$ch = null`，否则 FD 累积到 EMFILE；不要用 Swoole 协程 hook 不支持的选项（如 `CURLOPT_MAXFILESIZE`），在应用层限制载荷。
- `RedisClient` 显式设置 `OPT_READ_TIMEOUT`（默认 10s），不要调低，否则会周期性断连。
- 诊断日志用 `App\Syslog::error()`，不要直接 `error_log()`；`runtime/logs/system.log` 有 5MB 轮转，不要再整文件 `file()`。
- 请求体解压统一走 `App\Parser\RequestParser`（默认 32 MiB 上限，可由 `storage.uploadFiles.maxRequestBodyBytes` 调整）；brotli 用单参 `brotli_uncompress($body)`，第二参是字典，传整数会 TypeError。
- 响应压缩由 Swoole `http_compression`（level 6、min 1024）优先 Brotli；nginx 只做 gzip 兜底并透传 `Accept-Encoding`。
- 配置统一走 `App\Config::Get()`；修改 `Config.inc.php` 后需重启 Swoole 进程生效，不要在进程启动时缓存一份副本。
- Docker 部署中 PHP 代码烘进 hyperf 镜像，改 PHP 必须重建镜像；`Config.inc.php`、`.env`、`mappings/`、`runtime/` 是挂载。重建前先 `rm -rf runtime/container` 清扫描缓存（尤其 `SCAN_CACHEABLE=true`）。
- Compose 的必填密码变量用 `${VAR:?}`，命令带 `--env-file .env`；`docker compose down -v` 会删 `logshare-mongo-data` / `logshare-redis-data`，不要执行。
- 镜像内的 jemalloc（`LD_PRELOAD` + `MALLOC_CONF`）是为常驻进程 RSS 回落，改 Dockerfile 时保留。

## 边缘 WAF / 统计（submodule）

- WAF 入口在 `docker/nginx/default.conf` 的 `access_by_lua_file` 等；http 级指令在 `OpenLiteWaf/nginx/nginx.conf`（shared dict、`init_by_lua`）。容器镜像是 `openresty/openresty:1.27.1.2-alpine`，不是 stock nginx。
- 路径形态的探测规则（`.php`、`/.git/`、敏感文件扩展名）必须限定 `uri` / `path`；放宽到 body / UA 会封正常客户端。接收任意文本的端点做 body 豁免：log（URI / UA 仍匹配）。body 只扫前 64KB，`Content-Length > 2MB` 跳过（chunked 仍扫）；`/{version}/raw/{id}/{filename}` 通过 `is_raw_file_request` 绕过扩展名规则。
- CC 超限返回 429 + `Retry-After` 且不封 IP；特征命中返回 403，并按 `sig_strikes`（默认 3）累计才封。CC 阈值必须低于 nginx `limit_req`（30r/s），否则请求先被 503。
- 封禁与计数 60s 快照，重启后恢复；`docker exec logshare-nginx nginx -s reload` 在本部署不可靠，改 Lua 后用 `docker restart logshare-nginx`。重启不能清误封，走带 `OPENLITEWAF_ADMIN_TOKEN` 的 `POST /security/unban`（未配置 token 或长度不足时 404 fail-closed）。
- `OpenLiteStats` 记录通过请求，排除 `/security`、`/stats`；排除按路径段边界匹配，空 prefix 会关闭全站统计。热门端点聚合时模板化 ID，IP 脱敏，`OPTIONS` 不计。

## CI / 发布

- CI 主入口在 GitHub Actions：`.github/workflows/` 跑 Pest、composer、PHPStan、架构测试、Swoole 冒烟与 Docker E2E；Hyperf 冒烟任务会安装 Go、编译边车并用 `MONGODB_DRIVER=gotask --large-file` 验证 9 MiB 分块上传；`.cnb/` 配置保留为可选/历史流水线。
- 平台坑：子配置按「分支 + 事件」合并，整条流水线数组不能用 `!reference`（会静默产出空流水线）；`script` 由 sh/dash 执行，用 `set -eu`，不要用 `pipefail`；`services` 只有 docker/vscode，MongoDB/Redis 需自行 `docker run`，且 dind 与流水线容器网络不通（用同一自定义网络 + 容器名）；`docker:27-cli` 没有 curl/openssl；Stage 的 `if` 不继承，门控写在真正执行任务的 Stage 上。
- `docker.build` 指定的 Dockerfile 由 CNB 预构建并缓存；Stage 里不要再跑一次 `docker build`（会因缓存包冲突立即失败），用 `${CNB_PIPELINE_DOCKER_IMAGE}` 取已构建镜像。
- 覆盖率先 `--coverage-clover` 再用 `scripts/clover_to_lcov.php` 转 LCOV。
- 远端已迁移到 GitHub，`.github/workflows/` 直接触发；CI 与生产 Compose 均使用兼容 ARM64 39-bit VA 的 `mongo:7.0`；`scripts/init_mongodb.php` 初始化索引/TTL，`MongoCompactProcess` 周期性 `compact` 完成磁盘回收。MongoDB 8.0 且账号具备权限时会优先使用内置 `autoCompact`。
- 版本单一来源是 `app/Version.php`。发版时改 `CHANGELOG.md` 首条并同步 `README.md` / `openapi.yaml`，提交信息以 `[Build]` 开头；tag 与 Release notes 取自 CHANGELOG 首条。
- 版本策略：默认不主动迭代。补充性小改动发 `_supplement.N`（如 `1.8.8_supplement.1`）；非重要问题修复发 `_fix.N`；重要问题修复发 `_hotfix.N`。只有用户明确要求迭代时才递增常规版本。
- 发布包用 `git archive` 分别导出主仓与两个子模块后合并，确保 submodule 内容不会在归档中缺失；资产名 `LogShare-v<version>.tar.gz`，并附 `.sha256` 校验文件。
- submodule 先在自身仓库提交推送，再回主仓更新指针（沿用 `chore: 更新 OpenLiteWaf submodule 指针（…）`）。

## 协作约定

- 用简体中文交流与推理；术语可保留英文。
- 开发环境是 Debian 13 容器（不是 Termux）：`/tmp` 可写，临时文件可用 `/tmp/` 或项目根 `tmp/`；兼容性问题先查证，拿不准先问。
- 涉及 SQL 注入、XSS、CSRF、路径遍历、敏感信息泄露时，先说明风险并征询处理意见，不得擅自忽略或掩盖。
- 任何可能破坏全局环境、数据完整性或安全性的操作（清库、`docker compose down -v`、重启生产等）必须先取得明确授权。
- 生产是 `api.logshare.cn`；服务器上的 `docker/nginx/default.conf` 域名与证书路径与仓库（`api.test.logshare.cn`）不同，是否合入等用户决定，不要擅自“修正”。仓库即线上。
- `/security` 拦截页卡片（橙色 `#ea580c`、三角 SVG、“我们认为您的请求是恶意的”）是用户亲自提供的设计，改前先问。
- 文档与文案用书面化开发者风格，平铺直叙，不写营销腔；本文件是项目上下文约定来源，行为变更时同步更新。
