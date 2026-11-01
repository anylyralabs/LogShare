# Changelog

## 1.8.5 — 2026-11-01

### 变更

- **纯文件模型**：上传只接受 `files`，移除 `content` 正文、主文件与旧日志兼容；`logs` 表不再保存 `data`，所有内容由 `log_files` 承载。
- **移除异步事件队列**：删除 EventQueue、Redis Streams 消费者、安全审核 handler 与反混淆 handler；上传时逐文件同步执行 SpinYarn 反混淆。
- **移除应用层内容审核**：不再加载关键词/正则内容规则；内容防护由边缘 OpenLiteWaf 承担。
- **按文件访问**：`GET /v1/log/{id}` 返回文件列表；单文件 `raw`/`insights` 自动选择，多文件返回 `200` + 文件列表；新增 `/v1/insights/{id}/{filename}`。
- **移除 `POST /v1/analyse`**：分析只针对已存储文件的指定文件名执行。
- **限额调整**：最多 200 个文件、展开后合计 20 MiB、请求体解压后 32 MiB；不限制单个文件大小或行数。
- **部署要求**：单文件可能超过 16 MiB，MariaDB 需设置 `max_allowed_packet=64M`；旧 `logs.data` 结构需清空重建。

### 修复

- **表单上传的 metadata/source 不再丢失**：`application/x-www-form-urlencoded` 现在与 JSON 共用结构化解析，`metadata`、`source`、`files` 都会正常进入落库流程。
- **metadata 校验与长度口径**：拒绝空 key（首尾空白 trim 后为空），key / label / value 按字符数限制为 64 / 128 / 1024；`visible` 在表单中兼容 `1/0`、`true/false` 等写法。
- **OpenAPI 与实现对齐**：`MetadataEntry` 修正 key/value 限制，补 `label`、`visible`、JSON 值类型、最多 100 条，以及表单上传支持说明。
- **API 文档强化 metadata 地位**：明确 metadata 是分析、展示与排查的核心结构化输入，建议客户端上传 version、loader、launcher、modpack 与崩溃上下文；`visible=false` 不公开。

## 1.8.4_hotfix.1 — 2026-11-01

### 修复

- **CI 干净 checkout 失败**：`tmp/` 被 `.gitignore` 忽略，GitHub Actions 上不存在，导致 `ConfigValidationTest` 写临时配置失败、ZIP 解析测试返回空结果；测试 bootstrap 现在自动创建 `tmp/`。
- **PHPStan `$argv` 未定义告警**：`scripts/migrate_partitioned_storage.php` 与 `scripts/clover_to_lcov.php` 改用 `$_SERVER['argv'] ?? []`，不再依赖 CLI SAPI 全局变量。
- **PHP 8.5 弃用警告**：移除测试中的 `ReflectionMethod::setAccessible()` 调用；该调用自 PHP 8.1 起已无实际作用。

## 1.8.4 — 2026-11-01

第四期 Code Review 修复与 API 精简：5 项严重、21 项一般、16 项建议全部处理；同时移除应用层 Admin API、客户端遥测、过滤器/限流测试端点、文件系统存储后端与动态配置热更新。

### 安全

- **去重命中时不再覆盖删除凭证**：`StorageInterface::Put()` 新增 `&$created` 出参，命中内容指纹时 `Log::put()` 跳过缓存写入；此前第二个上传者可用自己的 token 删除他人日志（IDOR）。
- **删除 token 一律只比较哈希**：`Token` 改用 `fromHash()` 构造，存储格式为 `sha256:<hex>`，库里的值本身不再能作为凭证；旧记录在验证成功后惰性补前缀。
- **配置类名校验**：`filter.pre`、`storage.storages.*.class`、`cache.cacheId` 必须是存在且实现对应接口的类，避免一次误配置把上传打成 500。
- **IPv6 CIDR 支持**：`SecurityService::ipInCidr()` 覆盖 IPv6 网段，修复反代场景下按代理 IP 聚合封禁/限流。
- 依赖升级修复 `symfony/http-foundation` 的 ReDoS（CVE-2026-108082）。

### 修复

- **同名附件 / 重复元数据键不再打成 500**：`UploadParser` 自动改名为 `name (2)`，`MetadataEntry::allFromArray()` 与 `MariaDbStorage` 双重去重。
- **读路径不再执行分区 DDL**：`Log::renew()` 移除 1% 概率触发的 `ALTER TABLE`，回收只走 MariaDB Event；`dropPartition()` 只统计真正成功的分区。
- **`MariaDbStorage::Update()` 不再谎报成功**：记录不存在时返回 `false`。
- **`source` 按字符截断**（`mb_strcut`），中文启动器名不再被切成非法 UTF-8。
- **ZIP 解压改为流式读取**，避免按声明大小一次性分配内存。
- **公开元信息过滤 `visible=false`**：`MetadataEntry::serializeVisible()`。
- **`Config::Get()` 拒绝点路径**，嵌套读取统一用 `Config::GetPath()`。
- **子表关联补 `created`**，版本/加载器统计与 `filesCount` 不再跨分区串行。
- **上传路径改为只解析 64KB 头部**：3.5MB 日志从 1.9s 降到 13ms，全文分析移到 `/v1/insights` 首次访问。

### 队列

- **固定流水线**：只保留 `log.uploaded -> SecurityAuditHandler -> DeobfuscateHandler`，移除通用监听器注册、优先级、闭包 handler 与 legacy 事件；`QueueEvent` 不再承载重试次数。
- **同步降级**：Redis 可用时投递 Stream；队列关闭或 Redis 不可用时在请求内同步执行同一流水线，不再使用不可持久化的协程降级。
- **消费与重试简化**：单 worker + 单 reclaim 协程，删除僵尸消费者清理、空闲/内存回收与强制排空；每处理 1000 条且无在途任务后优雅退出。
- **重试语义统一**：重试次数只存 `events:attempts:<streamId>`（7 天 TTL），达到 `eventQueue.maxAttempts`（默认 3）后进入死信；死信写入失败时保留 pending。
- **避免重复处理**：`XAUTOCLAIM` 空闲阈值改为 5 分钟，长任务不再因 60 秒 reclaim 被并发重复执行。
- **主队列不裁剪**：`XADD` 不再使用 `MAXLEN ~ 10000`，避免积压时静默裁掉未消费或 pending 事件。
- **RedisStreams 收敛**：删除 `xLen`、`xInfoGroups`、`xInfoConsumers`、`xGroupDelConsumer`、`xRead`、`xPending` 及队列辅助键透传等无调用方法。

### 移除

- **应用层 Admin API 整体移除**：不再注册 `/{version}/admin/*`，删除 `AdminController`、`AdminAuthMiddleware`、Admin 配置段与 `ADMIN_ENABLED` / `ADMIN_TOKEN`；日志管理、配置管理、分析、存储健康、缓存清理、安全规则管理、SpinYarn 测试、审计日志、事件队列与死信管理接口全部下线。
- **Admin 专用服务清理**：删除 `AnalyticsService`、`StorageHealthService`、`SystemLogManager`、`SpinYarnManager`、`AuditLogManager`；异步安全审核原因改由 `Syslog` 记录。
- **队列与存储管理查询清理**：删除 `DeadLetterQueue` 的 list/retry/clear/count、`EventQueue::getStats/getMetrics`、`StorageInterface::List/Count` 及实现；死信仍由消费者内部 `push()` 归档。
- **客户端遥测移除**：删除 `POST /{version}/telemetry/report`、`TelemetryController`、`TelemetryService` 与 `telemetry:*` 聚合逻辑；旧 Redis 键按 7 天 TTL 自然过期。
- **元接口精简**：删除 `GET /{version}/filters` 与 `GET /{version}/errors/rate`；`/limits` 保留。内容脱敏过滤链本身不受影响。
- **文件系统存储后端移除**：删除 `FilesystemStorage`、`filesystem` 配置段与 `storage.storages.f`；存储固定为 MariaDB。旧 `f` 开头的文件系统日志不再可读，需自行迁移。
- **动态配置热更新移除**：删除 `Config::saveDynamic/resetDynamic/getMasked/ensureFresh` 等机制；配置统一由 `Config.inc.php` 与环境变量提供，修改后重启 Swoole 进程生效。
- **AI / RAG 子系统剥离**：移除 LogAgent、Agent 工具链、AI 分析微队列、SSE 输出层、AI 指标、RAG MCP `/rag` 与相关管理接口；AI 功能由外部服务承接。

### 升级须知

- 已有部署必须先执行 `php scripts/widen_token_column.php`，把 `logs.token` 从 `VARCHAR(64)` 扩到 `VARCHAR(80)`；该脚本幂等且只做 `ALTER TABLE`，不清数据。未执行时上传会以 `1406 Data too long` 失败。
- Admin API、`/telemetry/report`、`/filters`、`/errors/rate` 均返回 404；依赖这些接口的控制台或客户端需同步下线或改造。
- 启动前删除 `Config.inc.php` 中的 `admin`、`filesystem` 段与 `storage.storages.f`；旧 `runtime/dynamic_config.json` 中需要保留的配置请合并进 `Config.inc.php` 后删除该文件。
- 文件系统后端的旧日志不会自动迁移到 MariaDB。

### 构建与 CI

- GitHub Actions 改用 `scripts/ci_init_mariadb.sh` + mariadb 客户端导入，修复 `DELIMITER` 语句导致的必失败。
- CNB 增加 Lua 回归 stage（WAF 规则 99 样本 + 两个逻辑套件）。
- 覆盖率不再排除 `Storage` / `Data` / `Cache` / `Client`，风险最高的层恢复计入。
- `composer.json` 补齐 `ext-curl` / `ext-openssl` / `ext-zip`，`license` 与 `LICENSE`（MIT）对齐。

### 文档

- README / API.md / openapi.yaml / Postman 同步删除 Admin、遥测、过滤器与 `/errors/rate` 契约；版本统一为 1.8.4。
- `plan.md` 归档为 `docs/archive-rag-plan-2026-08.md` 并标注历史状态；根目录废弃的 AI/RAG 计划 `Plan.md` 删除。
- 存储与部署文档改为仅 MariaDB；`docker/mariadb-events.sql` 按当前 TTL 重新生成分区布局。
- 封禁文件改为原子写入并 `chmod 0600`；WAF 内部调用的 `Host` 改为从 `urls.apiBaseUrl` 推导，测试部署不再需要改代码。

### 仓库与子模块

- 主仓与两个子模块的远端迁移至 GitHub `anylyralabs`；`.gitmodules` 与子模块 origin 同步更新。
- 当前子模块版本：`OpenLiteWaf` 1.3.1、`OpenLiteStats` 1.2.0。
- `OpenLiteWaf` / `OpenLiteStats` 移除已下线 AI、telemetry、admin 端点的 body 豁免与统计排除项，测试临时目录从 Termux 路径统一到 `/tmp`。

### 保留

- Codex 本地日志解析能力：`POST /v1/analyse`、`GET /v1/insights/{id}`，以及上传时的格式/版本识别。
- 日志上传、MariaDB 存储、读取、删除、脱敏过滤、EventQueue、SpinYarn 反混淆与边缘 WAF/统计。

## 1.8.3 — 2026-10-13

### 新增

- **MariaDB 改为按 `created` 天级 RANGE 分区，空间可真正归还操作系统**：`logs` / `log_files` / `log_metadata` 三表均按天分区，清理过期日志由逐行 `DELETE` 改为丢弃整日分区。这是磁盘占用只增不减的根治手段——InnoDB 的 `DELETE` 只在 `.ibd` 文件内部留下空洞并等待复用，永不把空间还给操作系统（实测删除 171MB 数据后磁盘占用纹丝不动）；`DROP PARTITION` 则直接删除分区的 `.ibd` 文件，是元数据级的瞬时操作。实测 238MB 数据在分区回收后降至 6MB。
- **日志内容去重**：上传时对「正文 + 附加文件 + source」计算 `xxh128` 指纹并落库建索引，命中既有指纹时直接返回同一 ID，不再写入新行。指纹计算在应用侧完成（10MB 正文开销在毫秒级），查重是一次与日志总量无关的 B-tree 定位，低压力；附加文件按名称排序参与计算，使同一批文件无论上传顺序如何都能命中。
- **`GET /v1/admin/system/storage-health` 增强**：新增 `dataFreeBytes`、`fragmentationRatio` 与 `partitions`（各表日分区数、覆盖的时间边界、兜底分区占用），用于判断磁盘压力来源与分区维护是否正常运行。
- **一次性迁移脚本 `scripts/migrate_partitioned_storage.php`**：把旧版非分区表重建为分区结构，带 `--yes` 确认保护与幂等判据。

### 变更

- **表结构不兼容**：主键追加分区键（`(id, created)` / `(log_id, name, created)` / `(log_id, key, created)`）；因 MariaDB 分区表不支持外键（ERROR 1506），原 `ON DELETE CASCADE` 约束全部移除，级联删除改由应用层显式完成。旧库需清空重建，见迁移脚本。
- **`scripts/sync_mariadb_events.php` 改为生成存储过程** `logshare_cleanup_expired()`，分区名按天动态生成，无法用静态 SQL 表达；`cleanup_expired_logs` 事件名与 `ENABLED` 状态不变，健康检查与既有测试不受影响。修改 `storage.storageTime` 后需重新执行该脚本并重启 `mariadb-events` 容器。

### 修复

- **SpinYarn 接入审查修复**：`getHandle` 初始化失败不再永久缓存 false，失败后 60s 节流、过期自动重试，成功句柄仍复用；对 mappingsDir + redisUrl + 三水位算 hash，配置变化时重建句柄（新成功才替换）。`deobfuscate` 入口先校验版本格式、再归一化映射类型（非法均透传 + 日志，不再默认 yarn），常量取值前 `defined()` 兜底，失败返回打 warn 日志（仅版本/类型/正文长度，不记正文），成功经可选第四参 `&$stats` 回传三计数与耗时。代码兜底水位改为 10/10/7 并与示例一致；`resolveRedisUrl` 改走 `spinyarn.redis_enabled`（缺省跟随 `cache.enabled`），经 `buildRedisUrl` 拼串（支持 username，端口/库号非法打 error 日志）；“本地缓存”措辞统一为按需解析（无缓存）。管理探针同时接受 `mappingType`/`mapping_type`（camelCase 优先），版本/类型非法与 content 超 256KB 返回 400，回显 `stats` 与 `changed`；状态新增 `redisConfigured`（仅 bool）、`redisSupported`、三水位、`extensionVersion` 及最新 1-2 个版本的 `has_mapping` 抽查，版本正则放宽允许 `+` 与 `_`。示例配置新增 `spinyarn.redis_enabled` 与 `cache.redis.username`。
- **过期日志的空间从未真正归还**：MariaDB 后端原先按行 `DELETE` 过期日志，InnoDB 只回收 `.ibd` 文件内部的空闲页，磁盘占用随运行时长单调递增直至爆满。现按天分区并丢弃整日分区，空间在丢弃瞬间归还操作系统，空间回收最多滞后一天（跨越过期时刻当天的分区会保留到次日，属刻意的保守设计）。
- **`Renew()` 导致父子表分区错位**：续期会更新父表 `created`，InnoDB 随之把行移动到新分区，而子表 `created` 未同步，父子行落入不同分区，父表分区被丢弃后子表残留成为永久无法回收的孤儿数据。现同步更新子表。
- **存储过程中有符号/无符号类型提升导致清理静默失效**：`information_schema.PARTITIONS.PARTITION_DESCRIPTION` 是无符号列，与有符号的过期时刻比较时负值被提升为巨大无符号数，使「没有任何分区可丢弃」且不报任何错误。现统一按 `SIGNED` 比较。
- **分区维护的死循环与静默失败**：运行期补建历史分区会与幸存的较新分区重名（ERROR 1517），形成「丢弃→重建→冲突」循环；批量拼接分区列表会因 `information_schema` 陈旧视图混入已丢弃的分区名而使整条语句失败（ERROR 1064）。现改为历史分区仅在安装时铺设一次、运行期只增量补未来分区，DROP 逐个执行并设循环上限。
- **分析记录详情与 Trace 拉不到数据**：分析键形如 `ai:analysis:<日志ID>`，控制台按 RFC 3986 将冒号编码为 `%3A` 放进路径，而 FastRoute 不解码路径参数（实测控制器拿到的仍是 `ai%3Aanalysis%3A…`），于是查库必然 404 —— 表现为列表正常、详情抽屉 / Trace 导出 / 删除全部失败。控制器统一还原后再查库，编码与未编码两种形态均可访问，并补 `AdminAiTest` 回归。
- **后台"解封"提示与真实状态不符**：应用层封禁（Redis / 本地文件）与边缘 WAF 封禁是两件事，未配置 `OPENLITEWAF_ADMIN_TOKEN` 时前者解除成功而后者仍在 TTL 内 403，接口此前一律返回 `unbanned: true`。现回传 `waf_sync`（`ok` / `skipped` / `failed` / `invalid`），控制台按结果区分提示；`security/overview` 透传 `ban_reasons`（按「类目#规则序号」归因生效中的封禁），后台新增封禁归因展示，旧版 WAF 无该字段时整块不渲染。WAF 自动封禁条目的理由也不再是笼统文案，而是快照里的真实命中规则。

- **边缘防护误封治理（子模块更新）**：
  - `OpenLiteWaf` v1.3.0：攻击特征规则新增「匹配对象作用域」，路径形态的探测规则（含敏感文件扩展名）不再作用于请求体与 User-Agent——前端遥测上报的 `endpoint` 字段里出现 `main.log`、`config.yml` 曾让整个客户端 IP 被封 600 秒，期间上传与查看日志一律 403；敏感文件扩展名规则同时收窄到请求路径本身（`?file=latest.log` 一类正常业务参数不再判探测），并从通用扩展名中剔除 `json` / `xml`（本站无静态 JSON/XML 敏感资产，误伤方为 `/sitemap.xml` 与前端附件名），改由具名敏感文件规则精确覆盖；新增只作用于请求体的 multipart 上传文件名规则补齐收窄后的检测缺口；请求体豁免清单补入遥测与管理端前缀（`/v1|/1/telemetry`、`/v1|/1/admin`），管理员保存含反引号与 `curl` 的知识库正文不再被拦。
  - `OpenLiteWaf`：CC 超限改返回 429 + `Retry-After` 且不再封禁 IP（固定窗口计数天然跨窗口恢复，CGNAT 出口不再被整片封掉）；特征命中改为「窗口内累计 `sig_strikes`（默认 3）次才封禁」，把单次误判的爆炸半径从「该 IP 全站 403 十分钟」压缩为「一次 403」。
  - `OpenLiteWaf`：攻击日志与封禁记录新增命中规则序号（`rule`）与匹配对象（`via`），`/security/stats` 新增 `ban_reasons` 封禁原因分布；命中片段与请求体内容一律不记录，公开页隐私口径不变。
  - `OpenLiteWaf`：新增特权运维端点 `GET /security/bans` 与 `POST /security/unban`（令牌 `OPENLITEWAF_ADMIN_TOKEN` 走请求头，未配置时 404 fail-closed），解封同时清除封禁键、命中计数、CC 窗口计数与封禁槽位并立即重写快照，`docker restart` 不再复活误封。后台「解封」此前对边缘层完全无效（`syncUnbanToWaf` 只改快照文件，而该目录在应用容器内是只读挂载），现改走内部 HTTPS 调用，应用容器的 `OpenLiteWaf/data` 维持只读。
- **站点访问统计口径修正（子模块更新）**：`OpenLiteStats` v1.1.0 将遥测上报（`/v1|/1/telemetry`）与管理后台（`/v1|/1/admin`）排除出全部计数——线上热门端点榜首曾是 `/v1/telemetry/report`（占环形窗口近半），且每个上报方都占一个独立 IP 位；同时不再计入 CORS `OPTIONS` 预检；热门端点聚合键按后端 `TelemetryService::cleanEndpoint` 同口径做路径模板化（`/v1/raw/{id}/main.log` → `/v1/raw/:id/main.log`，长十六进制 cacheKey → `:hash`），`recent` 列表保留原始路径。排除前缀改为按路径段边界判定并拒绝空前缀（此前 `/v1/administrator` 也会被排除，而列表里混入空串会静默停掉全站统计）。

### 构建与 CI

- **CI 从 GitHub Actions 迁移至 CNB 云原生构建**：新增 `.cnb.yml` 与 `.cnb/` 目录，职责与原 GitHub Actions 一致——单测/覆盖率、composer 校验、PHPStan level 5、架构测试、Swoole 启动冒烟，另加生产镜像构建与 docker compose 全栈真实环境 E2E（覆盖反代、TLS、WAF 拦截与解封、统计页、日志完整生命周期与 Admin/RAG 端点）。`.cnb/release.yml` 在提交信息以 `[Build]` 开头时发布 Release，tag 与 Release notes 均取自 `CHANGELOG.md` 首条版本条目。
- **GitHub 代码同步**：push `main` 时经 git-sync 将代码同步回 `NingZeStudio/LogShare` 镜像仓库（目标仓库不在 CNB 镜像链条上，提交链长期分叉，故显式开启强制推送）。
- **CI 环境镜像 `.cnb/Dockerfile.ci`**：PHP 8.5 + Swoole 6.2 + pcov，刻意不编译 SpinYarn 扩展（CI 不验证反混淆链路，`phpstan.neon` 已排除 `SpinYarnClient`）、不安装 jemalloc（常驻进程专用）。
- **平台机制适配**（已记录在配置注释中，改配置前需先读）：`include` 在「分支 + 事件」层合并，整条流水线数组不能用 `!reference` 引用（CNB runner 1.73.0 下会静默产出零 stage 的空流水线），属性级引用可用；`script` 由 sh（dash）执行，统一用 `set -eu` 而非 bash 专有的 `set -o pipefail`；`services` 只支持 docker/vscode，MariaDB 与 Redis 需自行 `docker run`，且 dind 与流水线容器网络不互通，须令测试容器加入同一自定义网络用容器名解析；Stage 的 `if` 不继承，门控必须落在真正做事的 Stage 上。覆盖率经 `scripts/clover_to_lcov.php` 由 Clover 转为 LCOV 后上报平台洞察页。

### 协议与文档

- 边缘层生效方式统一表述为 `docker compose -f docker/compose.yaml restart nginx`（容器内 `nginx -s reload` 在本部署不可靠），并更正「统计页重启后清零」的旧描述——计数与封禁自 v1.2.0 起随快照持久化。
- `OpenLiteWaf/README.md` 新增「运维端点」章节与误封判读指引；`.env.example` 增加 `OPENLITEWAF_ADMIN_TOKEN`。

## 1.8.2 — 2026-09-23

### LogAgent 完整化重构（重大架构升级）

- **God Class 拆解**：原 `LogAgent.php` 1316 行拆分为 20+ 单一职责组件，最大文件 ≤ 350 行。
- **`AgentRuntime`**：独立多轮 tool loop 引擎，分层停止条件（轮次上限 / 检索预算 / 上下文窗口）。
- **`PromptBuilder`**：提示词模板化与版本化，支持 quick / deep / launcher 分析模式切换。
- **`ToolRegistry` + `ToolInterface`**：工具统一注册与分发，新增工具只需一个类 + 一行注册。
- **`ToolSession` 增强**：富会话状态（工具调用链 / 已核实事实 / 锚定行 / token 预算）。
- **`LogWindowManager`**：动态日志窗口（锚点扩散 + 窗口压缩），多轮聚焦崩溃区域。
- **`ResultValidator`**：结论结构化验证（工具证据链 / 未核实声明标注 / 来源可追溯性）。
- **`AnalysisScorer`**：分析质量自动评分（工具效率 / 证据充分性 / 结论明确性，0-100）。
- **`AnalysisTracer`**：完整链路可观测（每轮调用 / 工具耗时 / 错误 / 最终指标可导出 JSON）。
- **`AIClientGateway` + `LlmGateway`**：LLM 调用统一网关，支持多 provider 故障转移。
- **`LlmStreamHandler`**：流式响应标准化处理。
- 薄委托层保留：LogAgent 原有反射测试与外部接口 100% 兼容，SSE 逐帧输出与改造前一致。

### RAG 架构全面升级（重大改进）

- **Chunk 值对象 + 四种 Chunker 策略**：新增 `Chunk` 值对象与 `ChunkStrategy` 枚举（`HEADING_ONLY` / `SLIDING_WINDOW` / `TOKEN_AWARE` / `HYBRID`），`Chunker` 统一入口支持策略选择，知识库切片质量显著提升。
- **检索组件拆分**：原 `RagSearch`  monolithic 拆分为 `LexicalIndex`（BM25 FTS5 + CJK bigram LIKE fallback）、`VectorIndex`（bge-m3 向量召回）、`SnippetExtractor`（上下文窗口提取）三大独立组件。
- **RetrievalPipeline 与 RRF 融合**：新增 `RetrievalPipeline`  orchestrate 多路召回结果，支持 RRF（Reciprocal Rank Fusion）融合策略 + 可选 LLM 精排层（可开关）。
- **QueryPreProcessor 检索词改写**：LLM 驱动的查询改写 + 规则分类路由，提升疑难查询召回率。
- **增量索引**：`mtime` 文件变更比对、单文件热更新与 `--incremental` 构建模式，索引重建耗时大幅降低。
- **SemanticCache 双层缓存**：查询结果缓存 + batch 自适应批处理，新增 Ollama 本地 embedding provider 支持。
- **RetrievalMetrics 可观测性**：各阶段耗时埋点、慢查询日志与 `rag:stats` 命令，检索链路全链路可追溯。

### 精排（Rerank）增强

- **专用 cross-encoder 精排端点**：新增 `HttpReranker` 支持专用 cross-encoder 服务，提供连通性探测（`/health`），LLM 精排层可独立开关与替换后端。

### Admin 管理后台扩展

- **分析记录全生命周期管理**：`AnalysisRecordManager` 基于 Redis ZSET 三维索引 + 本地归档，支持分页检索、详情追溯（trace / score / validation / toolCallChain）、物理删除与 Trace JSON 导出。
- **质量评分仪表盘**：四维评分概览（工具效率 / 证据充分性 / 结论明确性 / 总体质量）、日均趋势分析、低分预警（score < 60）与慢分析排查。
- **Prompt 版本化热管理**：`PromptManager` 支持多版本列示、读取、Fork、在线编辑、安全删除与一键激活，毫秒级跨进程热重载。
- **工具链在线动态门控**：`ToolManager` 实时对接 9 大排障工具，支持在线启用/禁用开关（动态同步 Prompt 与 Tool Schemas）、重试预算与 Fallback 降级链。
- **排障模式参数可视化**：后台可视化调整 `deep` / `launcher` / `quick` 三大排障模式的运行预算（最大轮次 / Exa 搜索预算 / RAG 检索预算 / 行级读取预算）。

### 前台结构化诊断输出

- 诊断正文规范化结构化 JSON 输出块（根因 / 置信度 / 排障清单 / 证据追踪），前台前端自动解析渲染为诊断摘要卡片。

### 事件队列与指标增强

- `EventQueue` 指标补齐累计吞吐口径，违规拦截与处理失败分离统计。
- `AiMetricsService` 质量看板按窗口全量聚合，修复总数恒为 100 与 days 参数失效问题。
- 补齐检索召回计数，修复 `ragCalls` 与 `topics` 指标恒为 0 的问题。

### 分析与可观测性

- 服务端派生 `version` / `loader` 元数据并修正版本矩阵与走势取样口径。

### 静态分析与代码质量

- 全面修复 PHPStan Level 5 类型错误（`PromptBuilder` / `AbstractTool` / `MCPClient` / `RagController` / `ApiResponse` 等 10+ 文件）。
- `RedisClient::getRedis()` 返回类型修正，消除所有调用方泛型类型不确定问题。
- `RedisMock` 补齐 ZSET 有序集合全套核心方法与 `mGet`，单元测试离线环境完全解耦。

### 新增测试

- `RagRerankTest.php` — 精排层单元测试
- `EcosystemMetadataDeriveTest.php` — 生态元数据派生测试
- `AdminAiTest.php` — 管理后台 AI 端点全面覆盖

### API 文档

- `API.md` 补齐精排端点、召回指标与质量看板规范（+131 行）。
- `openapi.yaml` 补全 RAG 架构升级遗漏的端点契约（+101 行）。
- `postman_collection.json` 新增 6 组 RAG / Admin AI 操作用例。

---

## 1.8.2-beta.2 — PreRelease (2026-09-22)

### 预发布说明

本版本为面向 LogAgent 治理与可观测体系的公开预发布，新增管理端全套 AI 分析记录管理、质量评分看板、Prompt 版本管理、工具链在线门控与分析模式配置，并深度适配前台结构化诊断结果展示。

### 核心变更

- **LogAgent 全栈可观测与 Admin 治理引擎**：
  - **分析记录全生命周期管理（`AnalysisRecordManager`）**：基于 Redis ZSET（时间/分数/耗时三维索引）+ 本地归档构建高可用存储层，支持按日期、模式、评分等条件分页检索，单条详情追溯（trace + score + validation + toolCallChain），以及单条分析记录物理删除与一键导出 Trace JSON。
  - **质量评分仪表盘（Scoreboard）**：提供四维评分概览（工具效率、证据充分性、结论明确性、总体质量）、日均趋势分析、低分预警列表（score < 60）与慢分析耗时排查（耗时 > 阈值）。
  - **Prompt 版本化热管理（`PromptManager`）**：支持 PromptBuilder 多版本管理，包括列出版本、读取版本提示词、Fork 派生新版本、在线编辑修改、安全删除以及一键激活线上生效，毫秒级跨进程热重载。
  - **工具链在线动态门控（`ToolManager`）**：实时对接 ToolRegistry 9 大排障工具，支持在线启用/禁用开关（动态同步 Prompt 与 Tool Schemas），并支持配置重试预算与 Fallback 降级链。
  - **排障模式参数可视化（`AnalysisMode`）**：支持后台可视化调整 `deep` / `launcher` / `quick` 三大排障模式的运行预算（最大轮次、Exa 网络搜索预算、RAG 检索预算与行级读取预算）。
- **结构化诊断输出与前台感知升级**：
  - 诊断正文规范化结构化 JSON 输出块（根因、置信度、排障清单、证据追踪），前台前端自动解析并渲染为诊断摘要卡片。
- **架构与框架健壮性增强**：
  - 解决 FastRoute 控制器占位符冲突（将 Prompt 版本路径参数重命名为 `{promptVersion}`）。
  - 动态配置热更新机制加固：`getDynamicConfigRaw()` 与 `touchDynamicConfig()` 确保在保存局部更新时完整保留兄弟配置，杜绝配置被意外置空。
- **测试框架与基础设施健壮性**：
  - `RedisMock` 补齐 ZSET 有序集合全套核心方法（`zAdd`, `zRevRangeByScore`, `zRangeByScore`, `zRem`, `zRemRangeByRank`）与 `mGet`，单元测试离线环境完全解耦。
  - 新增 `tests/Unit/AdminAiTest.php` 单元测试套件，全面覆盖分析记录、评分看板、Prompt 管理与工具链管理逻辑。

---

## 1.8.1-beta.1 — PreRelease (2026-09-22)

### 预发布说明

本版本为 v1.8.1 功能冻结前的公开预发布，包含 LogAgent 完整化改造全部成果，
供社区提前测试。API 与数据库 schema 保持向后兼容，生产环境请继续使用 v1.8.0。

### 核心变更

- **LogAgent 完整化改造（重大重构）**：
  - God Class 拆解：原 LogAgent.php 1316 行拆分为 20+ 单一职责组件，最大文件 ≤ 350 行
  - `AgentRuntime`：独立多轮 tool loop 引擎，分层停止条件（轮次上限 / 检索预算 / 上下文窗口）
  - `PromptBuilder`：提示词模板化与版本化，支持 quick / deep / launcher 分析模式切换
  - `ToolRegistry` + `ToolInterface`：工具统一注册与分发，新增工具只需一个类 + 一行注册
  - `ToolSession` 增强：富会话状态（工具调用链 / 已核实事实 / 锚定行 / token 预算）
  - `LogWindowManager`：动态日志窗口（锚点扩散 + 窗口压缩），多轮过程中持续聚焦崩溃区域
  - `ResultValidator`：结论结构化验证（工具证据链 / 未核实声明标注 / 来源可追溯性）
  - `AnalysisScorer`：分析质量自动评分（工具效率 / 证据充分性 / 结论明确性，0-100）
  - `AnalysisTracer`：完整链路可观测（每轮调用 / 工具耗时 / 错误 / 最终指标可导出 JSON）
  - `AIClientGateway` + `LlmGateway`：LLM 调用统一网关，支持多 provider 故障转移
  - `LlmStreamHandler`：流式响应标准化处理
  - 薄委托层保留：LogAgent 原有反射测试与外部接口 100% 兼容，SSE 逐帧输出与改造前一致

### 代码质量

- 新增 4 个 Pest 单测文件，覆盖 ToolRegistry / AgentRuntime / PromptBuilder / 工具重试降级
- PHPStan Level 5 无新错误
- 总代码量：33,700 行 PHP / 197 文件（+3,385 行 / +4 文件），测试代码占比 26.2%

---

## 1.8.0 — 2026-09-21

### 重大架构演进与新特性

- **统一日志异步事件队列（EventQueue）体系**：
  - **极速上传彻底解耦**：用户上传日志（`LogController::create`）彻底移除同步阻塞的 SpinYarn 反混淆和全量敏感词/正则扫描，仅保留轻量 `preFilter()` 基础脱敏和 `$this->analyse()` Codex 分析，入库后立即派发 `EventQueue::EVENT_LOG_UPLOADED` 事件并毫秒级向客户端返回 200 响应。
  - **发布-订阅调度中心**：强类型事件模型 `QueueEvent`（包含 UUID、重试次数、时间戳与 `stopPropagation()` 流程阻断控制），支持按优先级降序调度（`EventHandlerInterface` 标准化处理器接口）。
  - **多层降级调度**：优先写入 Redis Stream（`events:log:stream`，消费组 `log-event-workers`），Redis 不可用时在 Swoole 协程环境下无缝降级为异步子协程并发执行，CLI/单测环境下同步降级执行，保证 100% 健壮可用。
  - **死信队列（DeadLetterQueue / DLQ）**：重试耗尽（默认最大 3 次）或毒丸条目自动归档至独立死信流 `events:log:dead` 并安全出队，提供 Admin API 进行死信列表检索、单条重放（`retry`）和安全清空（`clear`）。
  - **异步安全审计与反混淆处理流水线**：
    - `SecurityAuditHandler` 提取主日志正文与附加文件（`Log::getRawFiles()`）执行规则检测，违规时即刻物理清除日志及缓存、阻断后续流转、记录高危操作审计日志并自动封禁恶意来源 IP；
    - `DeobfuscateHandler` 对未混淆日志调用 SpinYarn 并通过统一的 `StorageInterface::Update()` 与 `Log::updateContent()` 完成持久化与缓存原子回写。
  - **常驻消费进程自回收与优雅排空**：`EventQueueConsumer`（`event-queue-consumer`）支持在进程内派生固定命名消费协程（`worker-0`, `worker-1`），后台协程自动周期性（30s）执行 `XAUTOCLAIM` 回收超时待决条目；支持两阶段排空（30s 宽限期）与三重自回收策略（处理 ≥1000 任务、OS 真实物理常驻内存 VmRSS ≥256MB 或空闲 ≥180s），退出后由 Swoole manager 自动重新拉起干净进程重置 RSS。

- **违规规则 AES-256-GCM 认证加密安全存储**：
  - 敏感关键词与正则表达式使用 AES-256-GCM 密文存储（标识前缀 `enc:v1:<base64(iv.tag.cipher)>`），密钥由环境变量 `SECURITY_ENCRYPTION_KEY`、配置或自动生成存盘于 `runtime/.security_secret`（0600 权限保护）。
  - 磁盘文件 `runtime/content_reject_rules.json` 及 `dynamic_config.json` 严格全量密文落盘，杜绝敏感词泄露；`Config::validate()` 自动解密后再进行正则表达式语法校验，Admin 控制台则解密后友好呈现。

- **配置热重载与安全防护增强**：
  - `SecurityService::resolveClientIp()` 统一加固真实客户端 IP 判定，仅允许受信任反向代理及私有网段透传 `X-Real-IP` / `X-Forwarded-For`。
  - `App\Config::ensureFresh()` 结合 Redis 版本号与文件时间戳，实现跨 Swoole Resident Worker 毫秒级配置热重载与零残留。

- **LogAgent 深度排障与已知领域知识机制**：
  - **因果追踪硬性约束**：重构 LogAgent 思维链（CoT）与系统提示词，明确复杂日志绝非仅看表象错误。除非报错原因极其明确孤立无需上下文，否则硬性要求模型【必须至少进行一次日志上下文线索查找】（使用 `read_log_file` 读取上下文行区间、检索 `crash-reports` 崩溃报告附件、或调用 `grep_log_file` 追踪前置异常链与模组初始化状态），探明真正诱因，彻底杜绝单点报错草率收敛。
  - **已知领域知识强行注入**：支持由运维管理员在后台统一录入与维护领域知识（单条严格限制 ≤ 200 字），落盘 `runtime/domain_knowledge.json` 并配合 Redis 高速同步；在组织分析请求时直接以先验规则格式拼接入系统提示词，LLM 自身无写权限且不走动态 Tools，形成确定性业务约束。

### 新增管理后台 API 端点

- `GET /v1/admin/event-queue/stats`：查询事件队列运行概况与指标（吞吐、背压、死信数、驱动模式）。
- `GET /v1/admin/event-queue/dead`：获取死信队列条目列表（分页、错误信息、失败时间戳）。
- `POST /v1/admin/event-queue/dead/retry`：重放指定死信条目，重新投入事件流消费。
- `DELETE /v1/admin/event-queue/dead`：清空死信队列。
- `GET/POST /v1/admin/ai/domain-knowledge`：获取已知领域知识列表 / 新增领域知识条目（≤200 字校验）。
- `PUT/DELETE /v1/admin/ai/domain-knowledge/{id}`：更新已知领域知识内容与启用状态 / 安全删除。

### 文档更新与补全

- **`README.md`**：全面改写架构与组件说明，重构安装配置指南（补充 security、eventQueue、admin 等配置说明），更新请求处理流程与异步事件队列时序图。
- **`API.md`**：上传日志流程补充极速解耦与事件流说明，新增第 44-47 节关于事件队列与死信管理的 Admin 端点规范与请求响应示例。
- **`openapi.yaml`**：版本升级至 1.8.0，补全事件队列指标与死信管理端点定义。
- **`postman_collection.json`**：在 Admin 分组下补充事件队列与死信操作用例。
- **`rag/README.md`**：更新 Agentic RAG Plus 机制、向量召回配置与 CMS 在线管理端点说明。
- **`AGENTS.md`**：更新事件队列、死信机制与敏感规则加密技术约定。

## 1.7.8 — 2026-09-12

### 新功能

- **LogAgent 关键词检索工具 (`grep_log_file`)**：支持模型在会话绑定日志的指定文件（默认 `main`）中逐行检索关键词；支持前后上下文行（0–5）、最大匹配数（1–30）、大小写敏感配置；输出行号按总行数字宽对齐，匹配行添加 `>` 指示标记，相邻及重叠行区间自动合并；单次工具输出上限放宽至 32KB，状态摘要实时提取匹配统计与标记行。
- **LogAgent 首轮日志上下文定位与提示词重构**：日志总长度 < 12KB 时不触发定位算法，完整原文直传首轮 user message；≥ 12KB 时统一运行 Tier 1（根因/堆栈）+ Tier 2（失败谓词）错误定位正则。若定位到错误行，以首个错误行为核心截取 12KB 聚焦窗口（向前保留约 2.5KB 前置因果与完整后置堆栈，整行对齐）；未定位到显式错误时，绝不盲目截取前缀干扰日志，改为注入日志概况并提供基础 grep 关键词建议（`ERROR`、`FATAL`、`Exception`、`Caused by`、`crash` 等），引导模型遵循“适可而止”思维链开展 1~2 轮定向排查。
- **全链路 Brotli 压缩支持**：Swoole HTTP 服务端开启原生压缩并优先响应 Brotli（`Content-Encoding: br`）；Nginx 反向代理层透传客户端 `Accept-Encoding` 并预置 Brotli 配置；`RequestParser` 统一支持请求体透明解压（`br` / `gzip` / `deflate`，含 20MB 防解压炸弹上限保护），`POST /v1/ai/analyse` 支持高压缩率 payload 直传。
- **AI 消费进程常驻内存治理与空闲自回收**：`ai-queue-consumer` 自回收机制（处理 ≥50 任务且队列深度为 0、空闲 ≥300s 后安全退出，由 Swoole manager 自动热重启，重置 arena 碎片与连接缓冲）；Docker 镜像引入 jemalloc（`libjemalloc.so.2` + 快速衰减回收），防止 resident 进程堆顶碎片化。

### 修复

- **排队深度空组判定修复**：以 `XINFO GROUPS` 判定消费组存在性，避免消费组未创建时误判队列深度为 0。
- **SpinYarn 版本升级**：镜像内 pin 的 SpinYarn 扩展升级至 v1.1.1，引入 bincode 映射缓存与常驻连接。
- **安全与脱敏治理（子模块更新）**：
  - `OpenLiteWaf`：豁免 `/v1/ai/analyse` 等 AI 分析端点请求体扫描以防用户日志误报，放行 `/{version}/raw/{id}/{filename}` 原始文件探针校验，修复长括号规则编译。
  - `OpenLiteStats`：Referer 记录严格剥离 HTTP Basic Auth 敏感认证凭据，IPv6 地址展开后脱敏遮盖前三段。

### 协议与文档

- **SSE 事件协议完善**：`API.md`、`openapi.yaml` 与站内 API 文档完整同步 `event: status`（`queued`、`thinking`、`tool`、`tool_result`、`limit`）、`event: error` 异常终止与 `event: done` 流结束定义。

## 1.7.7 — 2026-09-08

### 修复

- **队列满判定口径错误导致误 429（线上事故）**：排队深度此前取 `XLEN`——Stream 的累计消息数，`XACK` 不删除条目，消费完毕深度也不下降，累计达到 `maxQueue` 后所有 AI 分析被永久 429；改用「未投递 lag + 在途 pending」真实深度（消费组未创建时保守回退 XLEN）
- **`waitTimeout=0` 下 payload 过期任务让中继端永挂**：消费者发现任务载荷过期时补发 error 终态帧，等待中的 SSE 中继得以正常收尾

### 新功能

- **`ai.queue.waitTimeout` 支持无排队超时**：配为 `0` 或负数时中继端无限等待至 `done`/`error` 或客户端断开；此模式下任务总寿命即 `jobTtl`（新增 `AnalysisQueue::jobLifetime()` 统一 payload/活跃映射/运行锁三处 TTL 口径）

## 1.7.6 — 2026-09-08

### 修复

- **IPv4 地址在 PCRE2 < 10.43 环境下静默不脱敏（IP 泄露）**：`(?<!version:? )` 为变长后顾，CI runner 与 bookworm 基础镜像（PCRE2 10.42）编译失败，过滤器 safe 降级返回原文；改用两个定长后顾 `(?<!version )(?<!version: )`，语义等价
- **微队列在真 Redis 下读取恒为空**：`flattenStreamReply` 此前只匹配 `[[stream => entries]]` 形态，按 phpredis 实际回复 `[stream => [entryId => fields]]` 修正（其官方测试为据）；CI 集成测试（入队→消费→ACK→XAUTOCLAIM→中继帧序）已全链路通过
- **消费者进程在 CI 静态分析报 `class.notFound`**：`isEnable()` 补 `@param mixed` docblock（分析环境无 swoole 扩展）

### 构建

- Docker 构建 rust 镜像 pin 1.85.0 → 1.90.0：SpinYarn v1.1.0 依赖链（url→idna→icu 2.x）MSRV ≥1.88

## 1.7.5 — 2026-09-08

### 新功能

- **Agentic RAG Plus（知识库发现层重构）**：新增 `RagSearch::TOPIC_DESCRIPTIONS` 主题描述表，`list_topics` 返回可读主题地图（目录 + 描述 + 样本，紧凑渲染守门 ≤5120B）；`rag_search` 支持 `topic` 参数定向检索（FTS/LIKE/向量召回均在源头过滤）；工具描述按 docstring 标准重写；提示词链由「强制执行规则」改为证据驱动检索策略（目录选择闭环、改写方向清单、按缺口计数预算），并加代码兜底：`web_search_exa` 全对话 5 次硬拦截、检索合计达 6 次注入收敛提示；crash-reports 类附件在文件列表置顶标注 `[优先]` 并优先读取
- **AI 分析 Redis Streams 微队列**（`ai.queue`，默认关闭）：开启后全部 `/v1/ai/*` 分析经 `ai-queue-consumer` 进程执行，请求侧仅做 SSE 中继（新增 `queued` 首帧）；`maxConcurrent` 约束上游并发、`maxQueue` 约束排队深度（满队返回 429 + `Retry-After`）、`waitTimeout` 约束中继等待；客户端断连不取消任务（结果照常写缓存），崩溃任务由 XAUTOCLAIM 回收（job 级运行锁防双跑）；日志正文只存短 TTL payload 键不进 Stream，同缓存键任务自动去重挂接；Redis 不可用按 `failOpen` 回退请求内执行
- **SpinYarn Redis 映射缓存**：`SpinYarnClient` 反射探测已加载扩展的 `spinyarn_init` 签名并适配传参，扩展支持时传入 `cache.redis` 的 Redis URL，跨进程共享解析结果

### 修复

- **线上 SpinYarn 反混淆完全失效**：镜像 pin 的扩展 v1.0.0 只接受 4 参，配置了 Redis 时盲传第 5 参抛 `ArgumentCountError`，fail-open 后整个进程生命周期反混淆停用；现按实际签名适配，旧扩展走本地模式并一次性提示，Redis 初始化被拒时回退重试
- **PHPStan 并行 worker 超默认 128M 内存上限崩溃**：`composer stan` 脚本补 `--memory-limit=512M`

### 改进

- SSE 输出统一经 `AnalysisEmitter` 抽象（`SseEmitter` 直写 / `StreamEmitter` 入流），两实现产出逐字节相同帧；发射端随协程 Context 绑定，常驻进程无跨请求串扰
- API.md / openapi.yaml / postman_collection.json 同步队列模式协议（`queued` 帧、429 语义、断连不取消）与 Agentic RAG Plus 后的工具定义；README 配置表补 `ai.queue`

## 1.7.4 — 2026-08-30

### 新功能

- **LiteWAF v1.1.0**：规则扩充至 63 条（新增 `rce` 命令执行类目、12 种扫描器 UA、druid/nacos/jenkins/solr 等管理后台探测路径）；检查范围新增完整解码 URL（含 query，堵住 `%20` 编码绕过）与请求体扫描（POST/PUT/PATCH 前 64KB，日志内容端点豁免）；`python-httpx` 等常规客户端 UA 不再误伤
- **公开安全统计页重做**（`/security`）：总览卡片、最近 60 分钟拦截趋势图、类别分布、来源 IP Top（脱敏）、攻击日志分页（`/security/logs`，环形 500 条，每页 50 条，`token=` 参数自动打码）；拦截页重设计为卡片组件（iframe 友好，按特征命中/CC 超限/封禁期区分文案）
- **AI 工具轮次上限**默认值调整为 50

### 修复

- **LiteWAF 线上完全不拦截**：签名匹配依赖 `ngx.re.compile`（lua-resty-core FFI API，非原生），resty.core 未加载时 access 阶段报 nil 中止；现模块主动加载 `resty.core.re`，缺失时自动回退原生 `ngx.re.find` 字符串匹配路径
- **文件存储续期后仍被过期清理删除**（数据丢失）：`CleanupExpired` 改用与 `Get()` 一致的 `created` 时间源（`.meta.json` 优先），续期日志不再误删；`Delete` 同步清理元数据文件
- **构建镜像泄漏 `.env`**：`.dockerignore` 排除 `.env`、`.github`、`docs/` 等非运行时文件，生产密钥不再进入镜像层
- **`read_log_file` 续读与防重复拦截自相矛盾**：仅在完整读取时置已读标记，offset/行区间续读正常工作
- **分析缓存锁在异常路径不释放**：改 `finally` 无条件释放，互斥不再失效
- **SSE 客户端断开后 Agent 循环不中止**：`SseWriter` 检测底层写失败抛 `ClientDisconnectedException`，上层立即停止工具循环，不再浪费上游 API 配额
- **`rag:build` 后 Web 进程沿用旧索引**：`RagController` 按 `filemtime` 检测索引替换，重建后自动生效
- **`rag:build` 失败退出码为 0**：返回 FAILURE，CI 与启动脚本可感知
- **CI 冒烟证书域名硬编码**：改为从 `default.conf` 动态提取
- **`release.yaml` 脚本注入**：commit message 改经环境变量中转
- **`ai.sh` 错误事件被吞**：修复恒 false 的 error 检测条件与子 shell 状态丢失
- `content[]=` 类型污染返回 400 而非 500；`IPv6ShortFilter` 正则失败不再 500；删除日志时缓存删除失败写墓碑标记，杜绝已删日志在 TTL 内可读；URL 上传分析路径错误消息不再双重转义

### 改进

- **全仓库代码审查修复**（详见 `CRCLASH.md`）：共 63 项——存储层（ID 冲突重试、metadata 类型两端对齐、过期清理分批）、过滤链（safe 包装降级可观测、token 过滤器补无引号形态、UUID 预检精准化）、AI/RAG（RagSearch 语义缓存 O(n²) 修复、向量分批扫描、topics 进程级缓存、`hash_equals`）、WAF 与边缘（chunked body 扫描、nginx worker_connections/resolver/HSTS/http2）、部署（compose 镜像 pin、统一日志上限、SpinYarn 构建产物清理、rag:build 启动降级）
- 历史过程文档（迁移计划、审查报告、排障记录）归档至 `docs/`
- README / API.md / LiteWAF/README 重写为开发者文档风格

## 1.7.3-hotfix.1 — 2026-08-25

### 修复

- **AI 分析不可用**：修复内置 RAG 使用回环地址时 MCPClient 属性未初始化，导致 LogAgent 统一返回服务暂时不可用
- **Docker 映射路径**：修正 `docker/compose.yaml` 中 mappings 目录的挂载路径

## 1.7.3 — 2026-08-25

### 修复

- **AI 首次请求空流**：新增 `CURLOPT_ACCEPT_ENCODING` 允许 curl 透明解压，修复上游 gzip SSE 导致 `data:` 行乱码误判为空流的问题；空流失败后自动重试同 key 一次，缓解偶发的首次连接预热问题
- **`/rag` 访问控制**：默认仅允许本机回环访问，可选 `ai.mcp.rag.authToken` Bearer 鉴权；LogAgent 自调用自动携带 Token
- **出站 SSRF 防护**：MCPClient/SemanticClient 拒绝私网地址，未解析主机名不拦截；内置 RAG 仍可用回环
- **限流中间件**：支持 `trustedProxies` 下的 `X-Real-IP`；`/rag` 纳入限流桶
- **RAG 索引原子构建**：先写临时数据库，完成后原子 rename 替换，构建失败保留旧索引
- **日志分析缓存锁**：Redis NX 锁 + 等待缓存，缓解 cache stampede
- **上游错误脱敏**：SSE 统一返回 "AI service temporarily unavailable."，不再暴露上游细节
- **MCP 响应校验**：私网拦截、`data:` 无空格 SSE 兼容、response id 校验
- **PHPStan 排除收窄**：仅保留 `SpinYarnClient.php`
- **文件存储原子写入**：临时文件 + rename 替换；renew 只更新 `.meta.json`

### 改进

- **Compose 统一**：`docker/compose.yaml` 同时满足开发与生产，移除 `docker/compose.prod.yaml`；挂载 `Config.inc.php` 注入业务配置
- **Docker 编排**：移除内置 nginx，Hyperf 绑定回环 `127.0.0.1:9501` 由宿主机反代直连
- **CI 增强**：Docker 构建覆盖 PR、Composer validate/audit、coverage 生成
- **映射下载脚本**：失败返回非零、`.part` 临时文件原子替换、gzip/非空校验
- **MariaDB 外键**：`log_files`/`log_metadata` 增加 `ON DELETE CASCADE`

## 1.7.2-hotfix.2 — 2026-08-25

### 修复

- **SSE 流式响应缺失 CORS 头**：`/v1/ai/*` 等流式端点经 `EventStream` 直写连接，绕过了 `CorsMiddleware` 对响应对象加头的流程，生产环境跨域调用 LogAgent 被浏览器拦截（空响应、status 0）。`SseWriter::begin()` 现于首帧下发前显式携带 CORS 头，并补发 `Cache-Control: no-cache` 与 `X-Accel-Buffering: no` 防止反代缓冲延迟首帧

## 1.7.2 — 2026-08-25

### 修复

- CI：test job 补充 MariaDB schema 初始化步骤——v1.7.1 新增的存储链路集成测试（上传/读取/删除、token 哈希校验）在 CI 环境因 `logs` 表不存在而失败；本地开发时该用例集因无数据库被 skip，掩盖了缺口

## 1.7.1 — 2026-08-25

> 本版本新增语义 RAG：bge-m3 向量召回与 bge-reranker 精排，并扩充知识库内容。

### 新功能

- **语义 RAG 增强**：bge-m3 向量召回 + bge-reranker-v2-m3 精排（`ai.rag` 独立网关/密钥，多供应商 failover），API 异常自动回退纯词法检索
- **知识库扩充**：新增 Forge / NeoForge 官方开发者文档，PaperMC 全家桶（Paper/Velocity/Waterfall/Folia）、Purpur、Glowstone、Geyser、Quilt 服务端文档，以及 Android 启动器生态 issue 蒸馏库（patterns/renderers 等）——共 632 文件 / 2250 分块
- **知识库清洗器**：下载脚本自动剥离 frontmatter / MDX / admonition / HTML 噪声，fenced code 完整保护
- **CJK bigram 检索**：中文长串查询按 2-gram 降级匹配，避免无结果返回
- **tool_result 按工具定制摘要**：读文件只报行数、rag 输出命中清单、list_topics 折叠主题目录
- **生产部署编排** `docker/compose.prod.yaml`：挂载完整 Config.inc.php，四服务 healthcheck

### 改进

- `read_log_file` 全文直返（无行区间参数）+ 会话级防重复读取拦截
- 思维链 `reasoning_content` 按轮回传上游，修复 DeepSeek 思维模式工具调用 400
- 检索 snippet 长度上限提升（1600 字符整段阈值 / ±800 窗口）并保留 Markdown 结构；检索类工具结果限额放宽至 32KB
- 限流 key 归一化防随机 ID 绕过；计数键 SET NX EX 原子初始化
- Redis 支持密码/库选择；语义 RAG 支持 `AI_RAG_*` 环境变量；DB 连接池心跳保活
- SSE 基础设施收敛为 `App\Sse\SseWriter`，日志统一 `App\Syslog`
- 删除令牌落库改为 SHA-256 哈希（兼容存量明文）；Filters/Index 端点由配置动态生成

### 修复

- Docker 镜像补 `pdo_sqlite`（RAG 必需）；REDIS_PASSWORD 双端一致；AI_RAG env 透传
- rerank 返回空结果集时兜底回退词法排序，检索不再「归零」
- 分析结果 JSON 编码失败不再污染进程级缓存
- ApiTest 重写为真实 HTTP 集成测试（上传/读取/删除全链路）

## 1.7.0 — 2026-08-23

> 首个正式版（LTS）。在 1.7.0-beta.1 基础上完成 SpinYarn v1.0.0 去下载化、Docker 部署补全、性能优化与两轮代码审查修复。

### 新功能

- **SpinYarn v1.0.0 LTS**：移除运行时下载能力，映射表改由宿主提供（Git LFS + 下载脚本 + Docker bind mount），SpinYarn 只做「本地加载 + 解析 + LRU 缓存」
- **AI 可完全禁用**：新增 `ai.enabled` 开关 + `AI_ENABLED` 环境变量，禁用后 `/v1/ai/*` 统一返回 404

### 改进

- **Docker 部署补全**：镜像补齐 `composer install`、`zip`/`pcntl`/`posix`/`sockets` 扩展、`Config` 缺失回退示例配置，CI 6 个 job 全部通过
- **性能优化**：过滤器快速预检（IPv6/UUID/IPv4 等，脱敏 -2.3s）、Codex 分析结果进程级缓存（重复访问 ~3.7s → 8ms）
- Redis 连接改为协程级 `Context` 隔离；版本号收敛至 `App\Version`

### 修复

- 修复 CRCLASH 第六轮审查问题（反混淆重复 analyse、AI 环境变量缺口、版本号硬编码、RedisMock 缺 `ping()` 等）
- 映射表纳入 Git LFS 版本管理

## 1.7.0-beta.1 — 2026-08-21

### 新功能

- **Hyperf 3.2 + Swoole 6.2 常驻进程运行时** — 从传统 PHP CLI/FPM 架构迁移至 Hyperf 常驻协程进程（`bin/hyperf.php` 入口），全链路协程化，移除 `index.php`/`Router.php` 请求分发模型
- **SpinYarn 反混淆扩展** — 以自研 SpinYarn PHP 扩展取代已退役的 `aternos/sherlock`；日志写库前反混淆（DB 存反混淆后内容，读取时不再重复反混淆），映射句柄为进程级缓存（避免每次请求 ~110ms 重载）
- **RAG 整合进 Hyperf 进程** — 内置 RAG MCP server 由独立进程整合到主 server 的 `/rag` 路径（JSON-RPC 2.0），新增 `rag:build` 命令构建/重建索引
- **CORS 与限流中间件** — 全局 HTTP 中间件链（Cors → RateLimit），Redis `INCR`+`EXPIRE` 按 IP+method+path 限流，Redis 不可用时 fail-open

### 改进

- **存储层迁移**：MongoDB → MariaDB（`hyperf/database`），表结构 `logs` / `log_files` / `log_metadata`，`Get()` 以 `includeContent` 投影跳过超大文件体
- **代码迁移**：`src/` → `app/`，统一 `App\` PSR-4 命名空间（`composer.json` autoload）
- **路由改造**：`Router.php` 集中路由表 → Hyperf 注解路由（`#[Controller]` + `#[GetMapping]`/`#[PostMapping]`/`#[DeleteMapping]`）
- **AI 流式改造**：Swoole 协程 SSE 输出，流句柄存于协程 `Context`（避免跨请求串扰）
- 数据库连接改由 `DB_*` 环境变量（`config/autoload/databases.php`），替换原 `MONGODB_URI`

### 修复

- 修复 CRCLASH 第四轮审查问题（协程串扰、部署、资源泄漏等）
- RAG 改走主 server 的 `/rag` 路径，规避 Hyperf 多 HTTP server 单例冲突
- SSE 存储检测 Swoole（无则 static），修复 CLI 测试环境
- 修复 CRCLASH 第五轮审查问题：Redis 连接改为协程级隔离（Context）避免跨协程共享单连接串扰、`POST /v1/ai/analyse` 的 `id` 字段绑定失效、`Limit*` 过滤器异常契约、缓存热路径日志噪音等

### 配置变更

- 移除 `mongo` 配置段；数据库连接由 `DB_*` 环境变量提供
- 新增 `spinyarn` 配置段（`mappings_dir` / 缓存水位）；映射表改由下载脚本预生成并提交进仓库（Git LFS），Docker 以 bind mount 挂载，SpinYarn 不再运行时下载
- 存储后端改为 MariaDB（`s`，默认）↔ 文件系统（`f`）

### 测试

- 引入 `hyperf/testing` 编写 Controller 集成测试（HTTP 级）

## 1.6.0 — 2026-08-15

### 新功能

- **多文件日志上传** — `POST /v1/log` 支持 `files` 数组，同一 ID 下可存多个文件（主文件 + 附加文件）；`.zip` 压缩包自动展开（含子目录，路径遍历防护，上限 200 文件 / 12MB）
- **子文件读取** — 新增 `GET /v1/raw/{id}/{filename}`（支持子路径）与 `GET /v1/log/{id}`（元信息 + 文件列表）
- **LogAgent（AI 智能体）** — 模型驱动工具循环：LLM 自主调用工具（网络搜索 / RAG 检索 / 会话日志文件），SSE 流式透传思维链（reasoning_content）与工具事件
- **MCP 客户端** — 新增 `Client\MCPClient`（Streamable HTTP，curl + JSON-RPC 零依赖），接入 Exa WebSearch 托管端点
- **内置 RAG（SQLite FTS5）** — 新增 `rag/` 子系统：纯本地 BM25 检索静态知识库（不依赖网络与外部 embedding 服务），中英文双路检索；`php rag/build_index.php` 建索引，随 Docker Compose 启动自动重建索引
- **会话文件工具** — Agent 的 `list_log_files` / `read_log_file` 仅可访问当前分析日志 ID 下的文件，无法越权读取其他日志

### 改进

- `AIClient` 拆出底层 `streamChat()`，支持 content / reasoning_content / tool_calls 三分支流式解析，多 key 轮询保留
- 上游 AI 请求失败时暴露错误响应体（此前仅 `HTTP 400`，无法诊断）
- `Router` 支持 `{param:.+}` 通配段（子文件路由）+ 路由编译缓存
- 统一错误处理：全局 `set_exception_handler` 兜底，精简 Handler 重复样板
- Filesystem 存储补齐 TTL：`Renew()` 更新 `created`、`CleanupExpired()` 清理过期文件（与 Mongo TTL 语义对齐）
- 配置支持环境变量覆盖（MONGODB_URI / REDIS_HOST / REDIS_PORT / REDIS_TIMEOUT / AI_API_KEYS / AI_BASE_URL / AI_MODEL）
- 性能：Log 行数缓存、路由表一次性编译
- SSE 协议扩展 `event: status`（thinking / tool / tool_result / limit），兼容旧客户端

### 修复

- **ApiError 未继承 Throwable** — 所有 `throw new ApiError` 运行时必 fatal，已修复为继承 `\Exception`
- **架构测试静默失效** — glob 路径错误导致 7 项测试 0 断言（risky 通过），已修复并真实生效（67 断言）
- **PHPStan 无法运行** — `phpstan.neon` 无效配置项，现 level 5 零错误（覆盖 `src` + `rag`）
- **MongoCache 缺陷** — 补 `Delete()` 实现，`Set()` 的 updateOne 补 `$set` 操作符
- **tests/bootstrap 从未加载** — Pest 3 的 `bootstraps` 键失效，mock `class_alias` 从未生效，改由 `tests/Pest.php` 加载
- RedisCache `Get` 用 `?:` 导致缓存值 `"0"` 误判、RedisClient 无连接超时
- `Id::get()` 恒 false 的空值检查

### 配置变更

- 新增 `ai.agent`（enabled / maxToolRounds / maxFileLines / maxFileBytes）与 `ai.mcp`（webSearch / rag，rag 含 url + db）
- 新增 `storage.uploadFiles`（maxFiles / maxTotalBytes）
- 移除冗余依赖 `chillerlan/php-qrcode`

## 1.5.5 — 2026-07-28

### 改进

- **存储层重构** — MongoDB 和文件系统作为主存储二选一，Redis 仅作可配置缓存层（开关、TTL、大小限制），移除 RedisStorage
- **MongoDB TTL 索引修复** — 索引从无效的 `expires` 字段改为 `created` + `expireAfterSeconds`，滚动删除正确生效
- **FilesystemStorage 完整实现** — 修复 `StorageInterface` 兼容性，存储完整文档（含 token、metadata、source）
- **依赖版本校验** — aternos/codex v4.1.0、codex-minecraft v5.2.0、sherlock v1.1.3 均已最新

### 修复

- **AccessTokenFilter 引号格式** — 修复 pattern 中多余 `"` 导致的匹配失败
- **ClientIdFilter/SessionTokenFilter 模式修复** — 去除 pattern 中多余的引号，确保正确匹配
- **IPv6 地址过滤优化** — 正确处理 `::ffff:127.0.0.1` 等混合格式
- **各类过滤器 PHP 8.5 兼容** — 避免可变长后顾断言，改用简单模式

### 文档

- README / API.md / AGENTS.md 全面更新
- 生成 Postman Collection（23 个请求）
- 新增 CHANGELOG.md 更新日志

### 弃用

- **`/1/` API 端点** — 标记为已弃用，推荐使用 `/v1/` 替代。`/1/log` 上传接口将在过渡期内保持可用

## 1.5.4 — 2026-07-28

### 新功能

- **新增 `/v1/` API 端点** — 全部接口新增 `/v1/` 版本路径，原有 `/1/` 端点保持向后兼容，便于生态逐步迁移
- **敏感数据过滤增强** — 新增 6 个过滤器：UUID、IPv6 短格式、Xbox XUID、会话令牌、客户端/设备 ID、Minecraft 坐标
- **Postman 文档生成** — 基于 OpenAPI 3.1 规范自动生成 Postman Collection，可直接导入测试

### 改进

- **OOP 架构重构** — 代码目录扁平化，文件命名规范化，提升可维护性
- **测试框架搭建** — 引入 PestPHP 测试框架，覆盖过滤器、Handler 和 API 集成测试（47 个测试通过）
- **`safePregReplace` 安全包装** — 所有过滤器统一使用安全正则替换，避免 PHP 8.5 兼容性问题
- **OpenAPI 规范更新至 1.5.4** — 完整描述全部 20+ 端点，标记 `/1/` 为已弃用

### 修复

- **AccessTokenFilter 引号格式** — 修复 pattern 中多余 `"` 导致的匹配失败
- **ClientIdFilter/SessionTokenFilter 模式修复** — 去除 pattern 中多余的引号，确保正确匹配
- **IPv6 地址过滤优化** — 正确处理 `::ffff:127.0.0.1` 等混合格式
- **各类过滤器 PHP 8.5 兼容** — 避免可变长后顾断言，改用简单模式

### 弃用

- **`/1/` API 端点** — 标记为已弃用，推荐使用 `/v1/` 替代。`/1/log` 上传接口将在过渡期内保持可用