# LogShare API

基础 URL：由配置 `urls.apiBaseUrl` 决定。

同时提供 `/1/`（已弃用）和 `/v1/` 端点。建议新集成使用 `/v1/`。

完整 OpenAPI 3.1 规范见 [`openapi.yaml`](openapi.yaml)。

---

## 核心模型

一条日志记录不再区分“正文”和“附件”，而是由一个 ID 下的若干文件组成：

- 新客户端上传时只提交 `files`；旧版 `content` 字段仍然兼容，会映射为 `latest.log`，已有客户端不需要迁移。
- 单个文件大小不设上限；全部文件展开后合计不超过 20 MiB。
- 上传阶段逐文件脱敏，并在识别为 Minecraft 日志时执行 SpinYarn 反混淆。
- 全文 Codex 分析不在上传时执行，只在请求某个文件的分析接口时执行。
- 多文件日志直接访问 `raw/{id}` 或 `insights/{id}` 会返回 `200` + 文件列表；客户端选择文件后再带文件名请求。

---

## 快速接入

```bash
# 1. 上传文件列表
curl -X POST https://api.logshare.cn/v1/log \
  -H 'Content-Type: application/json' \
  -d '{
    "files": [
      {"name": "latest.log", "content": "<日志全文>"},
      {"name": "crash-reports/crash-01.txt", "content": "<崩溃报告>"}
    ],
    "source": "your-launcher/1.0.0"
  }'

# 2. 列出文件
curl https://api.logshare.cn/v1/log/sAbCdEf

# 3. 多文件时先选择文件，再读取或分析
curl https://api.logshare.cn/v1/raw/sAbCdEf/latest.log
curl https://api.logshare.cn/v1/insights/sAbCdEf/latest.log
```

上传响应的 `token` 是删除日志的唯一凭证，丢失后无法找回，请自行持久化保存。

---

## 客户端接入规范

1. **一次上传完整现场**：建议同时提交游戏主日志、崩溃报告与启动器日志，通过 `files` 数组或 ZIP 压缩包提交。
2. **多文件先让用户选择**：调用 `GET /v1/log/{id}`，若 `requiresFileSelection` 为 `true`，展示 `files` 列表让用户选择，再使用所选文件的 `name` 请求 raw/insights。
3. **注明来源**：`source` 推荐格式为 `启动器标识/版本号`，例如 `fcl/1.2.0`、`pojavlauncher/3.4.1`。
4. **保留删除凭证与分享链接**：持久化 `token`，并提供 `url` 的一键复制或外跳。

---

## 上传日志

```
POST /1/log   （已弃用）
POST /v1/log
```

**Content-Type：** `application/x-www-form-urlencoded` 或 `application/json`。
**Content-Encoding：** 支持 `br`、`gzip`、`x-gzip`、`deflate`，最多 5 层；解压后请求体上限 32 MiB。
**Accept-Encoding：** 默认采用 `br`，未声明时回退 `gzip`、`deflate`。

**请求字段：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `files` | array | 否* | 文件数组，每个元素 `{name, content}`；文件数 ≤ 200，展开后合计 ≤ 20 MiB。与 `content` 至少提供一个 |
| `content` | string | 否* | 旧版单文件字段；不为空时映射为 `latest.log`，与 `files` 同时存在时作为主文件 |
| `metadata[]` | array | 否 | **分析核心输入**，每项 `{key, value, label?, visible?}`。最多 100 条；`key` 去除首尾空白后必须为 1–64 字符，`value` 字符串最长 1024 字符、其他类型会 JSON 序列化，`label` 最长 128 字符；重复 `key` 以首次出现为准；`visible=false` 不公开。JSON 与 urlencoded 均支持 |
| `source` | string | 否 | 来源标识，最长 64 字符，格式如 `fcl/1.2.0` |

* `files` 与 `content` 至少提供一个；两者同时提供时，`content` 作为主文件 `latest.log`（若重名则使用 `main.log`），其余文件按 `files` 处理。旧客户端可以继续使用 `{"content": "...", "source": "..."}` 上传。

**多文件与 ZIP：**

```json
{
  "files": [
    { "name": "latest.log", "content": "..." },
    { "name": "crash-reports/crash-01.txt", "content": "..." },
    { "name": "server-logs.zip", "content": "<zip 二进制内容>" }
  ]
}
```

- `.zip` 文件会自动展开，保留内部相对路径。
- 展开后每个文件独立经过脱敏过滤链。
- 文件数上限 200；所有文件合计上限 20 MiB；不限制单个文件大小或行数。
- ZIP 条目名会做路径遍历防护（拒绝 `../`、绝对路径）。
- 同名文件会自动改名为 `name (2).ext`、`name (3).ext`。
- 内容去重按「按名称排序的文件集合 + `source`」计算指纹；命中时直接返回既有 ID，`deduplicated: true`。

> **metadata 很重要，不是可选装饰。** 请尽量提供 `version`、`loader`、`launcher`、`modpack`、崩溃上下文等结构化字段；`GET /v1/log/{id}` 只展示客户端提交且 `visible=true` 的条目（`visible=false` 除外）。后端从日志识别出的 `version` / `loader` 仅作为内部统计字段保存，不会回填到公开 metadata。

**响应：**

```json
{
  "success": true,
  "message": "Log submitted successfully",
  "id": "sAbCdEf",
  "url": "https://logshare.cn/sAbCdEf",
  "filesUrl": "https://api.logshare.cn/v1/log/sAbCdEf",
  "raw": "https://api.logshare.cn/v1/raw/sAbCdEf",
  "token": "3f9c1a7d...",
  "deduplicated": false,
  "fileCount": 2,
  "requiresFileSelection": true,
  "files": [
    {
      "name": "crash-reports/crash-01.txt",
      "size": 2048,
      "lines": 80,
      "raw": "https://api.logshare.cn/v1/raw/sAbCdEf/crash-reports/crash-01.txt",
      "insights": "https://api.logshare.cn/v1/insights/sAbCdEf/crash-reports/crash-01.txt"
    }
  ]
}
```

> **去重命中时的 token 语义**：命中已有指纹时返回的 `token` 是本次请求新生成的，并未写入存储，不能用于删除该日志；删除凭证仍属于首次上传者。

---

## 删除日志

```
DELETE /1/log/{id}   （已弃用）
DELETE /v1/log/{id}
```

**鉴权：** `Authorization: Bearer <token>`。
**多 ID：** 逗号分隔，如 `DELETE /v1/log/id1,id2`。

---

## 文件列表

```
GET /1/log/{id}   （已弃用）
GET /v1/log/{id}
```

返回日志元信息与全部文件。多文件时 `requiresFileSelection` 为 `true`：

```json
{
  "success": true,
  "message": "Log metadata retrieved successfully",
  "id": "sAbCdEf",
  "fileCount": 2,
  "requiresFileSelection": true,
  "size": 4096,
  "lines": 120,
  "created": 1755200000,
  "expires": 1755804800,
  "metadata": [],
  "source": "launcher/1.0.0",
  "filesUrl": "https://api.logshare.cn/v1/log/sAbCdEf",
  "files": [
    {
      "name": "latest.log",
      "size": 2048,
      "lines": 40,
      "raw": "https://api.logshare.cn/v1/raw/sAbCdEf/latest.log",
      "insights": "https://api.logshare.cn/v1/insights/sAbCdEf/latest.log"
    }
  ]
}
```

---

## 读取文件

```
GET /1/raw/{id}   （已弃用）
GET /v1/raw/{id}

GET /1/raw/{id}/{filename}   （已弃用）
GET /v1/raw/{id}/{filename}
```

- 只有一个文件时，`GET /v1/raw/{id}` 直接返回 `text/plain` 内容。
- 多个文件时，`GET /v1/raw/{id}` 返回 `200` + 文件列表（结构同文件列表接口），不会擅自选择文件。
- 指定文件名后返回该文件原文，例如：

```
GET /v1/raw/sAbCdEf/crash-reports/crash-01.txt
```

`{filename}` 支持子路径，需 URL 编码。文件不存在返回 404。文件名只做数据库精确匹配，不做文件系统路径拼接。

---

## 分析文件

```
GET /1/insights/{id}   （已弃用）
GET /v1/insights/{id}

GET /1/insights/{id}/{filename}   （已弃用）
GET /v1/insights/{id}/{filename}
```

- 只有一个文件时，`GET /v1/insights/{id}` 直接返回 Codex 分析 JSON。
- 多个文件时，返回 `200` + 文件列表；客户端选择文件后请求：

```
GET /v1/insights/sAbCdEf/latest.log
```

返回 Codex 解析结果，包含类型、版本、错误信息与堆栈等：

```json
{
  "id": "vanilla/server",
  "name": "Vanilla",
  "type": "Server Log",
  "version": "1.21.9",
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

分析结果按文件内容哈希做进程内 LRU 缓存；同一文件重复访问不会重复执行 Codex。

---

## 上传限制

```
GET /1/limits   （已弃用）
GET /v1/limits
```

```json
{
  "storageTime": 604800,
  "maxFiles": 200,
  "maxTotalBytes": 20971520,
  "maxRequestBodyBytes": 33554432
}
```

> **限流口径：** 限流在边缘完成：nginx `limit_req` 按 IP 30 r/s（burst 60），OpenLiteWaf 再叠加 10 秒窗口 240 次的 CC 限流。CC 超限返回 `429` 并带 `Retry-After`，`limit_req` 超限返回 `503`；两者都不封禁 IP（特征命中累计 3 次才会临时封禁）。应用层不再执行内容审核，边缘 WAF 仍负责基础探测拦截。

---

## 根端点

```
GET /
```

返回全部可用端点列表。

---

## 通用响应格式

**成功：**

```json
{
  "success": true,
  "message": "OK"
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
| 200 | 请求成功；多文件列表也返回 200 |
| 400 | 请求参数错误 |
| 401 | 缺少认证信息 |
| 403 | 权限不足或 IP 被封禁 |
| 404 | 日志或文件不存在 |
| 405 | 请求方法不允许 |
| 413 | 请求体或文件总量过大 |
| 415 | 不支持的 Content-Type 或 Content-Encoding |
| 429 | 速率限制触发 |
| 500 | 服务器内部错误 |
