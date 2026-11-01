# PLAN — LogShare 迁移到 Hyperf

> **历史文档**：本文件记录 2026-08 的 Hyperf 迁移计划。其中 Agent / RAG / AI / SSE 相关设计已于 1.8.4 从本仓库移除，相关章节仅作历史参考。
>
> 状态：**已完成**（v1.7.0 完成 Hyperf 3.2 迁移；本文件仅作历史归档）
> 创建：2026-08-19
> 前置：CRCLASH.md 第三轮审查遗留项（死代码清理、版本号、文档漂移）

## 目标

将 LogShare 从「自研 Router + PHP-FPM 短请求」单体迁移到 Hyperf 3.2（Swoole 常驻 + 协程），根治 CRCLASH #1（SpinYarn LRU 缓存无法跨请求复用，每次反混淆 ~110ms），并引入规范的 DI / 注解路由 / 中间件 / 异常处理。

## 已确认决策

| # | 决策点 | 结论 |
|---|---|---|
| 1 | 命名空间 | 全局类统一迁入 `App\` PSR-4 |
| 2 | RAG | 整合进 Hyperf，**保留 MCP JSON-RPC 协议**，由 Hyperf 协程服务承载（8081） |
| 3 | AI/SSE | `hyperf/guzzle` 协程客户端重写 + `Swoole\Response->write()` |
| 4 | 本地运行 | Swoole 6.2.x 官方支持 Termux（6.1.3 changelog「Added Android Platform Support … Termux」；6.2.0 加 PHP 8.5） |
| 5 | 迁移载体 | 当前仓库新建分支 `feat/hyperf-migration` 原地改造 |
| 6 | 双前缀 | 保留 `/1/` 与 `/v1/` |
| 7 | 主存储 | **MariaDB**（替代 MongoDB），用 `hyperf/database`（Eloquent）+ `hyperf/db-connection`（连接池）；Redis 缓存与 Filesystem 存储不变 |
| 8 | 存储 ID 前缀 | `s`（SQL，替代 `m`）；旧 MongoDB 数据**全新开始丢弃**；表结构用**关联表**（logs / log_files / log_metadata） |

**技术栈**：Hyperf 3.2 · Swoole 6.2.x · PHP 8.5 · MariaDB（`hyperf/database`）· hyperf/redis（延后到有 ext-redis 环境）· hyperf/guzzle · hyperf/testing

## 类迁移映射（关键）

| 现状 | 迁移后 |
|---|---|
| 全局 `Config` | 废弃 → Hyperf Config 组件（`config/autoload/*.php`） |
| 全局 `Router` | 废弃 → `#[Controller]`/`#[RequestMapping]` |
| 全局 `Handler`（基类） | `App\Controller\AbstractController` |
| `Handler\*`（12 个） | `App\Controller\*` |
| 全局 `ApiResponse`/`ApiError` | `App\Http\ApiResponse`（返回 PSR-7）· `App\Exception\ApiException` |
| 全局 `RequestValidator`/`ContentParser`/`UploadParser` | `App\Http\RequestValidator`（注入 `RequestInterface`）· `App\Http\ContentParser` · `App\Support\UploadParser` |
| 全局 `Log`/`Id`/`Detective` | `App\Service\Log` · `App\Model\Id` · `App\Service\Detective` |
| `Storage\*` / `Filter\*` / `Data\*` / `Cache\*` / `Client\*` / `Agent\*` | 同名迁到 `App\` 前缀 |
| `Filter\Pre\*` / `Filter\FilterType` / `Printer\*` | **删除**（阶段 0 清死代码） |
| `rag\server.php` / `RagSearch` | `App\Rag\RagSearch` + MCP 协程服务 |

## 分阶段执行计划

### 阶段 0 — 基线收尾（独立提交，先行）
- 删死代码：`src/Filter/Pre/`（7 文件）、`FilterType.php`、`src/Printer/`、`Log::getErrorCount/getAnalysis/getCacheStats/getPrinter`、`MongoStorage::VerifyToken/BulkDelete`、`Filter::getAll/filterAll`
- 版本号 `AGENTS.md` v1.5.5 → 1.6.0；同步 `rag/README.md` snippet 语义、README 目录树、AGENTS.md 过滤器「超限拒绝」措辞
- **验收**：`composer test` + `composer stan` 全绿

### 阶段 1 — Hyperf 骨架 + 基础设施
1. 引入 Hyperf 3.2 依赖，生成 `bin/hyperf.php`、`config/`、`storage/`
2. `src/` → `app/`，`composer.json` 只保留 `App\` PSR-4；删 `core.php` 自定义 autoloader 与 `index.php` 的 `set_exception_handler`
3. `Config.inc.php` 拆为 `config/autoload/{database,cache,storage,filter,ai,spinyarn,urls,id}.php`，环境变量覆盖迁入 ConfigProvider
4. `ApiResponse` → 返回 PSR-7（保住 `{success,message,code,error}` JSON 结构）；`ApiError` → `ApiException` + `ExceptionHandler`
5. CORS/OPTIONS → 全局中间件；限流（Redis INCR）→ 中间件
6. Redis → `hyperf/redis` 连接池（延后）；MariaDB → `hyperf/database` + `hyperf/db-connection`（连接池）

### 阶段 2 — 领域层迁移（纯逻辑）
- `Id`/`Filter/*`/`UploadParser`/`Detective`/`Data/*`/`Cache/*`/`Storage/*` 去 `static` → 服务类 + DI
- `MongoStorage` → `MariaDbStorage`（Eloquent 模型 + 表结构重设计：logs / log_files / log_metadata；TTL 清理改定时任务）
- `SpinYarnClient::$handle` → 进程级单例，`WorkerStart` 预热 `spinyarn_init`（根治 CRCLASH #1）

### 阶段 3 — Controller / 路由
- 12 Handler → `App\Controller\*`，注解路由配双前缀；`RequestValidator`/`ContentParser` 改用注入的 `RequestInterface`；架构测试重写

### 阶段 4 — AI 流式改造（最难点）
- `AIClient::streamChat` curl → `hyperf/guzzle` 协程流式，保留多 key 轮换 + `tool_calls` 增量拼接 + `reasoning_content`
- `LogAgent` 工具循环保留，`emit*` → `Swoole\Response->write()`；`MCPClient` curl → 协程
- **硬约束**：SSE 协议逐字节不变，`scripts/ai.sh` 零改动

### 阶段 5 — RAG 整合
- `RagSearch` → DI 服务；`rag/server.php` 由 Hyperf 协程 HTTP server 承载（8081，MCP JSON-RPC 协议不变）；`build_index.php` → Hyperf Command

### 阶段 6 — 部署 + CI
- Dockerfile：常驻进程镜像（Swoole 6.2 + SpinYarn + MariaDB + redis），nginx 反代 9300；更新 CI；本地 Termux 编译 Swoole 6.2

### 阶段 7 — 测试迁移 + 文档
- 166 用例 Pest → `hyperf/testing`；PHPStan level 5 适配 `App\`；更新 AGENTS.md / API.md / README / openapi.yaml

## 风险与缓解

| 风险 | 缓解 |
|---|---|
| R1 SSE 协程重写行为漂移 | 阶段 4 独立隔离，SSE 协议冻结，用 `scripts/ai.sh` 回归 |
| R2 `static` 状态常驻残留 | 阶段 2 逐类审计去 static，请求级实例化 |
| R3 存储迁移 MongoDB→MariaDB | 表结构重设计（logs/log_files/log_metadata），旧数据按需迁移或放弃；TTL 改定时清理 |
| R4 多 worker 资源预热 | `WorkerStart` 回调统一预热 SpinYarn/连接 |
| R5 分支改造中途无法回退 | 每阶段独立 commit，阶段 0-3 可独立验证 |
