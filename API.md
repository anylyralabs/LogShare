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
    "token": "3f9c1a7d5e2b8046c1d9f3a70b5e28461c7a9d0e3f5b8124c6d7e9f0a1b2c3d4",
    "deduplicated": false
}
```

> `/v1/log` 返回格式相同，`raw` URL 指向 `/v1/raw/`。

`token` 是删除该日志的唯一凭证，请自行保存。

> **去重命中时的 token 语义**：命中已有指纹时响应会带 `deduplicated: true`，返回的 `token` 是本次请求新生成的，并未写入存储，因此**不能用于删除该日志**（删除凭证仍属于首次上传者）。服务端也不会用该 token 覆盖缓存，首次上传者的凭证始终有效。

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

### 上传限制

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

> **限流口径：** 限流在边缘完成：nginx `limit_req` 按 IP 30 r/s（burst 60），OpenLiteWaf 再叠加 10 秒窗口 240 次的 CC 限流。CC 超限返回 `429` 并带 `Retry-After`，`limit_req` 超限返回 `503`；两者都不封禁 IP（特征命中累计 3 次才会临时封禁）。应用层 `RateLimitMiddleware` 目前未注册，不参与实际限速。

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
