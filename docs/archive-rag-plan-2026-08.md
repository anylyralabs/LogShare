# RAG 架构扩展 — 技术报告与开发计划

> **历史归档**：本文件记录 2026-08 的 RAG 架构扩展计划。AI/Agent/RAG 已于
> v1.8.4 从本仓库剥离（由外部服务承接），文中所有目录、命令与接口均已不存在，
> 仅作决策溯源保留。

> 基于 LogShare 当前代码与知识库审计，对 RAG 检索层进行能力增强与架构升级。
> 上一阶段 LogAgent 完整化改造已合并（commit `dfca4fe`），本计划承接后续。

---

## 一、现状诊断

### 1.1 当前架构

```
rag/knowledge/         332KB / 1,150 个 Markdown 文件
    ├── 日志分析/        42 个结构化报错条目（KB 编号）
    ├── patterns/       13 个故障模式
    ├── mobile_launcher/ 启动器常识（2 个文件）
    ├── format/         3 个日志格式指南
    ├── android-native-lib/ 原生库加载问题（2 个文件）
    ├── tools/          测试样例
    ├── zl_about/       站点运营信息（3 个文件）
    └── zl_announcement/ 站点公告（1 个文件）

app/Rag/
    ├── RagSearch.php       932 行 — FTS5 BM25 + CJK bigram LIKE + 向量语义
    ├── RagManager.php      543 行 — 构建/CRUD/统计/Admin API
    └── SemanticClient.php  203 行 — 多 provider embedding 故障转移

app/Client/
    └── MCPClient.php       237 行 — MCP 协议 HTTP 传输

app/Command/
    └── RagBuildCommand.php  61 行 — CLI 重建索引

app/Controller/
    └── RagController.php   275 行 — Admin Web CRUD
```

### 1.2 做得好的部分

- **RagSearch 双路检索扎实**：FTS5 porter unicode61 分词 + prefix 匹配；CJK 3+ 字切成 overlapping bigram 做 LIKE fallback；语义向量可选补充；进程级 FIFO 缓存 64 条/1MB
- **语义层多 provider 故障转移**：按配置顺序重试，跳过无 apiKey 的 provider，支持不同 gateway 的模型名差异
- **索引构建原子化**：写临时库 → rename，构建失败不破坏线上索引
- **知识库 CRUD 有安全边界**：路径遍历防护、扩展名白名单、原子写入、Admin 脱敏
- **分块按 H2 Heading 切割**：保留 H1 标题前缀，短块（≤1600 字）整段返回保结构，长块做命中词为中心 ±800 字符窗口 + 句读边界回退

### 1.3 核心短板

**（1）RagSearch 932 行一人扛全部**

分块、FTS5、LIKE fallback、向量扫描、snippet 提取、topics 管理全在一个类里。新增分块策略需要改 932 行文件， rerank 逻辑无处安放。

**（2）检索管道是"顺序单次"而非"多路融合"**

当前 `BM25 → LIKE fallback → 向量补充` 是串行管道，不是并行召回 + 融合精排。BM25 和向量的 top-k 结果简单 merge，没有 rerank，高相关文档可能被低相关文档挤出 top-n。

**（3）查询侧没有预处理**

用户的查询（实际是 LLM 生成的检索词）直接送入检索。没有查询改写、没有分类路由、没有停用词过滤、没有同义词扩展。中文口语化查询（如"进世界就闪退"）命中率低。

**（4）Chunking 策略单一**

只有按 `##` Heading 切分。问题：
- 超长 H2 section 不会被二次切分，单个 chunk 可能数千字
- 没有 overlap：相邻 chunk 边界处的上下文丢失
- 没有 token-aware 切分：按字符数而非 token 数判断 chunk 大小
- 没有层级化 parent-child 结构

**（5）Embedding 层薄**

SemanticClient 只做了基础 `POST /embeddings`。缺少：查询向量缓存、batch size 自适应、维度自动探测、本地 embedding provider 支持。构建时 embed 一次，查询时全量扫描向量表（LIMIT 5000），知识库变大后查询延迟会上升。

**（6）没有增量索引**

`rag:build` 每次都全量重建。知识库从 1,150 个文件涨到 5,000+ 时构建时间会显著变长。新增/修改单个文档需要全量 rebuild，Admin 在线编辑后无法热更新索引。

**（7）MCP 只有 HTTP 短轮询**

MCPClient 每次调用都是独立的 HTTP POST + 握手。多轮 tool call 场景下重复握手 overhead 明显，且没有连接级缓存。

**（8）没有检索可观测性**

不知道用户查询了什么、哪些命中了、哪些没命中、各阶段耗时多少、embedding API 花了多少钱。没有慢查询日志，没有命中率统计。

---

## 二、改造目标

### 2.1 架构目标

```
app/Rag/
├── Chunker.php               # 分块策略（Heading / 滑动窗口 / token-aware）
├── Chunk.php                 # Chunk 值对象
├── LexicalIndex.php          # FTS5 + LIKE 检索（从 RagSearch 抽出）
├── VectorIndex.php           # 向量扫描 + 余弦相似度（从 RagSearch 抽出）
├── SnippetExtractor.php      # 片段提取与截断（从 RagSearch 抽出）
├── RetrievalPipeline.php     # 多路召回 → merge → rerank（新）
├── RagSearch.php             # 精简为门面（~150 行）
├── RagManager.php            # 增强：增量索引、热更新
├── SemanticClient.php        # 增强：查询缓存、batch 自适应、本地 provider
└── SemanticCache.php         # 查询向量缓存（新）

app/Rag/Rerank/
├── RerankInterface.php
├── LLMReranker.php           # 用 LLM 做 pairwise rerank
└── NoopReranker.php          # 关闭 rerank 时的直通

app/Agent/Tool/
└── RagSearchTool.php         # 增强：注入查询改写与 rerank 能力
```

### 2.2 能力目标

1. **检索质量**：多路召回 + rerank，top-k 准确率预期提升 30-50%
2. **查询鲁棒性**：查询改写 + 分类路由，口语化/模糊查询命中率显著提升
3. **分块质量**：滑动窗口 overlap + token-aware 切分，边界上下文丢失减少
4. **构建效率**：增量索引，单文档热更新，Admin 编辑后秒级生效
5. **查询成本**：embedding 查询缓存，重复查询零 API 调用
6. **可观测性**：完整检索链路埋点，慢查询日志，命中率统计

---

## 三、详细设计

### 3.1 分块策略升级（Chunker）

**现状**：只有按 `##` Heading 切分。

**新增三种分块策略，可配置叠加**：

```php
final class Chunker
{
    /** 按 H2 Heading 切分（保留，作为基础策略） */
    public static function byHeading(string $source, string $content): array;

    /** 滑动窗口切分：固定 chunkSize + overlap 比例 */
    public static function bySlidingWindow(string $content, int $chunkSize = 1000, int $overlap = 150): array;

    /** Token-aware 切分：按估算 token 数切割，避免超长上下文 */
    public static function byToken(string $content, int $maxTokens = 512, int $overlapTokens = 64): array;

    /** 组合策略：Heading 切分为主，超长 chunk 自动降级为滑动窗口二次切分 */
    public static function chunk(string $source, string $content, ChunkStrategy $strategy): array;
}

enum ChunkStrategy {
    case HEADING_ONLY;      // 仅按 H2 切分（当前行为，默认）
    case SLIDING_WINDOW;    // 纯滑动窗口
    case TOKEN_AWARE;       // 按 token 数切分
    case HYBRID;            // Heading 为主，超长 chunk 二次切分（推荐）
}
```

**关键细节**：
- `HYBRID` 模式：先按 Heading 切分，如果某个 chunk 超过 `maxTokens`（如 1024），对该 chunk 内部再用滑动窗口二次切分，并标记 parent chunk id，形成 parent-child 层级结构
- 每个 Chunk 值对象携带：`source`（文件路径）、`title`、`body`、`startOffset`、`endOffset`、`parentId`（如有）、`tokenCount`
- 构建索引时 chunk 元数据存入 SQLite 的 `chunks` 表（新增），方便后续增量更新做 mtime 比对

### 3.2 检索管道重構（RetrievalPipeline）

**现状**：`RagSearch::search()` 内部串行执行 BM25 → LIKE → 向量，简单 merge。

**新设计：并行召回 + 融合精排**

```php
final class RetrievalPipeline
{
    public function __construct(
        private readonly LexicalIndex $lexical,
        private readonly VectorIndex $vector,
        private readonly Reranker $reranker,
        private readonly SnippetExtractor $snippets,
    ) {}

    /**
     * @param array{query: string, topic: string|null, k: int} $params
     * @return array<int, ScoredChunk>
     */
    public function retrieve(array $params): array;
}

final class ScoredChunk
{
    public function __construct(
        public readonly string $source,
        public readonly string $title,
        public readonly string $body,
        public readonly float $lexicalScore,    // BM25 原始分
        public readonly float $vectorScore,     // 余弦相似度
        public readonly float $fusedScore,      // 融合后分数
        public readonly int $rank,              // 最终排名
    ) {}
}
```

**检索流程**：

```
1. 查询预处理（QueryPreProcessor）
   ├── 查询改写：LLM 将原始查询扩写为 2-3 个精准检索词
   ├── 分类路由：判断查询类型（崩溃/模组/性能/网络），加权对应 topic
   └── 停用词过滤 + 同义词扩展

2. 多路并行召回（各取 top-20）
   ├── BM25: FTS5 MATCH + prefix matching
   ├── LIKE: CJK bigram fallback
   └── 向量: 余弦相似度 top-20

3. 融合（RRF — Reciprocal Rank Fusion）
   score = Σ (1 / (k + rank_i))  ×  typeWeight
   不用加权平均，RRF 对分数尺度不敏感，更适合融合不同排序体系

4. Rerank（可选，配置开关）
   ├── LLM Reranker: pairwise comparison，用 cheap 模型对 top-30 精排
   └── NoopReranker: 跳过，直接用 RRF 融合分

5. Snippet 提取
   └── 对 top-k 结果提取围绕命中词的上下文片段
```

**RRF 融合优于简单加权平均的原因**：BM25 分和余弦相似度的数值范围不同（BM25 可能到 10+，余弦在 0-1 之间），加权平均对尺度敏感。RRF 只依赖排名位置，天然免疫尺度差异。

### 3.3 查询改写与分类路由（QueryPreProcessor）

```php
final class QueryPreProcessor
{
    /**
     * 将原始查询扩写为更精准的检索词。
     * 调用 LLM（cheap/fast 模型），prompt 非常轻量：
     * "给定一个 Minecraft 日志排障查询，输出 2-3 个英文检索词
     *  （异常类名、错误关键词），保留原文中的技术术语不变。
     *   查询：{query}
     *   检索词（逗号分隔）："
     */
    public function rewrite(string $query, string $mode = self::MODE_LIGHT): array;

    /**
     * 判断查询类型，返回 topic 权重映射。
     * 用于检索时对不同 topic 的结果做加权偏置。
     */
    public function classify(string $query): array; // ['patterns' => 1.2, '日志分析' => 1.0, ...]
}
```

**分类映射**：

| 查询特征 | 加权 topic |
|---|---|
| 包含 Mixin/ClassNotFound/NoSuchMethod | patterns ×1.3, 日志分析 ×1.2 |
| 包含 SIGSEGV/OOM/exit code | patterns ×1.3 |
| 包含 闪退/崩溃/卡死（中文） | patterns ×1.2, 日志分析 ×1.2 |
| 包含 FCL/Pojav/Amethyst/MobileGlues | mobile_launcher ×1.5 |
| 包含 Fabric/Forge/NeoForge/Quilt | 日志分析 ×1.3 |
| 包含 世界加载/chunk/NBT | patterns ×1.2 |
| 包含 网络/ConnectException/Timeout | 日志分析 ×1.1 |

### 3.4 Reranker — 精排层

**LLM-based Pairwise Reranker**：

```php
final class LLMReranker implements Reranker
{
    public function __construct(
        private readonly AIClientGateway $gateway,
        private readonly int $maxCandidates = 30,
    ) {}

    /**
     * 对 candidates 做 pairwise comparison rerank。
     * 调用 LLM 一次，传入 query + 全部候选，要求按相关性排序。
     * 使用 cheap 模型（如已在配置的 minimax-m2.5-free），
     * prompt 控制在 200 字以内，单次调用成本 < ¥0.001。
     */
    public function rerank(string $query, array $candidates): array;
}
```

**开关控制**：`ai.rag.rerank.enabled`（默认 false，确认有效后再开）。关闭时 RetrievalPipeline 直接用 RRF 融合分。

### 3.5 Chunker 落地到构建流程

`RagSearch::buildIndex()` 中的 chunking 逻辑替换为 `Chunker`：

```php
// 旧逻辑（内联在 buildIndex 中）：
foreach (self::chunkMarkdown($relative, $content) as $chunk) { ... }

// 新逻辑：
$chunks = Chunker::chunk($relative, $content, ChunkStrategy::HYBRID);
foreach ($chunks as $chunk) {
    // chunk 携带 tokenCount，存入新增的 chunks 表
    $insert->execute([$chunk->title, $chunk->body, $relative, $chunk->tokenCount]);
}
```

**新增 `chunks` 表**（与 `docs` 表并列）：
```sql
CREATE TABLE IF NOT EXISTS chunks (
    rowid INTEGER PRIMARY KEY,
    doc_rowid INTEGER,           -- 关联 docs.rowid
    title TEXT,
    body TEXT,
    source TEXT,
    token_count INTEGER,
    parent_rowid INTEGER,        -- parent chunk rowid（如有）
    FOREIGN KEY (doc_rowid) REFERENCES docs(rowid),
    FOREIGN KEY (parent_rowid) REFERENCES chunks(rowid)
);
```

### 3.6 增量索引（RagManager 增强）

**新增方法**：

```php
final class RagManager
{
    /** 增量重建：仅处理变更的文件（基于 mtime 比对） */
    public static function incrementalBuild(string $knowledgeDir, ?SemanticClient $semantic): array;

    /** 增量重建单个文档（Admin 编辑后热更新） */
    public static function reindexDoc(string $relativePath, ?SemanticClient $semantic): array;

    /** 获取需要更新的文件列表（mtime 与索引中记录不符） */
    public static function getStaleFiles(string $knowledgeDir): array;
}
```

**增量逻辑**：
1. 读取现有 `index.db` 中 `chunks` 表的 `source_mtime` 字段
2. 遍历知识库目录，比对文件 mtime
3. 仅对 mtime 变更的文件执行 re-chunk + re-embed
4. 删除已不存在的文件对应的 chunk
5. 原子化 replace 变更的 chunk 行

**Admin 热更新**：`RagController::saveDoc()` 写入文件后自动调用 `RagManager::reindexDoc()`，无需手动 `rag:build`。

### 3.7 Embedding 增强（SemanticClient + SemanticCache）

**新增 SemanticCache**：

```php
final class SemanticCache
{
    /** 查询向量缓存：query hash → vector */
    private const CACHE_PREFIX = 'rag:embed:query:';
    private const CACHE_TTL = 86400; // 24h

    public function get(string $query): ?array;
    public function set(string $query, array $vector): void;
}
```

- 查询向量缓存在 Redis 中（24h TTL），相同查询零 API 调用
- 缓存键：`sha256(query)`，避免长 key 问题
- 进程内也做一层 memory cache（100 条 / 1MB），减少 Redis 往返

**SemanticClient 增强**：
- batch size 自适应：根据 provider 响应自动调整（成功则 +4，失败则 ÷2，范围 4-64）
- 维度自动探测：首次响应后缓存维度，后续请求维度不一致时触发告警并自动 re-embed 该 batch
- 新增本地 provider：`http://localhost:11434/api/embeddings`（Ollama 格式），支持完全离线部署

### 3.8 MCP 层增强

当前 MCPClient 每次调用都是独立 HTTP POST。增强：

1. **连接复用**：同一 endpoint 在一次分析运行内复用 MCPClient 实例（已有 `McpClientFactory` 做 Session 级缓存，维持）
2. **SSE 长连接传输**（可选）：支持 MCP Server 以 SSE 模式推送工具结果，降低多轮 call 的握手开销
3. **MCP 结果缓存**：Session 内相同 tool + arguments 的调用直接返回缓存结果
4. **健康检查**：每次分析开始前 ping RAG MCP endpoint，不可用时自动降级为纯词法检索

### 3.9 知识库内容治理

**新增 `rag/knowledge/.manifest.json`**：

```json
{
  "version": "2.0",
  "builtAt": 1234567890,
  "chunker": "hybrid",
  "maxTokens": 1024,
  "overlapTokens": 128,
  "semantic": {"enabled": true, "providers": ["siliconflow/bge-m3"]},
  "rerank": {"enabled": false},
  "files": [
    {"path": "patterns/mixin-apply-failed.md", "mtime": 1234567800, "chunks": 3},
    ...
  ]
}
```

- 构建时自动生成，记录知识库版本与每文件元数据
- 增量索引通过 manifest 快速判断哪些文件需要更新
- Admin 页面显示知识库版本与最后构建时间

---

## 四、改造后文件结构

```
app/Rag/
├── Chunk.php                    # Chunk 值对象（新）
├── Chunker.php                  # 分块策略（新）
├── LexicalIndex.php             # FTS5 + LIKE 检索（从 RagSearch 抽出）
├── VectorIndex.php              # 向量扫描 + 余弦相似度（从 RagSearch 抽出）
├── SnippetExtractor.php         # 片段提取（从 RagSearch 抽出）
├── RetrievalPipeline.php        # 多路召回 → merge → rerank（新）
├── RagSearch.php                # 精简为门面（~150 行）
├── RagManager.php               # 增强：增量索引 + 热更新
├── SemanticClient.php           # 增强：batch 自适应 + 本地 provider
├── SemanticCache.php            # 查询向量缓存（新）
├── QueryPreProcessor.php        # 查询改写 + 分类路由（新）
└── Rerank/
    ├── RerankInterface.php      # 精排接口
    ├── LLMReranker.php          # LLM pairwise rerank（新）
    └── NoopReranker.php         # 直通（新）

app/Agent/Tool/
└── RagSearchTool.php            # 增强：支持 k/topic 参数透传
```

---

## 五、工作量估算

| 阶段 | 内容 | 预估行数 | 工作量 |
|---|---|---|---|
| 阶段一 | Chunker + Chunk 值对象 + 分块策略 | ~400 | 2h |
| 阶段二 | LexicalIndex + VectorIndex + SnippetExtractor 拆分 | ~500 | 2-3h |
| 阶段三 | RetrievalPipeline + RRF 融合 + LLM Reranker | ~350 | 2h |
| 阶段四 | QueryPreProcessor（查询改写 + 分类路由） | ~200 | 1.5h |
| 阶段五 | RagManager 增量索引 + 热更新 | ~300 | 2-3h |
| 阶段六 | SemanticCache + SemanticClient 增强 | ~200 | 1h |
| 阶段七 | 集成测试 + 回归验证 + MCP 增强 | ~300 | 2h |
| **总计** | | **~2,250** | **~12-13h** |

---

## 六、实施步骤

### Step 1：分块基础设施
1. 新建 `Chunk` 值对象、`Chunker`（三种策略 + HYBRID 组合）
2. 新增 `chunks` SQLite 表（带 token_count / parent_rowid）
3. 修改 `RagSearch::buildIndex()` 调用 Chunker
4. 单测：验证各策略切分结果、HYBRID 超长 chunk 二次切分、parent-child 关系

### Step 2：检索拆分
1. 从 RagSearch 932 行中抽取 `LexicalIndex`（FTS5 + LIKE）和 `VectorIndex`（向量扫描 + 余弦）
2. 抽取 `SnippetExtractor`（片段提取逻辑）
3. RagSearch 精简为门面， delegating 到三个组件
4. 单测：LexicalIndex 和 VectorIndex 独立可测

### Step 3：RetrievalPipeline + Rerank
1. 新建 `RetrievalPipeline`：并行调用 Lexical + Vector，RRF 融合
2. 新建 `RerankInterface` / `LLMReranker` / `NoopReranker`
3. 配置开关 `ai.rag.rerank.enabled`
4. 集成测试：rerank 对 top-k 准确率的影响（用已知 query 验证）

### Step 4：查询预处理
1. 新建 `QueryPreProcessor`：查询改写 + 分类路由
2. 接入 `RagSearchTool`，检索前自动调用
3. 单测：改写准确性、分类映射正确性

### Step 5：增量索引
1. 新建 `chunks` 表的 mtime 字段
2. `RagManager::incrementalBuild()` + `reindexDoc()`
3. `RagController::saveDoc()` 写入后自动触发热更新
4. 单测：增量 build 只处理变更文件、删除文件对应的 chunk 被清理

### Step 6：Embedding 增强
1. 新建 `SemanticCache`（Redis + 进程内双层缓存）
2. `SemanticClient` batch size 自适应 + 维度探测 + 本地 provider
3. 单测：缓存命中/未命中、batch 自适应逻辑

### Step 7：MCP + 可观测性
1. MCPClient 连接复用已有，加 SSE 传输支持（可选）
2. 检索埋点：BM25 命中数、向量命中数、融合结果、各阶段耗时
3. 慢查询日志（>500ms）
4. `rag:stats` 命令：命中率、检索量趋势、embedding 成本

---

## 七、回滚与兼容策略

1. **配置开关矩阵**：

| 开关 | 默认值 | 说明 |
|---|---|---|
| `ai.rag.chunker` | `heading` | heading / sliding / token / hybrid |
| `ai.rag.rerank.enabled` | `false` | rerank 开关，关闭直通 RRF |
| `ai.rag.queryRewrite.enabled` | `false` | 查询改写开关 |
| `ai.rag.incrementalBuild` | `false` | 增量索引开关，关闭则全量 |
| `ai.rag.semanticCache` | `true` | 查询向量缓存 |

2. **渐进式切换**：每个开关独立，可单独开启/关闭。Chunker 切换后第一次 `rag:build` 重建索引，之后行为稳定
3. **回滚**：改配置即可回退到前一策略，不涉及数据迁移
4. **索引兼容**：新版本 `chunks` 表不存在时自动 fallback 到旧 `docs` 表查询路径

---

## 八、验收标准

- [ ] 新增 `chunks` 表，HYBRID chunking 策略可配置
- [ ] `RetrievalPipeline` 并行召回 BM25 + LIKE + 向量，RRF 融合
- [ ] LLM Reranker 可通过配置开关
- [ ] QueryPreProcessor 查询改写与分类路由可配置开关
- [ ] `RagManager::incrementalBuild()` 只处理 mtime 变更的文件
- [ ] `RagManager::reindexDoc()` 支持单文档热更新
- [ ] `SemanticCache` 缓存查询向量，重复查询不触发 embedding API
- [ ] `SemanticClient` 支持本地 Ollama provider
- [ ] 检索埋点记录各阶段耗时与命中数，`rag:stats` 可查看
- [ ] 集成测试：标准查询集在 rerank 开启后 top-5 准确率 ≥ 纯 BM25 基线

---

## 九、与 LogAgent 改造的协同

本计划与已合并的 LogAgent 改造（`feat/logagent-refactor`）形成协同：

| 协同点 | 说明 |
|---|---|
| **RagSearchTool** | LogAgent 改造已将 RagSearch 封装为独立 Tool 类，本计划增强检索能力后，Tool 层零改动直接受益 |
| **PromptBuilder** | 查询改写需要 LLM 调用，通过 `AIClientGateway` 接口复用，不新增外部依赖 |
| **AnalysisTracer** | 检索各阶段耗时自动记录到 trace，无需额外埋点代码 |
| **ToolRegistry** | Rerank 可作为 Tool 注册到 Registry，或在 RetrievalPipeline 内部透明执行（推荐后者，不暴露给 LLM） |
| **Config 体系** | 新增 RAG 配置项通过 `App\Config` 热加载，无需重启 |

---

*计划版本：v1.0 | 对应 LogShare commit: dfca4fe*
