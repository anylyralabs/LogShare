# 前端 API 文档待确认问题

> **后端答复（2026-11-02，基于仓库当前 HEAD `b5c76dd` 的代码，不是线上旧部署）**
>
> 线上实例尚未同步到当前代码。以下回答以仓库当前实现和 `Config.inc.example.php` 默认值为准；部署方如果覆盖了 `Config.inc.php`，数值可能变化，但字段结构和行为不变。前端应以本文件答复和实际 `/v1/limits` 为准，不要把线上旧响应当成新契约。

下面这些问题会直接影响站内 API 文档的「上传限制」「响应字段」「续期规则」几节。请后端 agent 按仓库当前代码逐条回答，尽量给出具体数值、单位和触发条件；如果不同环境（开发/测试/生产）配置不同，也请分别说明。

## 后端答复

### 一、`GET /v1/limits` 与限制

1. **当前代码只有四个字段**，`maxLength` / `maxLines` 已不存在：

   ```json
   {
     "storageTime": 604800,
     "maxFiles": 200,
     "maxTotalBytes": 20971520,
     "maxRequestBodyBytes": 33554432
   }
   ```

   线上如果仍返回 `maxLength` / `maxLines`，说明线上还是旧版本，不是当前仓库契约。

2. `maxLength` 在当前代码中**不存在**，没有单位可言；它不再参与上传校验，也不由 `/v1/limits` 返回。

3. `maxLength` 不作用于任何层级。当前实际限制是：
   - `maxTotalBytes`：整条日志所有文件展开后的合计字节数；
   - `maxRequestBodyBytes`：整个 HTTP 请求体；
   - 单个文件没有大小上限。

4. `maxLines` 在当前代码中**不存在**，没有单文件或合计行数上限。`files[].lines` 只是展示值，按 `substr_count(content, "\n") + 1` 计算，空行、堆栈行、ZIP 展开后的行都计入；空文件按 1 行展示。它不参与拒绝上传。

5. 200 个文件、20 MiB、32 MiB 仍然有效，分别对应 `maxFiles`、`maxTotalBytes`、`maxRequestBodyBytes`。`maxLength` / `maxLines` 不是当前限制，客户端应只依赖 `/v1/limits` 返回的四个字段。单文件大小、单文件行数没有额外上限。

6. `storageTime` 默认仍是 604800 秒（7 天）。MongoDB TTL 索引的 `expireAfterSeconds` 是 `storageTime + 120` 秒；`expires` 表示逻辑过期时间（`created + storageTime`），**不是** TTL 实际删除时刻。120 秒宽限和 TTL 监控延迟属于内部回收机制，不应作为对外精确 SLA；对外承诺应表述为“至少保留 7 天”。

### 二、上传字段与旧版 `content`

7. `files` 和 `content` 至少提供一个。两者同时提交时：
   - `content` **一定**作为主文件参与上传；
   - 主文件命名优先 `latest.log`；若与 `files` 重名则用 `main.log`；若仍重名则按 `latest (2).log`、`latest (3).log`……递增；
   - **上传响应**的顶层 `raw` 在 `content + files` 多文件时一定指向这个主文件；
   - **`GET /v1/log/{id}` 元信息响应**的 `raw` 是多文件按名称排序后的第一个文件，当前并不保证是旧版 `content` 主文件。若前端依赖主文件，应使用上传响应的 `raw`，或使用 `files[]` 里对应的文件 URL。

8. 旧版 `content` 映射出的主文件**占用** `maxFiles` 配额：`content + 199 个 files` 合计 200 个文件；`content + 200 个 files` 返回 413。它也计入 `maxTotalBytes` 的 20 MiB 合计（`Log::put` 在过滤/反混淆后统计），但不存在 `maxLength` / `maxLines` 限制。

9. 只提交 `content` 时，上传响应仍包含 `raw`、`files`、`fileCount`、`requiresFileSelection`：`fileCount=1`、`requiresFileSelection=false`，`raw` 指向 `/v1/raw/{id}`（不带文件名）。单文件读取仍返回正文。

10. `/1/log` 与 `/v1/log` 使用同一个控制器和同一套解析逻辑，因此也支持 `content` 与 `files`，响应字段一致。站内文档应保留 `/1/` 的兼容说明。

### 三、ZIP 与文件名

11. ZIP 的处理规则：
    - ZIP 内的非目录条目计入 `maxFiles`，目录条目跳过；展开后超过 200 个文件返回 413。
    - `maxTotalBytes` 只统计展开后的条目数据字节数，**不把 ZIP 压缩包自身大小计入合计**；ZIP 原始字节仍受整个请求体 32 MiB 上限约束。
    - 不统计字符数、行数；`maxLength` / `maxLines` 不存在。
    - 展开后的条目合计超过 20 MiB、或声明大小/流式读取超过剩余预算，返回 413。

12. 文件名上限是 **512 字节**（`strlen`，不是字符数）。普通文件和 ZIP 条目都适用。超长直接返回 400（`Invalid file name`），不自动截断。同名文件会在不超长前提下自动改名为 `name (2).ext` 等；若加后缀后超长，才截断基名以生成唯一名称。

13. 子路径保留；文件名中的 `\` 在路径遍历校验时会按 `/` 检查，但存储名称不会自动改写，建议客户端统一使用 `/`。非 ASCII/UTF-8 文件名允许，但必须是合法 UTF-8；`raw` / `insights` URL 需要按路径段做 URL 编码（响应里的 `raw`、`insights` 已经编码）。服务端读取时会对路由参数做 `rawurldecode`，然后按存储名称精确匹配。

### 四、请求体与压缩

14. `maxRequestBodyBytes` 默认 32 MiB（33554432 字节），限制的是**解压后的请求体**；同时 `ContentParser` 也会先检查原始请求体不超过同一上限。多层 `Content-Encoding` 会逐层解压，每一层输出都必须不超过 32 MiB，不是各层累计相加。`RequestParser` 的解压保护上限也取 `min(32 MiB, 配置值)`。

15. `gzip`、`x-gzip`、`deflate` 由 PHP 内置 zlib 支持；`br` 需要 `ext-brotli`。缺少 Brotli 扩展时 `br` 返回 501；未知/不支持的 `Content-Encoding` 返回 415；编码损坏返回 400。

16. 状态码：
    - nginx `client_max_body_size 210m` 超限：413；
    - 应用层 `maxRequestBodyBytes` 32 MiB 超限：413；
    - 应用层 `maxTotalBytes` 20 MiB 超限：413；
    - WAF CC 超限（10 秒窗口 240 次）：429，带 `Retry-After`，不封 IP；
    - nginx `limit_req`（30 r/s，burst 60）：503。

    客户端应以应用层限制为准：整条日志 20 MiB、请求体 32 MiB；同时必须处理边缘返回的 413/429/503，429 按 `Retry-After` 退避，503 也应退避重试。

17. `Content-Type` 缺失或不是 `application/json` / `application/x-www-form-urlencoded` 时，当前 `ContentParser` 的 `default` 分支仍按 urlencoded 解析，不返回 415。`application/json` 解析失败返回 400；`Content-Encoding` 不支持才返回 415。

### 五、响应字段与续期

18. 顶层 `raw` 的当前语义：
    - 单文件（包括只传 `content`）：`.../raw/{id}`；
    - 上传响应且带 `content + files` 多文件：`.../raw/{id}/{主文件名}`，主文件名是 `latest.log` / `main.log` / `latest (2).log` 之一；
    - 仅 `files` 的多文件上传响应：按文件名称排序后的第一个文件的 `.../raw/{id}/{filename}`；
    - `GET /v1/log/{id}` 的多文件响应：同样按名称排序后的第一个文件，**不保证是旧版 `content` 主文件**。

    前端如果必须拿主文件，请使用上传响应的 `raw`，不要从 `GET /v1/log/{id}` 重新推导。

19. 多文件列表请求**会续期**：
    - `GET /v1/raw/{id}` 在单文件/多文件分支之前调用 `renew()`；
    - `GET /v1/insights/{id}` 在加载日志时调用 `renew()`；
    - 带文件名的 `raw` / `insights` 也会续期；
    - `GET /v1/log/{id}` 不调用 `renew()`，明确不续期。

    因此站内文档“只有读取原文或分析结果才续期”不准确，应改为“raw / insights 的任意成功读取（包括多文件列表）都续期；log 元信息不续期”。

20. `expires` 是逻辑过期时间，值为 `created + storageTime`；MongoDB 的实际删除时间不早于 `expires + 120s`，还要加上 TTL 监控轮询延迟（约 60 秒级）。面向客户端的文档应说明“到期后可能短暂延迟删除”，不要把 `expires` 写成精确删除时刻。

### 六、metadata 与 source

21. `key`、`value`、`label` 超长时都是**截断**，不返回 400：
    - `key`：trim 后最多 64 个字符；空 key 的条目被丢弃；
    - `label`：最多 128 个字符；
    - `value`：字符串最多 1024 个字符；数组/对象先 JSON 编码，再按 1024 个字符截断。

22. 当前实际返回类型：
    - `null`：`value` 为 null 的条目在解析阶段就会因 `isValid()` 失败被丢弃，公开接口不会返回 `value: null` 的条目；
    - 布尔、整数、浮点数：按原始 JSON 标量返回；
    - 字符串：按字符串返回（超长已截断）；
    - 数组、对象：在 `MetadataEntry::setValue()` 中先 `json_encode` 成字符串，MongoDB 读取端当前**不会**再解码，所以公开元信息接口返回的是 JSON 字符串，而不是原数组/对象。

    这与当前 `openapi.yaml` 里 `value` 允许 array/object 的定义不一致；前端如果直接按 openapi 解构数组/对象会踩坑。若需要保持数组/对象原类型，需要后端补一次 JSON 解码或改存储格式，属于已知契约缺口，见第 25 条。

23. 行为仍然是：
    - 重复 `key` 保留首次出现的条目；
    - `visible=false` 的条目不会出现在 `GET /v1/log/{id}` 的公开 `metadata` 中；
    - 后端从 Fabric/其它日志识别出的 `version` / `loader` 只作为内部统计字段保存（`visible=false`），不会回填到公开 metadata；客户端显式提交的同名条目仍按客户端 `visible` 值返回；
    - 超过 100 条时只保留前 100 条有效条目，后续丢弃。

24. `source`：
    - 显式提供时先去首尾空白；`ContentParser` 按字符截断到 64，`Log::put` 再按 UTF-8 边界截断到 64 字节，因此实际存储上限是 64 字节；
    - 省略时从 `User-Agent` 推断，格式必须严格为“名称/版本号”（名称允许 Unicode 字母/数字/下划线/短横线，版本允许数字/点/下划线/加号/短横线，只有一个斜杠，长度不超过 64 字节）；浏览器、curl、Postman、Java、okhttp 等常见 UA 在黑名单中，不识别；
    - 无法识别或为空时固定为 `未指定`。该行为建议写入文档。

### 七、文档同步

25. 当前分支的 `API.md`、`openapi.yaml` 已经覆盖：
    - `/v1/limits` 四个字段；
    - 旧版 `content` + `files`；
    - 上传响应顶层 `raw`；
    - `maxFiles` / `maxTotalBytes` / `maxRequestBodyBytes`。

    仍有明确缺口，前端不要按旧描述实现：
    - `API.md` / `openapi.yaml` 对 metadata 复合值仍描述为 array/object，当前接口实际返回 JSON 字符串；
    - `API.md` 的续期描述仍容易让人理解为“只有正文/分析续期”，实际列表请求也续期；
    - `openapi.yaml` 的 `GET /v1/log/{id}` 多文件 `raw` 语义未标明“按名称排序的第一个文件”，与旧版 `content` 主文件不是同一概念；
    - `postman_collection.json` 目前只覆盖新 `files` 契约，没有旧版 `content` 示例。

26. 站内文档应以 `/v1/limits` 的四个字段为主：
    - 文件数 ≤ 200；
    - 展开后合计 ≤ 20 MiB；
    - 请求体（解压后）≤ 32 MiB；
    - 单文件大小/行数不设限；
    - `maxLength` / `maxLines` 不要写进新契约。

    如果旧线上接口仍返回 `maxLength` / `maxLines`，那是旧部署；在部署同步前不要把它们和新四字段混写。客户端应优先按本地 `/v1/limits` 和应用层 20 MiB/32 MiB 限制预校验，同时处理边界的 413、429、503。

27. 以上没有需要保留的“待定”项；唯一取决于部署配置的是 `/v1/limits` 的实际数值（由 `Config.inc.php` 覆盖）。本文回答的是当前仓库代码和默认配置，不是线上旧实例。

## 一、`GET /v1/limits` 的当前契约

1. 线上 `GET /v1/limits` 目前返回：

   ```json
   {
     "storageTime": 604800,
     "maxLength": 10240000,
     "maxLines": 20000
   }
   ```

   但 `API.md`、`openapi.yaml` 和 `Config.inc.php` 里仍然是：

   ```json
   {
     "storageTime": 604800,
     "maxFiles": 200,
     "maxTotalBytes": 20971520,
     "maxRequestBodyBytes": 33554432
   }
   ```

   哪一组才是当前正式契约？`/v1/limits` 是否已经改版，还是线上部署与仓库配置不一致？

2. `maxLength` 的单位是什么？字符数、字节数，还是解压后的总大小？

3. `maxLength` 作用于哪个层级？
   - 单个文件
   - 整条日志的所有文件合计
   - 整个请求体
   - 旧版 `content` 字段

4. `maxLines` 作用于哪个层级？
   - 单个文件
   - 整条日志的所有文件合计
   - 是否包含空行、堆栈行、ZIP 展开后的行

5. 200 个文件、20 MiB、32 MiB 这三个限制现在是否仍然有效？
   - 如果仍然有效，为什么 `/v1/limits` 不返回？
   - 如果已经废弃，客户端应该只依赖 `maxLength` / `maxLines` 吗？
   - 单文件大小、单文件行数是否另有上限？

6. `storageTime` 是否仍是 7 天？MongoDB TTL 的 `storageTime + 120s` 宽限是否属于对外承诺，还是只作为内部回收机制？

## 二、上传字段与旧版 `content`

7. `files` 和 `content` 至少提供一个的规则是否仍然成立？两者同时提交时：
   - `content` 是否一定作为主文件？
   - 主文件命名是否依次为 `latest.log`、`main.log`、`latest (2).log`？
   - 响应顶层的 `raw` 是否一定指向这个主文件？

8. 旧版 `content` 映射出的 `latest.log` 是否占用 200 个文件配额？是否受 `maxLength` / `maxLines` 限制？

9. 只提交 `content` 的旧客户端，上传响应是否也包含顶层 `raw`、`files`、`fileCount`、`requiresFileSelection`？`raw` 指向 `/v1/raw/{id}` 还是 `/v1/raw/{id}/latest.log`？

10. 旧版 `/1/log` 是否也支持 `content` 和 `files`？站内文档是否还需要保留 `/1/` 的兼容说明？

## 三、ZIP 与文件名

11. ZIP 展开后的文件数、总大小、总字符数、总行数分别如何计算？
    - 是否受 `maxFiles` / `maxLength` / `maxLines` 限制？
    - 压缩包本身的大小是否也计入总大小？

12. 文件名最大长度是多少？代码里看到 512 字节的限制，这个值是否对客户端公开？超长文件名是返回 400，还是自动截断？

13. 文件名中的子路径、非 ASCII 字符、URL 编码规则是否有变化？

## 四、请求体与压缩

14. `maxRequestBodyBytes` 限制的是压缩前的请求体，还是解压后的请求体？多层 `Content-Encoding` 时如何累计？

15. `br`、`gzip`、`x-gzip`、`deflate` 在生产环境是否都可用？如果服务端没有安装 Brotli 扩展，返回 501 还是 415？

16. 请求体超过 nginx `client_max_body_size 210m`、应用层 `maxRequestBodyBytes`、WAF CC 限制时，分别返回什么状态码？客户端应以哪个为准？

17. `Content-Type` 缺失或不是 `application/json` / `application/x-www-form-urlencoded` 时，服务端是否仍按 urlencoded 解析？还是返回 415？

## 五、响应字段与续期

18. 上传响应和 `GET /v1/log/{id}` 响应中的顶层 `raw` 字段，确切语义是什么？
    - 单文件：`/v1/raw/{id}`
    - 多文件：主文件的 `/v1/raw/{id}/{filename}`
    - 同时提交 `content` + `files`：是否指向 `content` 映射出的主文件？

19. 多文件时 `GET /v1/raw/{id}` 和 `GET /v1/insights/{id}` 返回文件列表，这两个请求是否续期？
    - 代码里 `RawController` 和 `InsightsController` 在返回文件列表前调用了 `renew()`，但站内文档目前写的是「读取原文或分析结果才会续期」。
    - `GET /v1/log/{id}` 是否明确不续期？

20. `expires` 表示逻辑过期时间还是 MongoDB TTL 实际删除时间？TTL 宽限期是否需要写进面向客户端的文档？

## 六、metadata 与 source

21. `metadata` 的 `key` / `value` / `label` 超过长度限制时，是截断还是返回 400？

22. `value` 为 `null`、数组、对象、布尔值时，公开接口返回的 JSON 类型是什么？文档应该写「原样返回」还是「JSON 序列化后的字符串」？

23. 重复 `key`、`visible=false`、超过 100 条时的行为是否仍是：
    - 重复 key 保留首次出现
    - `visible=false` 不出现在公开元信息
    - 超过 100 条直接截断

24. `source` 省略时，服务端从 `User-Agent` 推断启动器来源的规则是什么？无法识别时是否固定返回「未指定」？这个行为是否需要写进文档？

## 七、文档同步

25. `API.md` 和 `openapi.yaml` 目前是否已经同步到最新线上契约？如果没有，请给出最终字段定义或需要修改的段落。

26. 站内文档的「上传限制」应该以 `/v1/limits` 返回的 `maxLength` / `maxLines` 为主，还是继续写 200 文件 / 20 MiB / 32 MiB？如果两套限制同时存在，请给出优先级和适用范围。

27. 以上任何一条如果暂时无法确认，请明确标注「待定」，不要用「以代码为准」代替具体契约，前端需要能直接写进文档的答案。
