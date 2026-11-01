# LogShare 全仓库 Code Review 报告（第四期）

## 修复与审查历史

- **第一期（2026-08-30）：** 64 项问题（严重 2、一般 21、建议 41），除 G10 外全部闭环。
- **第二期（2026-09-11）：** 22 项问题（严重 5、一般 10、建议 7），全部闭环。
- **第三期（2026-09-21）：** 33 项问题（严重 10、一般 12、建议 11），随 v1.8.0 事件队列 / 规则加密 / LogAgent 重构落地。
- **第四期（2026-11-01，当前）：** v1.8.4 剥离 AI/Agent/RAG 之后的全量复审。共发现 **42 项问题**（严重 5、一般 21、建议 16），其中 **S1 为可复现的权限提升**、**S2 为默认凭据风险**、**S4 为可稳定复现的上传 500**。本期同时复核了第三期遗留项在剥离后的状态。**42 项已全部修复**，修复方式与验证结果见文末「第四期修复记录」。

---

## 概览

- **审查日期：** 2026-11-01
- **审查基线：** `main` @ `35a5d17`（v1.8.4）
- **技术栈识别：**

| 类别 | 结论 | 依据 |
|---|---|---|
| 语言 | PHP 8.4+（主体）、Lua 5.1/LuaJIT（边缘）、Bash/sh、Python 3（映射下载） | `composer.json`、`OpenLiteWaf/lua`、`scripts/*.sh`、`scripts/download_vanilla_mappings.py` |
| 框架 | Hyperf 3.2 + Swoole 6.2 常驻协程 | `composer.json`、`config/autoload/server.php` |
| 构建/包管理 | Composer（锁文件 `composer.lock`）、Docker 多阶段构建 | `composer.json`、`docker/hyperf.Dockerfile` |
| 数据库 | MariaDB 11（天级 RANGE 分区）、文件系统（二选一） | `docker/mariadb-init.sql`、`app/Storage/*` |
| 缓存/队列 | Redis 7（可选）：缓存、限流计数、Streams 事件队列 | `app/Client/RedisClient.php`、`app/Queue/*` |
| 测试 | Pest 3 / PHPUnit 11；架构测试组；Lua 回归（子模块内） | `pest.php`、`phpunit.xml`、`tests/Architecture/ArchitectureTest.php` |
| 静态分析 | PHPStan 2.2，level 5，仅 `app/` | `phpstan.neon` |
| CI/CD | CNB（主）+ GitHub Actions（镜像仓库仍在触发） | `.cnb.yml`、`.cnb/*`、`.github/workflows/*` |

- **核心源码目录：** `app/`（70 个 PHP 文件，11,347 行）、`config/autoload/`、`bin/`、`core.php`、`scripts/`。
- **非审查目录（已排除）：** `vendor/`、`runtime/`、`tmp/`、`testlogs/`、`.git/`、`coverage/`、`mappings/`（LFS 数据）、`docker/certs/`。
- **文件规模：**

| 范围 | 文件数 | 行数 |
|---|---|---|
| `app/` PHP | 70 | 11,347 |
| `tests/` PHP | 26 | 4,823 |
| `config/` + 入口 | 14 | ~400 |
| `scripts/` | 12 | ~1,600 |
| `OpenLiteWaf/` Lua + 测试 | 6 | 2,173 |
| `OpenLiteStats/` Lua + 测试 | 4 | 1,043 |

- **执行过的验证（全部为本机真实输出）：**

```text
composer validate --strict      → ./composer.json is valid
composer stan                   → [OK] No errors (69 files)
pest --group=architecture       → Tests: 7 passed (72 assertions)
pest（默认本地配置）             → Tests: 1 failed, 18 skipped, 180 passed
php OpenLiteWaf/tests/openlitewaf_regex_test.php → 64 条规则 / 99 个样本全部通过
lua5.1 OpenLiteWaf/tests/openlitewaf_logic_test.lua → 全部通过
lua5.1 OpenLiteStats/tests/openlitestats_logic_test.lua → 全部通过
php bin/hyperf.php list         → 注解扫描正常
运行时冒烟                       → /v1/limits 200；/v1/ai/*、/rag、/v1/admin/ai|rag/* 均 404；POST /v1/analyse 200
```

- **整体评价：** 剥离 AI/RAG 后，代码边界明显更清晰，分层（Controller → Storage/System/Queue → Client）稳定，文档密度与运维可读性在同类项目中属上游水平。**但存在一个可复现的越权删除路径（S1）和一个默认凭据+默认开启后台的组合风险（S2）**，另有若干“文档/契约/实现三方不一致”的问题，属于发布前应修复项，而非风格问题。

---

## 问题清单

### 一、严重问题（Critical）

#### S1. 去重命中时用新 token 覆盖缓存，第二个上传者可删除他人日志

- **位置：** `app/Log.php:475-494`、`app/Storage/MariaDbStorage.php:36-40`、`app/Log.php:69-80`
- **问题：** `MariaDbStorage::Put()` 命中指纹时直接返回既有 ID，且**不写入任何新行**。但 `Log::put()` 无论是否命中都会用本次请求新生成的 token 计算哈希，并**无条件写入 Redis 缓存**：

```php
$this->id = $storage::Put(...);          // 命中去重时返回旧 ID
...
$this->saveToRedisCache([
    'token' => $this->token->get(),      // 新 token 的哈希，覆盖了原 token
]);
```

随后 `Log::load()` **优先读缓存**，因此该 ID 的删除凭证被替换为第二个上传者的 token。`API.md:116` 声称“命中去重时 token 不能用于删除”，与实现相反。

- **复现（本机真实输出，使用内存存储 + RedisMock 模拟生产缓存路径）：**

```text
A id=kABC123 token=339c4782c8ab...
B id=kABC123 token=8282a1220088...
verify with A token: false        ← 原始上传者已无法删除
verify with B token (should be false): true   ← 第二个上传者获得删除权
```

- **影响：** 任何人只要拿到与目标日志相同的正文（分享链接即公开正文）+ 相同 source，即可上传一次并取得删除权，物理删除他人日志。属于越权删除（IDOR）。
- **建议：**
  1. 去重命中时，`Put()` 应返回“是否新建”标志；命中则**跳过缓存写入**，或从存储回填原始 token 后写入。
  2. 更稳妥：缓存 payload 中区分 `ownerTokenHash` 与请求 token，删除鉴权只认存储层权威值。
  3. 补一条回归测试：两次相同内容上传后，用第一次 token 删除必须成功，第二次必须 403。

#### S2. 全新部署默认开启后台，且 token 是公开的占位字符串

- **位置：** `Config.inc.example.php:135-141`、`docker/compose.yaml:95-96`、`.env.example`（无 `ADMIN_TOKEN`）
- **问题：** 示例配置的 `admin.token` 为 `change-this-to-a-secure-random-token`；Compose 又把 `ADMIN_ENABLED` 默认设为 `true`、`ADMIN_TOKEN` 默认空串。`Config::validate()` 只校验“enabled 时 token 非空”，占位串能通过校验。
- **复现：**

```text
fresh deploy -> admin.enabled=true token=change-this-to-a-secure-random-token
AdminAuth would accept placeholder: true
```

- **影响：** 按 README 的 `cp .env.example .env` 直接部署，`/v1/admin/*` 对公网开放，且任何读过仓库的人都能用占位 token 登录：可读取全部日志正文、批量删除、修改安全规则、封禁 IP。
- **建议：**
  1. 示例 token 置空，并让 `validate()` 拒绝 `change-this-*`、`replace-with-*`、长度 < 32 的值。
  2. Compose 的 `ADMIN_ENABLED` 默认改为 `false`，或在 token 为空时强制关闭。
  3. `.env.example` 增加 `ADMIN_TOKEN=` 并注明“必须自定义”。
  4. 启动时若使用占位 token，`Syslog::error()` 显式告警。

#### S3. `Token::matches()` 明文回退使“库里的哈希”本身成为可用凭证

- **位置：** `app/Data/Token.php:26-33`
- **问题：**

```php
return hash_equals($this->value, hash('sha256', $token))
    || hash_equals($this->value, $token);   // 明文回退
```

`Log::load()` 用存储值（SHA-256）构造 `Token`。第二个分支让“请求方直接提交哈希”也成立。

- **复现：**

```text
$holder = new Token($storedHash);
$holder->matches($plaintext) → true
$holder->matches($storedHash) → true   ← 越权
```

- **影响：** 任何能读到 `logs.token` 的路径（备份、只读库账号、SQL 注入、日志导出、后台 `getLog` 之外的泄露）都可直接当删除凭证使用，哈希失去“泄露后不可冒用”的意义。与 S1 组合时放大器更明显。
- **建议：** 存量兼容改为“读取时标记是否为 legacy 明文”，仅在 legacy 记录上做明文比较；新写入一律只做哈希比较。或一次性迁移：把 legacy 明文哈希化后删除回退分支。

#### S4. 同名附件与重复 metadata key 未去重，直接触发 MariaDB 主键冲突 → 上传 500

- **位置：** `app/UploadParser.php:63-120`（不去重）、`app/Data/MetadataEntry.php:22-46`（不去重）、`app/Storage/MariaDbStorage.php:186-218`（批量 insert）、`docker/mariadb-init.sql:45,59`（主键含 `name` / `key`）
- **问题：** `log_files` 主键为 `(log_id, name, created)`，`log_metadata` 为 `(log_id, key, created)`。上传侧允许同名条目：

```text
2 entries: main.log,main.log          ← parseFiles 原样接受
2 entries, keys: version,version      ← allFromArray 原样接受
```

- **影响：** 客户端一次上传两个 `main.log`（常见于“主日志 + 压缩包内同名文件”），或提交重复 metadata key，`insert` 抛 `Duplicate entry`，被 `AppExceptionHandler` 兜底成 500，且 `Put()` 内的事务已回滚、无任何用户可读的错误说明。
- **建议：**
  1. `UploadParser::parseFiles()` 对 `name` 做大小写敏感去重（保留第一个或追加 `(2)` 后缀），并明确返回冲突而非静默丢弃。
  2. `MetadataEntry::allFromArray()` 以 key 去重（后者覆盖前者或丢弃）。
  3. 在 `MariaDbStorage::Put()` 内对重复做防御性合并，避免未来新增调用点再次踩坑。
  4. 补测试：同名附件、重复 key、压缩包内外同名。

#### S5. 读请求随机触发分区 DDL，GET 可能阻塞并持有表锁

- **位置：** `app/Log.php:573-583`、`app/System/LogPartitionManager.php:106-143`
- **问题：**

```php
if (mt_rand(1, 100) === 1) {
    ... CleanupExpired()   // → ensureFallbackPartition() + dropExpiredPartitions()
}
```

`CleanupExpired()` 会执行 `ALTER TABLE ... ADD PARTITION` 与 `ALTER TABLE ... DROP PARTITION`。而 `renew()` 被 `GET /v1/raw/{id}` 与 `GET /v1/insights/{id}` 调用（`RawController.php:34`、`InsightsController.php:23`）。

- **影响：** 约 1% 的读取请求会在请求生命周期内执行 DDL。DDL 需要元数据锁，可能阻塞同表写入（上传）并拉长读延迟；在 Swoole 协程模型中，`Db::statement` 虽是协程客户端，但锁等待会占用连接池。此外 `ensureFallbackPartition()` 每次清理都跑一遍 `information_schema` 查询。
- **建议：**
  1. 读路径只做 `Renew`，清理交给 `mariadb-events` 定时事件与 Admin 手动端点。
  2. 若必须保留“读时清理”，改为投递到 EventQueue 异步执行，并加分布式锁防并发 DDL。
  3. `ensureFallbackPartition()` 加进程内 TTL 缓存（如 5 分钟）。

---

### 二、一般问题（Moderate）

#### M1. `Config::Get('admin.token')` 返回空数组，两个运维脚本取不到 token

- **位置：** `scripts/test_online_apis.php:7`、`scripts/test_endpoints_host.sh:4`
- **验证：**

```text
Config::Get("admin.token") → array(0) {}
PHP Warning: Array to string conversion
Admin token configured: NO
```

- **影响：** 线上巡检脚本在带鉴权的接口上全部 401/403，却仍打印“通过/失败”统计，容易误判服务健康。`test_endpoints_host.sh` 会把 `Array` 当作 token 发出去。
- **建议：** `Get('admin')['token']`；或给 `Config` 增加受控的点路径访问 `GetPath('admin.token')`，并让 `Get()` 对含 `.` 的键显式抛错，避免再次误用。

#### M2. `filter.pre` 未校验类存在性与接口，管理员一次误配置可让全部上传 500

- **位置：** `app/Config.php:275-287`（动态合并 `filter.pre`）、`app/Log.php:608-613`
- **验证：**

```text
saveDynamic accepted arbitrary filter class
["App\\Nonexistent\\Evil"]
```

- **影响：** `preFilterValue()` 直接 `$preFilterClass::filter($data)`，类不存在时抛 `Error`；上传、`/v1/analyse` 全部 500，且需要回滚动态配置才能恢复。
- **建议：** `validate()` 中遍历 `filter.pre`，要求 `class_exists` 且 `is_subclass_of(..., App\Filter\Filter::class)`；同时校验 `storage.storages[*].class` 实现 `StorageInterface`、`cache.cacheId` 实现 `CacheInterface`。

#### M3. 生产路径使用 Redis `KEYS`，O(N) 阻塞

- **位置：** `app/System/SecurityService.php:155`（`security:ban:*`）、`app/System/StorageHealthService.php:110`（任意 prefix）、`app/Telemetry/TelemetryService.php:355`（`telemetry:*`）
- **影响：** `KEYS` 在单线程 Redis 上阻塞全库；`/v1/admin/security/bans` 是管理端高频轮询接口，数据量上来后会拖慢所有依赖 Redis 的请求（缓存、限流、事件队列）。
- **建议：** 改用 `SCAN` 游标分批；封禁名单改为 Set/Hash 索引；遥测清理按已知日期键删除而非 `KEYS`。

#### M4. `flushCache` 支持 `*` 直接 `FLUSHDB`

- **位置：** `app/System/StorageHealthService.php:105-108`、`app/Controller/AdminController.php:328-336`
- **影响：** 管理员误传 `{"prefix":"*"}` 会清空整个 Redis DB，包括事件队列 Stream、消费组、封禁记录、动态配置版本号，属于不可逆运维事故。
- **建议：** 移除 `all`/`*` 分支，或要求二次确认参数（如 `confirm: "FLUSH_ALL"`），并把允许的前缀列入白名单（`log:*`）。

#### M5. 遥测公开端点无鉴权、无长度上限，可被用来撑爆 Redis

- **位置：** `app/Telemetry/TelemetryService.php:56-75,365-375`、`app/Controller/TelemetryController.php:20-41`
- **问题：** `cleanEndpoint()` 只做 `parse_url` 与 ID 模板化，不截断长度；`endpoints` 的 hash field 为 `METHOD path:count`，path 可任意长；batch 上限 50，但可无限次调用。
- **验证：** 5008 字节路径 → 输出仍 5008 字节。
- **影响：** 单 IP 可持续写入无限长 field 与无限多端点键，Redis 内存与 hash 元素数无上界；`getStats` 还会把这些键全部读出。
- **建议：** 路径截断到 200 字符；端点键数量上限（如 Top 1000 + 其它归并 `:other`）；给 `/telemetry/report` 加边缘限流或应用层计数。

#### M6. 审计日志无轮转，查询时全量读入内存

- **位置：** `app/System/AuditLogManager.php:66-72`（追加）、`117-130`（`file()` 全量）
- **影响：** `runtime/logs/audit.log` 随运营时间单调增长；每次 `/v1/admin/audit/logs` 都把整个文件读成数组再过滤分页，内存与延迟随文件线性上升（对比 `Syslog` 有 5MB 轮转）。
- **建议：** 复用 `Syslog` 的轮转策略（含大小上限与保留份数）；查询改用从文件尾部反向读取 N 行，或落 MariaDB 表。

#### M7. `MariaDbStorage::Update()` 恒返回成功

- **位置：** `app/Storage/MariaDbStorage.php:129-147`
- **问题：** `return $updated >= 0;`——`update()` 返回受影响行数，0 行（ID 不存在）也 ≥ 0。
- **影响：** `Log::updateContent()` 会把不存在的记录当作更新成功，并继续刷新缓存，掩盖数据丢失。
- **建议：** 先查存在性（或用 `currentCreated` 的返回值判空），0 行时返回 `false`。

#### M8. `FilesystemStorage::Put()` 占位循环无上限

- **位置：** `app/Storage/FilesystemStorage.php:25-32`
- **问题：** `do { regenerate(); fopen($path,'x') } while ($placeholder === false);` 无最大重试次数。目录不可写、inode 耗尽、磁盘满时会无限循环并疯狂生成随机 ID。
- **影响：** 单请求打满 CPU；Swoole worker 被长时间占用。
- **建议：** 最多重试 5 次，失败抛明确异常；`fopen` 失败时区分 `EACCES`/`ENOSPC` 与“ID 冲突”。

#### M9. 子表查询只按 `log_id` 关联，孤儿子行会被新日志继承

- **位置：** `app/System/AnalyticsService.php:137-147,158-168`、`app/Storage/MariaDbStorage.php:399-403`、`app/System/LogPartitionManager.php:112-120`
- **问题：** 三张表主键都含 `created`（`(id, created)` / `(log_id, name, created)` / `(log_id, key, created)`），但所有子表读取与统计都只按 `log_id = l.id` 关联，不带 `created`。插入侧靠 `idExists()`（只查 `logs`）防重，所以正常情况下不会重号；**一旦父表分区被丢弃而对应子表分区因故未丢弃**，子表就会留下孤儿行。此时该 6 位 ID 重新变得可用（父行已不存在），新日志会与旧孤儿行按 `log_id` 关联上。
- **触发链：** `dropPartition()` 吞掉异常（见 A6），`$dropped++` 仍计数 → 三表分区边界出现“父删子留” → ID 复用后统计/`filesCount` 串入上一代数据。
- **影响：** 版本/加载器统计虚高、`filesCount` 虚高；属于低频但静默的数据正确性问题。
- **建议：** 子表关联一律补 `AND m.created = l.created`（同 `created` 必然同分区）；`dropExpiredPartitions()` 只在真正成功时计数，并对三表做一致性校验（父分区已删而子分区仍在时告警）。

#### M10. ZIP 解压先分配后校验，仍存在内存炸弹窗口

- **位置：** `app/UploadParser.php:180-198`
- **问题：** 代码注释已承认：`$zip->getFromIndex($i)` 会按 central directory 声明或实际大小分配内存，之后才做 `strlen` 复检。声明 size 被篡改的超高压缩比条目可在复检前耗尽内存。
- **影响：** 单个恶意 ZIP 可触发 worker OOM（`memory_limit` 未在 `php.ini` 显式收紧时更危险）。
- **建议：** 用 `ZipArchive::getStream()` 流式读取并分块累计，超预算立即中止；或先校验 `comp_size`/`size` 比与条目数上限。

#### M11. IPv6 CIDR 不受支持，IPv6 反代客户端 IP 判定失效

- **位置：** `app/System/SecurityService.php:118-140`
- **验证：**

```text
ipInCidr("2001:db8::1", "2001:db8::/32") → false
isIpTrusted("2001:db8::1", ["2001:db8::/32"]) → false
```

- **影响：** `trustedProxies` 里的 IPv6 网段永远不匹配；IPv6 前向代理部署下，`resolveClientIp()` 退化为 `remote_addr`（即代理地址），导致封禁/限流按代理 IP 聚合，可能误封整片用户。
- **建议：** 对 IPv6 使用 `inet_pton` + 位掩码比较（或 `ip6_in_cidr` 辅助函数），并补单测。

#### M12. 规则加密失败时静默落明文

- **位置：** `app/System/SecurityService.php:533-548`
- **问题：** `openssl_encrypt()` 返回 `false` 时 `return $plaintext;`。调用方随后把结果当密文写入 `runtime/content_reject_rules.json` 与 `dynamic_config.json`。
- **影响：** 磁盘上出现明文违规关键词，与“杜绝明文敏感词物理暴露”的承诺相反，且没有任何告警。
- **建议：** 加密失败抛 `RuntimeException`，由 `saveContentRules()` 返回 500 并保持旧规则；至少 `Syslog::error` 并拒绝写入。

#### M13. 公开元信息接口输出 `visible=false` 的条目

- **位置：** `app/Controller/LogMetaController.php:29`、`app/Data/MetadataEntry.php:162-169`
- **问题：** `visible` 字段只在序列化时输出，没有任何消费方过滤；`GET /v1/log/{id}` 与 `AdminController::getLog()` 都直接 `array_map(jsonSerialize)`。
- **影响：** 客户端标记为“不展示”的元数据（可能含内部标识、路径、环境信息）仍然公开返回，`visible` 形同虚设。
- **建议：** 公开接口只输出 `isVisible() === true` 的条目；管理端可保留全部并显式标注。

#### M14. OpenAPI 契约与实现三处不符

- **位置：** `openapi.yaml:14`、`openapi.yaml:1494`、`Config.inc.example.php:78`
- **问题：**

| 契约 | 实现 |
|---|---|
| `All endpoints are rate limited per IP (default: 36,000 requests/minute)` | 应用层默认 `[60, 60]`（60 次/60 秒），且 `RateLimitMiddleware` **未注册**；实际限速只由 nginx `limit_req 30r/s` + WAF CC 承担 |
| `token pattern: '^[a-zA-Z0-9]{16}$'` | `Token::generate()` 为 `bin2hex(random_bytes(32))` → **64 位十六进制** |
| 删除接口 `Authorization` 示例 16 位 | 同上 |

- **影响：** 客户端按 16 位做校验会拒绝合法 token；按 36,000/min 设计重试策略会与真实限速严重不符。
- **建议：** token 改为 `^[a-f0-9]{64}$`；限流描述改为“边缘 30r/s + WAF CC”，并注明应用层中间件未启用；补一条契约测试（用真实上传响应校验 pattern）。

#### M15. GitHub Actions 用 PDO 导入含 `DELIMITER` 的 SQL，必然失败

- **位置：** `.github/workflows/ci.yaml:70-73`
- **验证：** `docker/mariadb-events.sql` 含 4 处 `DELIMITER`；PDO `exec()` 不支持客户端指令（ERROR 1064）。
- **影响：** GitHub 镜像仓库的 CI 在 “Initialize MariaDB Schema & Events” 步骤失败；CNB 侧因使用 `scripts/ci_init_mariadb.sh` 走客户端而不受影响，形成“两套 CI 一红一绿”。
- **建议：** 与 CNB 对齐，改用 mysql/mariadb 客户端导入：

```bash
mariadb -h127.0.0.1 -uroot -proot logshare < docker/mariadb-init.sql
mariadb -h127.0.0.1 -uroot -proot logshare < docker/mariadb-events.sql
```

#### M16. `TelemetryAndSyslogTest` 依赖全局 Redis 状态，本地默认配置必挂

- **位置：** `tests/Unit/TelemetryAndSyslogTest.php:100`、`Config.inc.php:22`
- **验证：**

```text
无 Redis → 1 failed, 1 passed
启动 Redis + REDIS_HOST=127.0.0.1 → 仍 1 failed（因为 cache.enabled=false）
```

- **影响：** 开发机全量测试永远有一条红色；团队会逐渐忽略测试失败信号。CI 之所以绿，只是因为 `Config.inc.example.php` 的 `cache.enabled=true`。
- **建议：** 测试内显式 `RedisClient::setTestConnection(new RedisMock())`，并让 `TelemetryService` 在 `RedisMock` 下可断言；或按 `cache.enabled` 做 `markTestSkipped`，不要断言 0 以外的具体值。

#### M17. 覆盖率排除最易出错的层，CI 也不跑 Lua 回归

- **位置：** `pest.php:27-37`、`.cnb/ci.yml`
- **问题：** `coverage.exclude` 排除了 `app/Storage/*`、`app/Client/*`、`app/Cache/*`、`app/Data/*`——本期 S1/S3/S4/S7 全部落在这几层。CNB/GitHub 的 CI 步骤里没有任何 `lua5.1` 或 `openlitewaf_regex_test.php` 调用。
- **影响：** 覆盖率数字“好看”但与风险分布相反；WAF 规则与统计逻辑的回归只能靠人工执行。
- **建议：** 取消 `Storage`/`Data` 的覆盖率排除（MariaDB 用例用 `requireDb()` 跳过，不影响覆盖率门槛）；CI 增加 Lua 回归 stage（镜像里装 `lua5.1` 即可，无需 OpenResty）。

#### M18. 上传同步执行 Codex 解析，与“毫秒级响应”不符

- **位置：** `app/Log.php:433-438`
- **实测：**

```text
3.5MB / 40,001 行 → codex detect+parse+analyse: 1.92s
```

- **影响：** 上传 P99 延迟被 1~2 秒量级的 CPU 解析主导；并发上传时 Swoole worker 被同步阻塞（Codex 是纯 CPU，期间不让出事件循环），会拖慢同 worker 上的其它请求。README 的“毫秒级响应”只对大日志之外的小文件成立。
- **建议：** 把 Codex 解析下沉到 EventQueue（与反混淆同一流水线），上传只做 `preFilter` + 落库；`insights` 首次访问时按需解析并缓存。若必须同步，至少限制只解析前 N KB 头部。

#### M19. `LogController::create` 未处理 `put()` 的可空返回

- **位置：** `app/Controller/LogController.php:52-60`
- **问题：** `$id = $log->put(...);` 后直接 `$id->get()`。`put()` 与 `StorageInterface::Put()` 的签名都是 `?Id`；当前两个存储实现要么返回 `Id` 要么抛异常，所以暂不触发，但契约本身允许 null。任何未来实现（或异常被上层吞掉的路径）返回 null 时，会退化成 `Call to a member function get() on null` 的 500，而非可读的业务错误。
- **建议：** 显式判空并抛 `ApiError(500, 'Failed to persist log')`，同时 `Syslog::error` 记录原因；若确定永不为 null，则把返回类型收紧为非空，让静态分析守住契约。

#### M20. `source` 用 `substr` 按字节截断，会切坏 UTF-8

- **位置：** `app/Storage/MariaDbStorage.php:30-32`、`app/Storage/FilesystemStorage.php:52-54`、`app/ContentParser.php:161`
- **验证：**

```text
substr(启动器名称…/1.2.3, 0, 64) → bytes=64 valid_utf8=false
```

- **影响：** 中文启动器名被截断成非法 UTF-8，写入 `utf8mb4` 列时报 1366/“Incorrect string value”，或让 `json_encode` 返回 false（响应体为空）。
- **建议：** 统一用 `mb_strcut($source, 0, 64, 'UTF-8')`，并补一条中文 source 用例。

#### M21. CORS 对管理端过于宽松

- **位置：** `app/Middleware/CorsMiddleware.php:31-33`
- **问题：** `Allow-Origin: *` + `Allow-Headers: *`。管理端用 Bearer token，一旦 token 出现在浏览器侧（例如后台 SPA 与 API 同域但被第三方页面引用），任意源都可发起读请求；`Allow-Headers: *` 还允许自定义头绕过部分代理策略。
- **建议：** 管理端与公开端分离 CORS：公开端保持 `*`，管理端改为配置化 origin 白名单（`urls.baseUrl`），并显式列出允许头（`Authorization, Content-Type, X-Admin-Token`）。

---

### 三、建议（Minor）

| 编号 | 位置 | 问题 | 建议 |
|---|---|---|---|
| A1 | `app/Storage/FilesystemStorage.php:5`、`app/Storage/MariaDbStorage.php:5`、`app/Log.php:15` | 未使用的 `use`（`MetadataEntry`、`Filter`） | 删除；可在 CI 加 `php -l` + 简单 unused-import 检查 |
| A2 | `app/Command/`（空目录）、`.gitignore:15-16`、`.dockerignore:13-14`、`plan.md` | RAG 剥离后的残留：空命令目录、rag 忽略规则、533 行已废弃的 RAG 扩展计划仍被跟踪 | 删除空目录与忽略项；`plan.md` 移入 `docs/archive/` 或删除 |
| A3 | `composer.json:11-37` | 缺少 `ext-zip`（`UploadParser`）、`ext-curl`（`SecurityService`/健康检查）、`ext-openssl`（AES-GCM）；`license: proprietary` 与 `LICENSE`（MIT 正文）冲突 | 补扩展声明；license 与 LICENSE 对齐 |
| A4 | `README.md:56,174` | “Compose 定义五个服务”实为六个（漏 `acme`）；“CC 超限封禁 IP 600 秒”与现实现（429 不封）不符 | 按 `docker compose config --services` 与 WAF v1.3.0 行为更新 |
| A5 | `app/System/SecurityService.php:251-255`、`app/System/AnalyticsService.php:199-201` | 悬空/重复 docblock 与注释（第三期遗留） | 删除重复块，避免 IDE 提示与阅读噪声 |
| A6 | `app/System/LogPartitionManager.php:117-118,191-198` | `dropPartition()` 内部 `catch` 后静默返回，外层无条件 `$dropped++`：分区未删掉却报告已回收，且与 M9 的孤儿行互为因果 | 让 `dropPartition()` 返回 bool，只统计成功项；失败时 `Syslog::error` 已记录，调用方据此告警 |
| A7 | `app/Config.php`（62 处 `Get`）、上传路径 19 处 | 每次 `Get()` 都 `ensureFresh()`：`clearstatcache` + 可能一次 Redis `GET`；单次上传可产生十余次配置检查 | 请求级快照（协程 Context 缓存），或把 `config:dynamic:version` 检查降到秒级 TTL |
| A8 | `app/Config.php:389-402` vs `434-449` | 校验的是 `$candidate`（基于内存数据），写入的是 `$newDynamic`（基于磁盘旧值），两者在并发写时可能不同 | 校验 `$newDynamic` 后再落盘 |
| A9 | `phpstan.neon` | 只分析 `app/`，level 5 | 提升到 level 7+，纳入 `scripts/` 与 `bin/`；`ignoreErrors` 已精简，正好借机收紧 |
| A10 | `app/Storage/FilesystemStorage.php:280-340` | `List/Count` 每次 `scandir` 全目录并逐文件读 meta，O(N) | 维护 `index.json` 或迁移到 SQLite 索引；至少让 `Count` 复用 `List` 的扫描结果 |
| A11 | `app/System/AuditLogManager.php:132-152` | 过滤在内存中全表扫描 | 与 A6 一起改为尾部读取 + 早期过滤 |
| A12 | `app/Queue/EventQueue.php:148-190` | `dispatch()` 未使用 `$options['dedupKey']`（注释声明支持） | 实现或删除该选项，避免误导调用方 |
| A13 | `app/Telemetry/TelemetryService.php:33-36` | Redis 不可用时 `recordBatch()` 返回 `count($items)`，接口显示“已处理”但无任何落库 | 返回 0 或标记 `degraded`，让上报方与后台可见 |
| A14 | `tests/Unit/MariaDbStorageTest.php`、`tests/Integration/*` | 集成用例大量 `markTestSkipped`，本地默认全跳过 | 增加 `docker compose` 一键测试脚本并在 README 标注“本地全量测试需 MariaDB/Redis” |
| A15 | `app/Version.php` 与文档示例 | 版本号已 1.8.4，但 `API.md` 的 Admin 系统统计示例仍写 `"version": "1.7.8"`（其余 `1.20.1`/`1.21.9` 是 Minecraft 版本，属正常） | 示例统一为当前版本或改为 `1.x.y` 占位符，避免每次发版手改 |
| A16 | `openapi.yaml`、`app/Controller/IndexController.php:16-38` | 契约覆盖不全：`POST /v1/telemetry/report` 未进 OpenAPI；根端点清单只列 20 条而实际注解路由 44 条（管理员端点除外仍缺 `telemetry`） | 用注解扫描或手写清单做一致性测试；补齐 telemetry 与 `/1` 别名 |

---

## 改进建议（按优先级与可操作性）

### 第 1 批：发布前必须修（1~2 天）

1. **修 S1（去重 token 覆盖）**
   - `StorageInterface::Put()` 返回值改为 `array{id: Id, created: bool}`，或在 `Log::put()` 内先 `findByFingerprint` 判断。
   - 命中时跳过 `saveToRedisCache()`；若缓存已存在，回填存储层 token。
   - 回归用例：`dedupDoesNotReplaceDeletionToken`。

2. **修 S2（默认凭据）**
   - `Config.inc.example.php` 的 `admin.token` 置空。
   - `Config::validate()` 拒绝占位串与 < 32 字符 token。
   - `docker/compose.yaml` 的 `ADMIN_ENABLED` 默认 `false`。

3. **修 S4（重复键）**
   - `UploadParser` 与 `MetadataEntry` 入口去重；`MariaDbStorage` 内做二次防御。
   - 回归用例覆盖同名附件与重复 key。

4. **修 M1（脚本 token）与 M14（契约）**
   - 两行改动即可让巡检脚本恢复有效。
   - OpenAPI token pattern 改 64 位、限流描述与实现对齐。

### 第 2 批：两周内修（影响稳定性/安全纵深）

5. **S5：把分区清理移出读路径**，改由事件或队列异步执行，并加锁。
6. **M13：公开接口过滤 `visible=false`**。
7. **M11：补 IPv6 CIDR**，并补 6 个单测（v4/v6 × 命中/不命中/非法）。
8. **M3/M4：替换 `KEYS`、移除 `FLUSHDB` 快捷路径**。
9. **M5/M6：遥测限长与审计轮转**。
10. **M12：加密失败 fail-closed**。
11. **M15/M16/M17：CI 对齐与测试可重复**。
12. **M7/M8/M19/M20：四处“静默成功/无限循环/空指针/截断乱码”**。

### 第 3 批：持续改进

13. **性能：** 上传路径 Codex 解析异步化（M18）；`Config` 请求级快照（A7）；`FilesystemStorage` 列表索引（A10）。
14. **可观测性：** 把 `Syslog` 的结构化字段（component/level/requestId）沉淀，替代字符串拼接；给关键路径加指标（上传耗时分解、DDL 次数、配置刷新次数）。
15. **契约驱动：** 用 OpenAPI 生成契约测试（token pattern、响应字段、状态码），避免 M14 类漂移。
16. **分层约束：** 扩展架构测试：禁止 Controller 直接 `Db::`、禁止 `Config::Get` 使用点路径（M1 的根因）、禁止 `Storage` 层 `echo`/`die`。
17. **子模块治理（A2）：** `OpenLiteWaf` / `OpenLiteStats` 的未提交改动（Termux 临时目录 → `/tmp`）目前只存在于工作区，建议先在子模块仓库提交并推送，再回主仓更新指针，避免主仓指针长期指向脏工作树。

---

## 正面亮点

1. **分区 + `DROP PARTITION` 的空间回收设计**（`docker/mariadb-init.sql`、`app/System/LogPartitionManager.php`）：直击 InnoDB `DELETE` 不归还磁盘的根因，文档把 ERROR 1517/1064/1506 的成因与规避写进了代码注释，属于“事故驱动的正确抽象”。
2. **`Renew()` 同步子表 `created`**（`app/Storage/MariaDbStorage.php:307-330`）：预判了父子分区错位会产生永久孤儿行，并用 `MariaDbStorageTest::testRenewKeepsChildTablesInSync` 守门。
3. **去重指纹设计**（`xxh128` + 附件名排序 + 旧库降级）：算法选择（非加密哈希、毫秒级）与降级策略（缺列不阻断上传）都恰当；问题只在 S1 的凭证处理。
4. **Token 存储与比较**：落库 SHA-256、`hash_equals` 常量时间、删除墓碑防缓存复活——方向正确，只需去掉 S3 的明文回退。
5. **上传安全的多层防御**：请求体解压上限、ZIP 路径穿越校验、条目数/总字节预算、解压后复检，注释明确标注残余风险（M10），是诚实的工程写法。
6. **WAF 规则的“作用域”模型**（`OpenLiteWaf/lua/openlitewaf.lua:82-90`）：把 `uri/path/ua/body` 做成位掩码而不是全局开关，直接来自“遥测 body 里出现 `main.log` 导致误封”的真实事故，且用 99 个样本的回归锁住。
7. **OpenLiteStats 的口径治理**：排除前缀按路径段边界匹配、空前缀守卫、热门端点模板化、IP 脱敏、`OPTIONS` 不计——每个细节都对应一次线上统计失真。
8. **EventQueue 的可靠性工程**：固定 worker 名、`XAUTOCLAIM` 回收、两阶段排空、自回收三重条件、死信 push 成功才 `XACK`/`XDEL`、`entries-read` 口径说明，属于同类项目中少见的完整实现。
9. **SpinYarn 的反射适配与失败节流**（`app/Client/SpinYarnClient.php:128-150,195-270`）：用 `supportsRedisArg()` 规避 4/5 参签名差异，并保留 60s 节流与旧句柄，直接对应一次线上故障。
10. **文档与代码的同源程度**：`AGENTS.md`、`API.md`、`openapi.yaml`、`CHANGELOG.md` 与代码注释互相引用，运维手册（部署、TTL、WAF 生效方式、证书）齐备，显著降低交接成本。
11. **架构测试的“最小但有效”**：控制器不得读超全局、不得写裸 SQL、继承/接口约束，配合 PHPStan 与 Pest，形成可执行的分层门禁。
12. **jemalloc 的 RSS 回落**（`docker/hyperf.Dockerfile:33-47`）：把“常驻进程内存只增不减”的宿主机现象转成分配器层面的可解释方案，并保留 `LD_PRELOAD`/`MALLOC_CONF` 的注释依据。

---

## 附录：本期验证命令与观察

```bash
# 依赖与静态
composer validate --strict
composer stan
php vendor/bin/pest --group=architecture

# 全量（本地默认配置，Redis 关闭）
php vendor/bin/pest

# 聚焦失败用例
php vendor/bin/pest tests/Unit/TelemetryAndSyslogTest.php

# 运行时冒烟
php bin/hyperf.php list
php bin/hyperf.php start   # 另一终端：
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:9501/v1/limits
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:9501/v1/ai/dummy
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:9501/rag
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:9501/v1/analyse \
  -H 'Content-Type: application/json' \
  --data-binary '{"content":"[12:34:56] [Server thread/INFO]: Starting minecraft server version 1.20.1"}'

# Lua 回归
php OpenLiteWaf/tests/openlitewaf_regex_test.php
lua5.1 OpenLiteWaf/tests/openlitewaf_logic_test.lua
lua5.1 OpenLiteStats/tests/openlitestats_logic_test.lua

# 关键复现（本期新增）
php -r 'require "core.php"; $t=new App\Data\Token(); $h=hash("sha256",$t->get());
        $o=new App\Data\Token($h); var_dump($o->matches($h));'          # S3 → true
php -r 'require "core.php"; var_dump(App\UploadParser::parseFiles([
          ["name"=>"main.log","content"=>"a"],["name"=>"main.log","content"=>"b"]]));'  # S4 → 2 条同名
php -r 'require "core.php"; var_dump(App\System\SecurityService::ipInCidr(
          "2001:db8::1","2001:db8::/32"));'                              # M11 → false
php -r 'require "core.php"; var_dump(App\Config::Get("admin.token"));'   # M1 → array(0)
```

> 说明：本期未对生产环境执行任何写操作；MariaDB 相关集成用例因本机未启动数据库而跳过，相关结论均基于静态代码、单测与可独立复现的纯函数/运行时行为。

---

## 第四期修复记录（2026-11-01，全部完成）

本期 42 项问题已全部修复并通过回归验证，未执行任何生产环境写操作。

### 严重问题

| 编号 | 状态 | 修复方式 | 验证 |
|---|---|---|---|
| S1 | 已修复 | `StorageInterface::Put()` 新增 `&$created` 出参；去重命中时 `Log::put()` 跳过缓存写入，响应带 `deduplicated: true`，且不再重复派发事件 | 真实 MariaDB + Redis：第二个上传者 token 删除返回 **400**，原始 token 返回 **200**，删除后 raw 为 **404** |
| S2 | 已修复 | 示例 `admin.token` 置空、`ADMIN_ENABLED` 默认 `false`；`Config::validate()` 拒绝占位串与 <32 字符 token；`.env.example` 补说明 | 占位 token 启动即抛 `InvalidArgumentException`；新增 10 条配置校验用例 |
| S3 | 已修复 | `Token::fromHash()` 区分存储侧与上传侧；存储格式统一为 `sha256:<hex>`，只做哈希比较；旧记录验证成功后惰性补前缀 | 单测断言 `matches($storedHash) === false`；集成测试断言直接提交库值返回 400 |
| S4 | 已修复 | `UploadParser::uniqueName()` 自动改名 `main (2).log`；`MetadataEntry` 与 `MariaDbStorage` 双重去重 | 真实上传同名附件 + 重复 metadata → 200，库中为 `main.log` / `main (2).log` |
| S5 | 已修复 | `Log::renew()` 移除 1% 概率的 `ALTER TABLE`；回收只走事件与 Admin 端点 | 读路径已无 `mt_rand` 与 `CleanupExpired` 调用 |

### 一般问题

| 编号 | 状态 | 修复方式 |
|---|---|---|
| M1 | 已修复 | `Config::Get()` 拒绝点路径并新增 `GetPath()`；两个巡检脚本改用 `Get('admin')['token']` |
| M2 | 已修复 | `Config::validate()` 校验 `filter.pre`、`storage.*.class`、`cache.cacheId` 的存在性与接口实现 |
| M3 | 已修复 | 封禁列表、缓存清理、遥测清理全部改为 `SCAN` 游标遍历；`RedisMock` 补齐 `scan/hExists/hLen` |
| M4 | 已修复 | `flushCache()` 改为前缀白名单，移除 `all` / `*` 的 `FLUSHDB` 分支；控制器对非法前缀返回 400 |
| M5 | 已修复 | 端点路径截断至 200 字符；单日端点键上限 2000，溢出归并 `:other` |
| M6 | 已修复 | 审计日志 5MB 轮转、保留 5 份；查询改为只读文件尾部 2MB 窗口 |
| M7 | 已修复 | `Update()` 先查 `created`，记录不存在直接返回 `false`；不再用受影响行数判断 |
| M8 | 已修复 | `FilesystemStorage` 与 `MariaDbStorage` 的 ID 分配都设 5~8 次上限，失败抛明确异常 |
| M9 | 已修复 | 统计关联与 `files_count` 子查询全部补 `created` 条件；`dropPartition()` 只统计成功项 |
| M10 | 已修复 | ZIP 改用 `getStream()` 分块读取，超预算立即中止，峰值内存降到 64KB/块 |
| M11 | 已修复 | `ipInCidr()` 支持 IPv6（`inet_pton` + 位掩码），补 8 个 v4/v6 用例 |
| M12 | 已修复 | `openssl_encrypt()` 失败抛 `RuntimeException`，拒绝落明文 |
| M13 | 已修复 | 新增 `MetadataEntry::serializeVisible()`，公开元信息接口过滤 `visible=false` |
| M14 | 已修复 | token pattern 改 64 位十六进制、限流描述改为边缘实现；补录 dedup 语义 |
| M15 | 已修复 | GitHub Actions 改用 `scripts/ci_init_mariadb.sh` + mariadb 客户端，并安装客户端 |
| M16 | 已修复 | 遥测/系统日志用例统一注入 `RedisMock`；顺带修正 mock 的 `keys()` 正则与 `scan` 游标类型 |
| M17 | 已修复 | 覆盖率不再排除 Storage/Data/Cache/Client；CNB 增加 Lua 回归 stage |
| M18 | 已修复 | 上传路径只解析 64KB 头部（3.5MB 日志 1.9s → 约 0.01s），全文分析移到 `/v1/insights` |
| M19 | 已修复 | `LogController::create` 判空并抛 `ApiError(500)` |
| M20 | 已修复 | `source` 全链路改用 `mb_strcut(..., 'UTF-8')` |
| M21 | 已修复 | `/admin` 路径只回显 `urls.corsOrigins` 白名单并使用显式头列表；公开端保持通配 |

### 建议项

| 编号 | 状态 | 修复方式 |
|---|---|---|
| A1 | 已修复 | 删除未使用的 `use` 与悬空 docblock |
| A2 | 已修复 | 删除空 `app/Command/` 与 RAG 忽略项；`plan.md` 归档到 `docs/archive-rag-plan-2026-08.md` 并标注历史状态 |
| A3 | 已修复 | 补 `ext-curl` / `ext-openssl` / `ext-zip`；`license` 改为 MIT；升级 `symfony/http-foundation` 修复 CVE-2026-108082 |
| A4 | 已修复 | README 服务数改六个、CC 行为改为 429 不封 IP |
| A5 | 已修复 | 清理重复 docblock 与重复注释 |
| A6 | 已修复 | `dropPartition()` 返回 bool，外层只统计成功项 |
| A7 | 已修复 | 配置新鲜度检查改为协程 Context 每请求一次，写操作后主动失效 |
| A8 | 已修复 | `saveDynamic()` 校验「示例配置 + Config.inc.php + 动态增量」的合并结果 |
| A9 | 已修复 | PHPStan 提升到 **level 7**，范围扩到 `app/ scripts/ bin/`，零告警 |
| A10 | 已修复 | `List` / `Count` 共用 `scanEntries()`，消除重复全目录扫描 |
| A11 | 已修复 | 审计查询改为尾部窗口读取 |
| A12 | 已修复 | 删除未实现的 `dedupKey` 选项 |
| A13 | 已修复 | Redis 不可用时 `recordBatch()` 返回 0，不再谎报已处理 |
| A14 | 已修复 | README 补充本地全量测试所需服务与跳过语义 |
| A15 | 已修复 | Admin 示例版本号更新为当前版本 |
| A16 | 已修复 | OpenAPI 补录 `POST /v1/telemetry/report`；根端点清单补 telemetry |

### 额外发现与修复

- **`logs.token` 列宽不足**：`sha256:` 前缀使存储值达到 71 字符，`VARCHAR(64)` 会触发 1406。列宽已改为 `VARCHAR(80)`，并新增幂等迁移脚本 `php scripts/widen_token_column.php`；升级须知写入 `CHANGELOG.md` 与 `README.md`。
- **并发去重的 TOCTOU**：`MariaDbStorage::Put()` 在唯一键冲突时会重新查指纹，命中则按去重返回，避免并发同内容上传报 500。
- **依赖漏洞**：`symfony/http-foundation` 升级到 v7.4.22，`composer audit` 无告警。
- **WAF 内部调用硬编码 Host**：`SecurityService` 的 WAF 快照 / 解封请求原本固定 `Host: api.logshare.cn`，测试部署直接失效；已改为从 `urls.apiBaseUrl` 推导，仍保留公网域名兜底。
- **运行时文件权限**：封禁文件改为临时文件 + rename 原子写并 `chmod 0600`，`mkdir` 收紧到 0750；`runtime/dynamic_config.json` 与 `runtime/logs/audit.log` 同样收紧到 0600。
- **契约与文档漂移清理**：`/errors/rate` 的 OpenAPI / Postman 示例从「返回 200 计数器」改为「始终 429」；Postman 的 16 位 token 示例、占位 Admin Token、36,000 速率描述全部更新；README 的 `/limits` 说明改为「上传限制与存储 TTL」并补 `/errors/rate`。
- **过期生成物与本地残留**：`docker/mariadb-events.sql` 的分区只铺到 2026-10-23（今天 2026-11-01），已按当前 TTL 重新生成（覆盖 2026-10-24 ~ 2026-11-14）；删除根目录废弃的 AI/RAG 计划 `Plan.md`，`docs/PLAN.md` 标注为已完成的历史归档；清理测试产生的 `runtime` 状态、`storage/logs` 当日文件与 `scripts/__pycache__`。

### 最终验证输出

```text
php -l（app/ + scripts/ + bin/ 全部文件）  → 无语法错误
composer stan（level 7）                  → [OK] No errors（76 个文件）
composer validate --strict                → ./composer.json is valid
composer audit --locked                   → No security vulnerability advisories found
pest 全量（本地）                          → 18 skipped, 209 passed (885 assertions)
pest 全量（真实 MariaDB）                  → 4 skipped, 223 passed (975 assertions)
pest --group=architecture                 → 7 passed (72 assertions)
OpenLiteWaf regex / WAF logic / Stats      → 全部通过
openapi.yaml / CI YAML / postman JSON      → 解析通过
真实 MariaDB + Redis 端到端：
  去重同 ID、deduplicated=true
  第二个 token 删除 → 400；原始 token 删除 → 200；删除后 raw → 404
  同名附件 → main.log / main (2).log，上传 200
  库中 token 前缀 → sha256:
  5000 字符遥测路径 → 写入字段被截断到 200 字符以内
```

> 说明：本节记录的是修复后的状态；上方「问题清单」保留原始审查发现与证据，便于追溯。
