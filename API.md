# LogShare API

基础 URL：由配置 `urls.apiBaseUrl` 决定。

同时提供 `/1/`（已弃用）和 `/v1/` 端点。建议新集成使用 `/v1/`。旧版 `/1/` 在过渡期内保持兼容。

完整 OpenAPI 3.1 规范见 [`openapi.yaml`](openapi.yaml)。

---

## 快速接入

接入「日志上传 + 结构化解析」的最小流程：

```bash
# 1. 上传日志（source 填启动器名/版本，用于匹配对应生态的知识库）
curl -X POST https://api.logshare.cn/v1/log \
     -H 'Content-Type: application/json' \
     -d '{"content":"<日志全文>","source":"your-launcher/1.0.0"}'
# → {"success":true,"id":"sAbCdEf","token":"...","raw":"...","url":"..."}

# 2. 获取原始日志或结构化解析（可选）
curl https://api.logshare.cn/v1/raw/sAbCdEf          # 日志原文
curl https://api.logshare.cn/v1/insights/sAbCdEf     # Codex 结构化分析
```

接入时需要注意以下几点：

- 上传响应中的 `token` 是删除日志的唯一凭证，丢失后无法找回，请自行持久化保存。
- `source` 字段建议填写启动器名与版本（如 `fcl/1.2.0`），便于日志来源统计与后续外部诊断服务关联。
- 全链路默认采用 Brotli（`br`）压缩：移动端与各客户端默认推荐开启 Brotli 压缩上传（`Content-Encoding: br`，亦向下兼容 gzip）；服务端出站响应全链路默认采用 Brotli（`br`）压缩返回。
- AI 智能诊断已由外部服务提供，本仓库不再暴露 `/v1/ai/*` 与 `/rag` 端点。

---

## 客户端接入规范

为便于日志分享、结构化解析与后续外部诊断服务定位问题，启动器或客户端接入时应遵循以下规范：

1. **尽可能完整上传「游戏主日志 + 崩溃报告 + 启动器日志」**：
   - 游戏闪退或报错往往源于 Java 虚拟机配置、移动端渲染器组件（如 Zink / Turnip / Holy GL4ES）、本地原生动态库或启动参数异常，单传游戏日志易产生诊断盲区。
   - 接入时**尽可能同时上传三类关键内容**：
     - **游戏主日志**（如 `latest.log`）；
     - **崩溃报告**（如 `crash-reports/crash-*.txt`）；
     - **启动器运行日志**（如 `launcher.log`、控制台输出或错误堆栈）。
   - 建议通过 `files` 数组或 ZIP 压缩包一次性提交，服务端会自动保留相对路径并构建完整的多文件预览面板。
2. **上传时务必注明来源标识（`source` 字段）**：
   - 上传请求体中须尽量携带 `source` 参数，推荐格式为 `启动器标识/版本号`（例如 `pojav-glow-worm/3.4.0`、`fcl/1.2.0`、`zl2/2.1.0`、`amcl/1.0.0` 等）。
   - 注明来源标识有助于按启动器生态统计与分发日志。
3. **保留删除凭证与分享直达**：
   - 上传成功后持久化保存返回的 `token`，并在客户端界面提供分享链接（`url`）的一键复制或外跳功能。

---

## 日志管理

### 上传日志

```
POST /1/log   （已弃用，保留兼容）
POST /v1/log
```

**Content-Type：** `application/x-www-form-urlencoded` 或 `application/json`。  
**Content-Encoding：** 默认推荐 `br`（Brotli，极限压缩比），向下兼容 `gzip`、`x-gzip`、`deflate`（可叠加，最多 5 层，服务端设 20 MiB 解压安全上限）。
**Accept-Encoding：** 默认采用 `br`（Brotli）全链路压缩返回（≥1024 字节），未声明时回退 `gzip`、`deflate`。

**请求字段（JSON）：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `content` | string | 是* | 日志内容（多文件上传时可为空，见下） |
| `files` | array | 否 | 附加文件数组，每个元素 `{name, content}` |
| `metadata[]` | array | 否 | 元数据，每项 `{key, value, label?, visible?}`；`value` 为字符串时直接存储，其他类型会 JSON 序列化；单项最长 value 1024 / label 128 / key 64 字符。服务端会在落库前用日志识别结果自动补写 `version`（Minecraft 版本）与 `loader`（fabric / neoforge / forge / vanilla 等）两项元数据，客户端已提交同名键时以客户端值为准，不受影响；补写项的 `label` 固定为 `Minecraft 版本` 与 `模组加载器`，`visible` 为默认 true |
| `source` | string | 否 | 来源标识（最长 64 字符），建议填写，格式如 `fcl/1.2.0`、`pojavlauncher/3.4.1`。该字段用于按启动器生态统计与分发日志 |

\* 当提供 `files` 时 `content` 可省略，主文件取 `files[0]`。

**多文件上传：**

每个 `files[].content` 可为纯文本，也可为 ZIP 压缩包（`name` 以 `.zip` 结尾时自动展开为多个文件，保留内部相对路径）：

```json
{
    "content": "主文件内容（可选）",
    "files": [
        { "name": "crash-reports/crash-01.txt", "content": "---- Minecraft Crash Report ----\n..." },
        { "name": "server-logs.zip", "content": "<zip 二进制内容>" }
    ]
}
```

- 展开后每个文件独立经过脱敏过滤链
- 上限：文件数 ≤ 200，解压后累计 ≤ 12MB（`storage.uploadFiles`）
- ZIP 条目名会做路径遍历防护（拒绝 `../`、绝对路径）
- **极速解耦与异步事件流**：上传时系统仅执行轻量级 Codex 特征探测并即刻持久化入库，毫秒级直接响应结果；随后派发 `log.uploaded` 事件，由后台常驻队列 Worker 异步执行内容敏感规则审核与 SpinYarn 堆栈反混淆。若异步审核命中违规规则，该日志将被物理清除并自动封禁非私网来源 IP。
- **内容去重**：服务端按「正文 + 附加文件（按文件名排序，顺序无关）+ `source`」计算内容指纹。指纹命中既有日志时**不写入新行，直接返回既有日志的 ID**，响应结构与首次上传完全相同。这可避免客户端重试、重复提交造成的存储膨胀。

**响应：**

```json
{
    "success": true,
    "message": "Log submitted successfully",
    "id": "sAbCdEf",
    "url": "https://logshare.cn/sAbCdEf",
    "raw": "https://api.logshare.cn/1/raw/sAbCdEf",
    "token": "f3a2b1c4d5e6..."
}
```

> `/v1/log` 返回格式相同，`raw` URL 指向 `/v1/raw/`。

`token` 是删除该日志的唯一凭证，请自行保存。

> **去重命中时的 token 语义**：命中已有指纹时返回的 `token` 是本次请求新生成的，它并未写入存储，因此**不能用于删除该日志**（删除凭证仍属于首次上传者）。若你需要删除能力，请使用首次上传时保存的 token。

---

### 删除日志

```
DELETE /1/log/{id}   （已弃用，保留兼容）
DELETE /v1/log/{id}
```

**鉴权：** `Authorization: Bearer <token>`（token 来自上传响应）。  
**多 ID：** 逗号分隔，如 `DELETE /v1/log/id1,id2`。

**响应：**

```json
{
    "success": true,
    "message": "Log deletion completed",
    "deleted": ["sAbCdEf"],
    "failed": [],
    "total": 1,
    "deletedCount": 1,
    "failedCount": 0
}
```

失败时 `failed` 数组包含每个失败的 ID、原因和 HTTP 状态码。

---

## 日志获取

### 获取原始日志

```
GET /1/raw/{id}   （已弃用，保留兼容）
GET /v1/raw/{id}
```

返回 `Content-Type: text/plain; charset=utf-8`，直接输出日志原文（多文件日志返回主文件）。

### 获取附加文件

```
GET /1/raw/{id}/{filename}   （已弃用，保留兼容）
GET /v1/raw/{id}/{filename}
```

`{filename}` 支持子路径，需 URL 编码：

```
GET /v1/raw/sAbCdEf/crash-reports/crash-01.txt
```

返回该文件原文（`text/plain`）。文件不存在返回 404。路径会做遍历防护（拒绝 `../`、绝对路径）。

### 获取日志元信息与文件列表

```
GET /1/log/{id}   （已弃用，保留兼容）
GET /v1/log/{id}
```

返回日志元信息及附加文件列表（不含内容）：

```json
{
    "success": true,
    "message": "Log metadata retrieved successfully",
    "id": "sAbCdEf",
    "size": 4096,
    "lines": 120,
    "created": 1755200000,
    "expires": 1755804800,
    "metadata": [],
    "source": "minecraft-server",
    "files": [
        { "name": "crash-reports/crash-01.txt", "size": 2048 }
    ],
    "raw": "https://api.logshare.cn/v1/raw/sAbCdEf"
}
```

---

### 获取分析结果

```
GET /1/insights/{id}   （已弃用，保留兼容）
GET /v1/insights/{id}
```

返回 Codex 解析引擎的结构化分析结果，包含服务端类型、版本、错误信息、堆栈跟踪等：

```json
{
    "id": "vanilla/server",
    "name": "Vanilla",
    "type": "Server Log",
    "version": "1.21.9",
    "title": "Vanilla 1.21.9 Server Log",
    "analysis": {
        "problems": [
            { "message": "...", "counter": 1, "solutions": ["..."] }
        ],
        "information": [
            { "message": "Minecraft version: 1.21.9", "counter": 1, "label": "Minecraft version", "value": "1.21.9" }
        ]
    }
}
```

> `analysis.problems` 为诊断出的问题（含解决方案建议），`analysis.information` 为提取的信息（版本、类型等，含 `label`/`value`）。响应不包含 `entries`（原始日志行），内容紧凑。

---

## 分析

### 直接分析日志内容

```
POST /1/analyse   （已弃用，保留兼容）
POST /v1/analyse
```

与 `GET /insights/{id}` 类似，但直接从请求体取内容而非读取已存储日志。请求格式同 `POST /log`。返回 Codex 结构化分析结果（结构同上「获取分析结果」）。

---

## 信息查询

### 根端点

```
GET /
```

返回全部可用端点的列表：

```json
{
    "success": true,
    "message": "LogShare API",
    "endpoints": ["POST /v1/log", "GET /v1/raw/{id}", "..."]
}
```

### 速率限制

```
GET /1/limits   （已弃用，保留兼容）
GET /v1/limits
```

**响应：**

```json
{
    "maxLength": 10485760,
    "maxLines": 50000,
    "storageTime": 604800
}
```

> **限流口径：** 全局限流按 `IP + method + 归一化路径` 计数（动态资源段如 `/v1/raw/{id}` 共享同一桶），默认每 IP 每方法每路径 36,000 次/60 秒，触发返回 HTTP 429。正常集成远达不到该阈值；若你的应用有高并发拉取需求请联系部署方调整。

### 过滤器列表

```
GET /1/filters   （已弃用，保留兼容）
GET /v1/filters
```

过滤器清单由 `filter.pre` 配置动态生成，始终与实际上链路一致；以下为默认配置的完整输出。

**响应：**

```json
{
    "success": true,
    "filters": [
        { "type": "trim", "data": null },
        { "type": "limit-bytes", "data": { "limit": 10485760 } },
        { "type": "limit-lines", "data": { "limit": 50000 } },
        {
            "type": "regex",
            "data": {
                "patterns": [
                    { "pattern": "IPv4", "replacement": "**.**.**.**" },
                    { "pattern": "IPv6", "replacement": "****:****:****:****:****:****:****:****" },
                    { "pattern": "IPv6Short", "replacement": "****:****:****:****:****:****:****:****" },
                    { "pattern": "Uuid", "replacement": "********-****-****-****-************" },
                    { "pattern": "Xuid", "replacement": "xuid:\"****************\"" },
                    { "pattern": "SessionToken", "replacement": "accessToken:\"********\"" },
                    { "pattern": "ClientId", "replacement": "clientId:\"********\"" },
                    { "pattern": "Coordinate", "replacement": "BlockPos(*****, *****, *****)" },
                    { "pattern": "Username", "replacement": "C:\\Users\\********\\" },
                    { "pattern": "AccessToken", "replacement": "accessToken:\"********\"" }
                ]
            }
        }
    ]
}
```

### 速率错误测试

```
GET /1/errors/rate   （已弃用，保留兼容）
GET /v1/errors/rate
```

始终返回 HTTP 429，用于测试限速错误处理。

---

## 管理后台接口（Admin API）

所有管理后台端点均位于 `/{version}/admin/*`（推荐 `/v1/admin/*`），受 `admin.enabled` 开关保护（未启用时返回 404）。  
请求必须在 Header 中携带管理员鉴权令牌：
- `Authorization: Bearer <ADMIN_TOKEN>`
- 或 `X-Admin-Token: <ADMIN_TOKEN>`

若令牌缺失或不匹配，返回 401 Unauthorized。

### 1. 日志列表查询

```
GET /v1/admin/logs
```

**Query 参数：**

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `page` | int | 1 | 当前页码（从 1 开始） |
| `limit` | int | 20 | 每页条数（1–100） |
| `source` | string | - | 按上传来源标识精准过滤（如 `fcl/1.2.0`） |
| `since` | int | - | 按创建时间过滤（≥ since 秒级时间戳） |
| `until` | int | - | 按创建时间过滤（≤ until 秒级时间戳） |
| `keyword` | string | - | 模糊检索（支持日志完整 ID、原始 ID 或 source） |

**响应示例：**

```json
{
    "success": true,
    "message": "Logs retrieved successfully",
    "items": [
        {
            "id": "sAbCdEf",
            "size": 40960,
            "source": "fcl/1.2.0",
            "created": 1726200000,
            "filesCount": 2
        }
    ],
    "total": 128,
    "page": 1,
    "limit": 20,
    "totalPages": 7
}
```

### 2. 日志详情查看

```
GET /v1/admin/logs/{id}
```

免用户删除 Token 查看单条日志的完整数据与元数据。

**响应示例：**

```json
{
    "success": true,
    "message": "Log details retrieved successfully",
    "id": "sAbCdEf",
    "size": 40960,
    "lines": 1200,
    "created": 1726200000,
    "expires": 1726804800,
    "source": "fcl/1.2.0",
    "files": [
        {"name": "crash-reports/crash.txt", "size": 1024}
    ],
    "metadata": [],
    "content": "[12:34:56] [Server thread/INFO]: ..."
}
```

### 3. 日志特权强制删除

```
DELETE /v1/admin/logs/{id}
```

管理员下架接口，**无需**普通用户的 deletion token。支持通过逗号分隔批量删除（如 `DELETE /v1/admin/logs/s123,s456`）。删除后主存储数据、附加文件及 Redis 缓存同步清除并写入墓碑标记。

**响应示例：**

```json
{
    "success": true,
    "message": "Log deletion completed",
    "deleted": ["sAbCdEf"],
    "failed": [],
    "total": 1,
    "deletedCount": 1,
    "failedCount": 0
}
```

### 4. 系统统计与状态

```
GET /v1/admin/system/stats
```

查看系统版本、PHP/Swoole 运行时、存储后端与总日志数。

**响应示例：**

```json
{
    "success": true,
    "message": "System statistics retrieved successfully",
    "version": "1.8.4",
    "phpVersion": "8.4.4",
    "swooleVersion": "6.2.0",
    "storageBackend": "s",
    "totalLogs": 2480,
    "storageTime": 604800
}
```

### 5. 获取系统配置（已脱敏）

```
GET /v1/admin/config
```

获取全站当前的完整配置树。为防止敏感凭据泄露，`admin.token`、`storage.mariadb.password`、`cache.redis.password` 统一返回 `******`。

### 6. 更新系统配置（动态热生效）

```
PUT /v1/admin/config
```

更新业务配置并持久化至 `runtime/dynamic_config.json`。修改后立即更新内存单例，无需重启服务即可对新请求热生效。若传入已脱敏的密钥占位符，服务端会自动保留并还原原有的真实密钥，不覆盖。

### 7. 重置系统配置

```
POST /v1/admin/config/reset
```

清空动态配置文件，重新载入基础配置与环境变量。

### 8. 客户端与生态来源分布统计

```
GET /v1/admin/analytics/sources?days=7
```

参数：
- `days`: 查询天数（1-90，默认 7）

响应：
- `days`: 查询天数
- `total`: 该时间段日志总数
- `sources`: 来源生态列表（`source`, `count`, `percentage`）

### 9. Minecraft 版本与加载器矩阵

```
GET /v1/admin/analytics/versions?days=30
```

参数：
- `days`: 采样天数（1-90，默认 30）

响应：
- `versions`: MC 核心版本排行与占比
- `loaders`: Mod/服务端加载器分布排行与占比

### 10. 日志时序走势大盘

```
GET /v1/admin/analytics/trends?days=7
```

参数：
- `days`: 统计天数（1-90，默认 7）

响应：
- `trends`: 按日走势列表（`date`, `count`, `bytes`），固定为最近 `days` 个自然日（起点为最早统计日的本地零点，无数据的日期补 0）
- `bytes`: 按时间窗内取样得到的平均单篇体积估算，非逐条精确求和（避免全表读取大字段）

### 11. 存储健康度与底层资源诊断

```
GET /v1/admin/system/storage-health
```

返回 MariaDB 表数据与索引大小、文件系统磁盘剩余容量、Redis 内存占用与 Key 数量。

MariaDB 部分额外返回分区状态，用于判断磁盘压力来源：

| 字段 | 说明 |
| --- | --- |
| `dataFreeBytes` | 三张表 `DATA_FREE` 之和。未归还空间的绝对值（首要信号）；GB 级且持续增长才需处理 |
| `fragmentationRatio` | `dataFreeBytes / (数据 + 索引)`，仅作同量级下的横向参考。新库数据量小（MB 级）时该比值天然偏高——InnoDB 按 1MB extent 批量预分配，半满 extent 即计入 `DATA_FREE`（实测 86 行零删除即达 0.39），那是预分配不是空洞；数据量上来后自然收敛 |
| `partitions[]` | 各表分区概览：`dayPartitions`（日分区数量）、`oldestBoundary` / `newestBoundary`（覆盖的时间边界，UTC 秒级）、`totalBytes`、`fallbackPartition`（兜底分区 `p_future` 的行数、占用与空闲）、`largestFreePartition`（空闲最多的分区名、行数与空闲字节——空闲集中在活跃写入分区属健康预分配，散落在多个旧分区则是 DELETE 空洞） |

### 12. 手动触发过期日志清理

```
POST /v1/admin/system/cleanup-expired
```

立即执行底层的 `CleanupExpired()`，返回回收条数与耗时。

MariaDB 后端按天级 RANGE 分区存储，清理通过丢弃整日分区完成，磁盘空间会被真正归还操作系统；返回值是丢弃的分区数与兜底按行清理的条数之和（两者语义不同，前者是分区数、后者是行数）。

### 13. Redis 缓存按需清空

```
POST /v1/admin/system/cache/flush
```

请求体：
- `prefix`: 缓存前缀（默认 `log:*`，支持 `ai:*` 或 `all`）

### 14. 按条件批量下架日志

```
POST /v1/admin/logs/batch-delete
```

请求体：
- `source`: 可选来源过滤
- `since`: 可选起始时间戳
- `until`: 可选结束时间戳
- `keyword`: 可选关键词
- `limit`: 单次上限（1-1000，默认 500）

### 15. 封禁 IP 清单查询

```
GET /v1/admin/security/bans
```

返回当前所有处于封禁状态的 IP 地址、解封时间戳、剩余有效期与封禁原因（合并 Redis 活跃键与 OpenLiteWaf 本地持久化）。

### 16. 手动封禁 IP

```
POST /v1/admin/security/ban
```

请求体：
- `ip`: 目标 IPv4 或 IPv6 地址（必填）
- `ttl`: 封禁时长（秒，默认 86400）
- `reason`: 封禁原因备注（可选）

### 17. 手动解除 IP 封禁

```
POST /v1/admin/security/unban
```

请求体：
- `ip`: 目标 IP 地址（必填）

响应 `data`：
- `ip`: 被解封的 IP
- `unbanned`: 应用层封禁（Redis 与本地封禁文件）是否已解除
- `waf_sync`: 边缘 WAF 封禁的同步结果，取值 `ok`（已解除）/ `skipped`（未配置 `OPENLITEWAF_ADMIN_TOKEN`）/ `failed`（调用 Nginx 运维端点失败）/ `invalid`（IP 非法）。WAF 封禁按 IP 生效且随快照持久化，`unbanned: true` 不代表该来源已能正常访问；为 `skipped` 或 `failed` 时边缘仍会在封禁 TTL 内返回 403

### 18. 边缘防御概览与分类拦截统计

```
GET /v1/admin/security/overview
```

返回 OpenLiteWaf 拦截大盘数据，包括累计请求与拦截次数、当前封禁 IP 总量、分类拦截计数（CC、SQL 注入、XSS、目录穿越、RCE/探针等）、来源 IP Top、逐分钟趋势，以及 `ban_reasons`——按「类目#规则序号」（如 `probe#57`、`cc`）归因的生效中封禁，用于判断是否有单条规则在批量误封；旧版 WAF 未上报该字段时为空对象。

### 19. 获取违规内容过滤规则

```
GET /v1/admin/security/content-rules
```

返回当前生效的违规内容过滤开关（`enabled`）、关键词黑名单（`keywords`）和正则表达式规则列表（`patterns`）。

### 20. 更新违规内容过滤规则

```
PUT /v1/admin/security/content-rules
```

请求体：
- `enabled`: 布尔值，是否启用内容过滤
- `keywords`: 字符串数组，违规关键词列表
- `patterns`: 字符串数组，违规正则列表

### 21. 获取 SpinYarn 映射状态与本地映射库清单

```
GET /v1/admin/spinyarn/status
```

返回 SpinYarn PHP 扩展加载状态、版本、本地 `mappings/` 目录路径以及 Yarn 与 Vanilla 映射文件列表与大小统计。

### 22. SpinYarn 在线反混淆测试探针

```
POST /v1/admin/spinyarn/test
```

测试混淆类名与方法的实时反混淆解析能力。

**请求体（JSON）：**
```json
{
    "content": "java.lang.NullPointerException\n\tat net.minecraft.class_310.method_1508",
    "version": "1.20.1",
    "mapping_type": "yarn"
}
```

**响应示例：**
```json
{
    "success": true,
    "message": "SpinYarn deobfuscation test completed",
    "data": {
        "success": true,
        "available": true,
        "changed": true,
        "version": "1.20.1",
        "mappingType": "yarn",
        "durationMs": 4,
        "original": "...",
        "deobfuscated": "..."
    }
}
```

### 23. 分页获取操作审计日志

```
GET /v1/admin/audit/logs?page=1&pageSize=20&action=log.delete&keyword=s123456
```

支持按动作代号（`action`）、关键词（`keyword`）与时间戳范围（`since`/`until`）筛选检索管理员高危运维操作记录（包含删除、封禁、解封、规则修改、队列控制等）。

### 24. 清空操作审计日志

```
DELETE /v1/admin/audit/logs
```

清空 Redis 环形缓冲区及本地审计日志文件。返回清空条数 `{"cleared": N}`。

### 25. 获取统一事件队列状态与监控指标

```
GET /v1/admin/event-queue/stats
```

查看统一日志异步事件队列（EventQueue）的流状态、当前注册的所有监听器列表、排队积压深度（Lag + Pending）、累计吞吐指标与死信条目数。

**响应示例：**

```json
{
    "success": true,
    "message": "Event queue stats retrieved successfully",
    "enabled": true,
    "stream": "events:log:stream",
    "group": "log-event-workers",
    "maxAttempts": 3,
    "backlog": 0,
    "pending": 0,
    "streamLength": 0,
    "entriesRead": 1420,
    "consumers": 2,
    "deadLetters": 0,
    "counters": {
        "dispatched": 1420,
        "processed_success": 1400,
        "processed_blocked": 19,
        "processed_failed": 1
    },
    "registeredEvents": {
        "log.uploaded": [
            { "name": "security_audit", "priority": 100 },
            { "name": "deobfuscate", "priority": 50 }
        ],
        "log.security_audit": [
            { "name": "security_audit", "priority": 100 }
        ],
        "log.deobfuscate": [
            { "name": "deobfuscate", "priority": 100 }
        ]
    }
}
```

指标口径说明：消费者处理完成后立即执行 `XACK` 与 `XDEL`，稳态下 `streamLength` 与 `backlog` 恒为 0，属设计而非故障；累计吞吐看 `entriesRead`（消费组自创建以来的投递条数，需 Redis 7.0+，低版本无该字段时为 0）与 `counters` 各计数。`counters.processed_blocked` 是异步安全审核命中违规并完成日志删除与来源封禁的拦截数，属预期治理结果，与真正的处理失败 `processed_failed` 分列统计。

### 26. 分页获取死信任务列表

```
GET /v1/admin/event-queue/dead?limit=50
```

查看重试上限耗尽或遇到不可恢复异常的死信任务列表，包含原始事件、负载、重试次数及失败异常原因。

**响应示例：**

```json
{
    "success": true,
    "message": "Dead letters retrieved successfully",
    "total": 1,
    "items": [
        {
            "streamId": "1726912345678-0",
            "id": "evt_66edfe123456",
            "event": "log.uploaded",
            "payload": {
                "logId": "sAbCdEf",
                "clientIp": "198.51.100.2"
            },
            "attempts": 3,
            "error": "Connection timed out after 3 retries",
            "failedAt": 1726912345.678
        }
    ]
}
```

### 27. 重放死信任务

```
POST /v1/admin/event-queue/dead/retry
```

将特定死信任务重置尝试次数后重新推入主事件流 `events:log:stream` 进行消费，并从死信流中清除。

**请求参数（JSON）：**

```json
{
    "streamId": "1726912345678-0"
}
```

**响应示例：**

```json
{
    "success": true,
    "message": "Dead letter retried successfully",
    "streamId": "1726912345678-0",
    "retried": true
}
```

### 28. 清空死信队列

```
DELETE /v1/admin/event-queue/dead
```

物理清空死信流 `events:log:dead`。返回 `{"cleared": true}`。

---

## 通用响应格式

**成功：**

```json
{
    "success": true,
    "message": "OK",
    ...
}
```

**错误：**

```json
{
    "success": false,
    "error": "错误描述",
    "code": 400
}
```

## 状态码

| 状态码 | 说明 |
|--------|------|
| 200 | 请求成功 |
| 400 | 请求参数错误 |
| 401 | 缺少或无效的认证信息 |
| 403 | 权限不足（Token 不匹配） |
| 404 | 日志不存在 |
| 405 | 请求方法不允许 |
| 413 | 请求体过大 |
| 415 | 不支持的 Content-Type 或 Content-Encoding |
| 429 | 速率限制触发 |
| 500 | 服务器内部错误 |
