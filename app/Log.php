<?php

namespace App;

use Aternos\Codex\Analysis\Analysis;
use Aternos\Codex\Log\File\StringLogFile;
use Aternos\Codex\Minecraft\Log\Minecraft\MinecraftLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\Fabric\FabricLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaClientLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaCrashReportLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaNetworkProtocolErrorReportLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaServerLog;
use App\Data\MetadataEntry;
use App\Data\Token;
use App\Storage\StorageInterface;

class Log
{
    private bool $exists = false;
    private ?Id $id = null;
    private ?string $data = null;
    private ?Token $token = null;
    /** @var MetadataEntry[] */
    private array $metadata = [];
    private ?string $source = null;
    private ?int $created = null;
    private ?int $expires = null;
    /** @var array<int, array{name: string, data?: string, size?: int}> */
    private array $files = [];
    private ?int $lineCount = null;
    /** 最近一次 put() 是否命中了已有内容指纹（去重） */
    private bool $deduplicated = false;
    /** $log 是否仅由头部片段解析而来（此时不能用于最终结论） */
    private bool $headerOnly = false;
    protected ?\Aternos\Codex\Log\Log $log = null;

    /**
     * @var Analysis|null
     */
    protected ?Analysis $analysis = null;

    /**
     * Log constructor.
     *
     * @param Id|null $id
     */
    public function __construct(?Id $id = null)
    {
        if ($id) {
            $this->id = $id;
            $this->load();
        }
    }

    /**
     * Load the log from storage (primary storage with optional Redis cache)
     */
    private function load(): void
    {
        $config = Config::Get('storage');

        if (!isset($config['storages'][$this->id->getStorage()])) {
            $this->exists = false;
            return;
        }

        if (!$config['storages'][$this->id->getStorage()]['enabled']) {
            $this->exists = false;
            return;
        }

        $result = null;

        if ($this->isCacheEnabled()) {
            if ($this->isDeletedTombstoned()) {
                // 墓碑存在：该 id 已被删除但当时缓存删除失败，残留缓存不可信，
                // 跳过缓存读取直接回源（主存储已删，正确返回不存在）
                \App\Syslog::error("Redis", "命中删除墓碑，跳过缓存: " . $this->getRedisCacheKey());
            } else {
                try {
                    $result = $this->loadFromRedis();
                } catch (\Exception $e) {
                    \App\Syslog::error("Redis", "缓存读取失败: " . $e->getMessage());
                }
            }
        }

        if ($result === null) {
            /**
             * @var StorageInterface $storage
             */
            $storage = $config['storages'][$this->id->getStorage()]['class'];
            $result = $storage::Get($this->id);

            if ($result !== null && $this->isCacheEnabled() && !$this->isDeletedTombstoned()) {
                /** @var array<int, array{name: string, data?: string, size?: int}> $storedFiles */
                $storedFiles = $result['files'] ?? [];
                $filesBytes = array_sum(array_map(
                    static fn(array $file): int => isset($file['size']) ? (int) $file['size'] : strlen($file['data'] ?? ''),
                    $storedFiles
                ));
                if ($this->shouldCacheToRedis((string) $result['data'], $filesBytes)) {
                    try {
                        /** @var array<string, mixed> $result */
                        $this->saveToRedisCache($result);
                    } catch (\Exception $e) {
                        \App\Syslog::error('Redis', '缓存写入失败: ' . $e->getMessage());
                    }
                }
            }
        }

        if ($result === null) {
            $this->exists = false;
            return;
        }

        $this->data = $result['data'];
        // 存储里永远是哈希（可能是不带前缀的旧格式），用 fromHash() 构造，
        // 让 matches() 只走哈希比较，绝不把「库里的值」当明文凭证。
        $this->token = Token::fromHash(isset($result['token']) ? (string) $result['token'] : null);
        $this->metadata = MetadataEntry::allFromArray($result['metadata'] ?? []);
        $this->source = (isset($result['source']) && $result['source'] !== '') ? (string) $result['source'] : '未指定';
        $createdValue = $result['created'] ?? null;
        $this->created = is_numeric($createdValue) ? (int) $createdValue : null;
        $this->expires = $this->created !== null ? $this->created + (int) $config['storageTime'] : null;
        $this->files = $result['files'] ?? [];
        $this->exists = true;
    }

    /** 上传期解析头部所用的最大字节数（足够覆盖版本行与启动器标识） */
    private const HEADER_PARSE_BYTES = 65536;

    /**
     * 只解析日志头部，用于低成本地提取版本 / 加载器元数据。
     *
     * 与 analyse() 的区别：只取前 64KB 交给 Codex，实测 3.5MB 日志从 1.9s
     * 降到 13ms，足以识别 Minecraft 版本与 mod loader（两者都在启动头部）。
     * 结果只写入 $this->log，不产生 $this->analysis，避免 insights 误用截断结果。
     */
    private function analyseHeader(): void
    {
        $header = mb_strcut((string) $this->data, 0, self::HEADER_PARSE_BYTES, 'UTF-8');
        if ($header === '') {
            return;
        }

        $detected = (new Detective())->setLogFile(new StringLogFile($header))->detect();
        if (!$detected instanceof MinecraftLog) {
            return;
        }

        $this->log = $detected;
        $this->log->parse();
        $this->log->analyse();
        $this->headerOnly = true;
    }

    /**
     * Analyse the log content
     * @return Analysis
     */
    public function analyse(): Analysis
    {
        $detected = (new Detective())->setLogFile(new StringLogFile($this->data))->detect();
        /** @var \Aternos\Codex\Log\Log $detected */
        $this->log = $detected;
        $this->log->parse();
        $this->analysis = $this->log->analyse();
        $this->headerOnly = false;
        return $this->analysis;
    }

    /**
     * 用 Codex 已识别出的 Minecraft 版本与 Mod Loader 补全生态统计所需的元数据。
     *
     * 后台 analytics/versions 的主查询按 log_metadata 的 version / loader 键聚合，
     * 而这两个键历史上只来自客户端自行提交，绝大多数客户端并不提交，导致版本矩阵
     * 长期只能落到启发式兜底。put() 在落库前只解析头部（analyseHeader()），
     * 版本行与加载器标识都在启动日志头部，足以支撑聚合；全文分析留给
     * /v1/insights 按需执行，避免上传被 1~2 秒的 CPU 解析拖住。
     *
     * 客户端已显式提交同名键时一律以客户端值为准，不覆盖、不重复写入。
     *
     * @param MetadataEntry[] $metadata
     * @return MetadataEntry[]
     */
    private function deriveEcosystemMetadata(array $metadata): array
    {
        if (! $this->log instanceof MinecraftLog || count($metadata) >= MetadataEntry::MAX_ENTRIES) {
            return $metadata;
        }

        /** @var array<string, array{value: string, label: string}> $derived */
        $derived = [];

        $version = $this->log->getVersion();
        if (is_string($version) && $version !== '') {
            $derived['version'] = ['value' => $version, 'label' => 'Minecraft 版本'];
        }

        // getNameId() 给出稳定小写标识（fabric / neoforge / vanilla），避免同名不同写法拉散分组
        $loader = $this->log->getNameId();
        if (is_string($loader) && $loader !== '') {
            $derived['loader'] = ['value' => $loader, 'label' => '模组加载器'];
        }

        foreach ($metadata as $entry) {
            unset($derived[$entry->getKey()]);
        }

        // label 仅供展示，聚合与查询一律按 key，改文案不影响统计口径
        foreach ($derived as $key => $item) {
            $entry = (new MetadataEntry())
                ->setKey($key)
                ->setValue($item['value'])
                ->setLabel($item['label']);
            if ($entry->isValid()) {
                $metadata[] = $entry;
            }
        }

        return $metadata;
    }

    /**
     * 分析结果 JSON 缓存（进程级，keyed by 内容 hash）。
     *
     * Codex 的 detect+parse+analyse 对大型日志成本高昂（~2.5s/3.5MB），同一内容的
     * 重复分析（如 GET /v1/insights/{id} 反复访问）应命中缓存，避免重复全量分析。
     *
     * 容量约束：条数上限 + 总字节预算 + 单条字节上限。分析 JSON 体积与日志内容
     * 成正比，Swoole 常驻进程内存只增不还，不限字节会让 32 条大分析占满 worker 内存。
     *
     * @var array<string, string>
     */
    private static array $analysisJsonCache = [];

    private const MAX_ANALYSIS_JSON_CACHE = 32;
    private const MAX_ANALYSIS_JSON_ENTRY_BYTES = 4 * 1024 * 1024;
    private const MAX_ANALYSIS_JSON_TOTAL_BYTES = 32 * 1024 * 1024;

    private static int $analysisJsonCacheBytes = 0;

    /**
     * 返回 Codex 分析结果的 JSON 字符串（含进程级缓存）。
     */
    public function getAnalysisJson(): string
    {
        $key = md5((string) $this->data);
        if (isset(self::$analysisJsonCache[$key])) {
            $json = self::$analysisJsonCache[$key];
            // LRU 语义：命中后重插到尾部，避免热条目被 FIFO 挤出
            unset(self::$analysisJsonCache[$key]);
            self::$analysisJsonCache[$key] = $json;
            return $json;
        }

        $codexLog = $this->get();
        $codexLog->setIncludeEntries(false);
        $json = json_encode($codexLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 编码失败（如内容含非法 UTF-8）时不得把空串写入缓存，
        // 否则同内容的后续请求会持续命中坏缓存并返回空响应。
        if ($json === false) {
            throw new ApiError(500, 'Failed to encode analysis result: ' . json_last_error_msg());
        }

        $jsonBytes = strlen($json);
        if ($jsonBytes <= self::MAX_ANALYSIS_JSON_ENTRY_BYTES) {
            while (self::$analysisJsonCache !== []
                && (count(self::$analysisJsonCache) >= self::MAX_ANALYSIS_JSON_CACHE
                    || self::$analysisJsonCacheBytes + $jsonBytes > self::MAX_ANALYSIS_JSON_TOTAL_BYTES)) {
                $evicted = array_key_first(self::$analysisJsonCache);
                self::$analysisJsonCacheBytes -= strlen(self::$analysisJsonCache[$evicted]);
                unset(self::$analysisJsonCache[$evicted]);
            }
            self::$analysisJsonCache[$key] = $json;
            self::$analysisJsonCacheBytes += $jsonBytes;
        }

        return $json;
    }

    /**
     * Resolve the SpinYarn mapping type for this log.
     *
     * @return string|null "yarn" (Fabric) / "vanilla" (Mojang official), or null to skip
     */
    protected function getMappingType(): ?string
    {
        if (in_array(get_class($this->get()), [
            VanillaServerLog::class,
            VanillaClientLog::class,
            VanillaCrashReportLog::class,
            VanillaNetworkProtocolErrorReportLog::class
        ])) {
            return 'vanilla';
        }

        if ($this->get() instanceof FabricLog) {
            return 'yarn';
        }

        return null;
    }

    /**
     * Deobfuscate the log content before it is stored, so the database holds
     * deobfuscated text and reads need no further deobfuscation.
     *
     * Detects the log type and version, then calls the SpinYarn extension.
     * When the extension is absent or the log needs no deobfuscation, the
     * data passes through unchanged.
     *
     * @return void
     */
    protected function deobfuscateForStorage(): void
    {
        $detected = (new Detective())->setLogFile(new StringLogFile($this->data))->detect();
        if (!$detected instanceof \Aternos\Codex\Minecraft\Log\Minecraft\MinecraftLog) {
            return;
        }
        $this->log = $detected;
        $this->log->parse();

        $mappingType = $this->getMappingType();
        if ($mappingType === null) {
            return;
        }

        // Codex 最佳实践：通过 Log::getVersion() 获取版本（内部走 analyse，带缓存）。
        $version = $detected->getVersion();
        if ($version === null) {
            return;
        }

        $content = \App\Client\SpinYarnClient::deobfuscate($this->data, $version, $mappingType);
        if ($content === null) {
            return;
        }

        $this->data = $content;
        $this->lineCount = null;

        // 反混淆后重置 log/analysis，让后续 get()/analyse() 基于新内容重新检测
        $this->log = null;
        $this->analysis = null;
    }

    /**
     * 执行反混淆并更新持久化存储与缓存（供异步队列调用）。
     *
     * @return bool 是否成功反混淆并更新内容
     */
    public function deobfuscateAndPersist(): bool
    {
        if (!$this->exists || empty($this->data)) {
            return false;
        }

        $detected = (new Detective())->setLogFile(new StringLogFile($this->data))->detect();
        if (!$detected instanceof \Aternos\Codex\Minecraft\Log\Minecraft\MinecraftLog) {
            return false;
        }
        $this->log = $detected;
        $this->log->parse();

        $mappingType = $this->getMappingType();
        if ($mappingType === null) {
            return false;
        }

        $version = $detected->getVersion();
        if ($version === null) {
            return false;
        }

        $content = \App\Client\SpinYarnClient::deobfuscate($this->data, $version, $mappingType);
        if ($content === null || $content === $this->data) {
            return false;
        }

        return $this->updateContent($content);
    }

    /**
     * Checks if the log exists
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * 最近一次 put() 是否复用了已有日志（内容指纹命中）。
     */
    public function wasDeduplicated(): bool
    {
        return $this->deduplicated;
    }

    /**
     * Get the log id
     *
     * @return Id|null
     */
    public function getId(): ?Id
    {
        return $this->id;
    }

    /**
     * Get the log
     *
     * @return \Aternos\Codex\Log\Log
     */
    public function get(): \Aternos\Codex\Log\Log
    {
        // 头部片段只用于上传期的元数据提取；对外取完整分析结果时必须
        // 基于全文重新解析，否则 insights 会返回被截断的结论。
        if ($this->log === null || $this->headerOnly) {
            $this->analyse();
        }
        return $this->log;
    }

    /**
     * Get the amount of lines in this log
     *
     * @return int
     */
    public function getLineNumbers(): int
    {
        if ($this->lineCount === null) {
            $this->lineCount = substr_count($this->data, "\n") + 1;
        }
        return $this->lineCount;
    }

    /**
     * Get the raw content of the log
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->data;
    }

    /**
     * Set the data of the log without saving it to storage
     *
     * @param string $data
     * @return Log
     */
    public function setData(string $data): Log
    {
        $this->data = $data;
        $this->lineCount = null;
        $this->preFilter();
        $this->deobfuscateForStorage();
        return $this;
    }

    /**
     * Put data into the log (with Redis cache)
     *
     * @param string $data
     * @param Token|null $token
     * @param MetadataEntry[] $metadata
     * @param string|null $source
     * @param array<int, array{name: string, data?: string, size?: int}>|null $files Additional files stored under the same id
     * @return ?Id
     */
    public function put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null): ?Id
    {
        $this->data = $data;
        $this->lineCount = null;
        $this->preFilter();
        // 上传路径只解析「头部指纹」用于生态元数据（版本/加载器），不跑全量
        // Codex 分析：3.5MB 日志的 detect+parse+analyse 实测约 1.9s，且是纯 CPU
        // 调用，会阻塞整个 Swoole worker。全量分析在 /v1/insights 首次访问时按需执行。
        try {
            $this->analyseHeader();
        } catch (\Throwable $e) {
            \App\Syslog::error('Log', 'Codex header analysis during put failed: ' . $e->getMessage());
        }
        $plainToken = $token ?? new Token();
        // 存储层（MariaDB / 文件系统 / Redis 缓存）只落带前缀的 SHA-256；
        // 上传响应通过调用方持有的 $token 原对象返回原文。
        $this->token = new Token(Token::hashValue((string) $plainToken->get()));
        $this->metadata = $this->deriveEcosystemMetadata($metadata);
        $this->source = ($source !== null && trim($source) !== '')
            ? mb_strcut(trim($source), 0, 64, 'UTF-8')
            : '未指定';
        $this->files = [];

        if (!empty($files)) {
            $filteredFiles = [];
            foreach ($files as $index => $file) {
                $rawContent = $file['data'] ?? '';
                // LogController 会把 files[0] 复制为主 content：此时主 content 已
                // 经过整条过滤链 + 反混淆，直接复用，避免同一内容重复过滤且保证
                // 两处存储内容一致
                if ($index === 0 && $rawContent === $data) {
                    $filteredContent = $this->data;
                } else {
                    $filteredContent = $this->preFilterValue($rawContent);
                }
                $filteredFiles[] = [
                    'name' => $file['name'],
                    'data' => $filteredContent,
                    'size' => strlen($filteredContent),
                ];
            }
            $this->files = $filteredFiles;
        }

        $config = Config::Get('storage');

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$config['storageId']]['class'];

        $created = null;
        $this->id = $storage::Put($this->data, $this->token, $this->metadata, $this->source, $this->files, $created);
        $this->exists = $this->id !== null;
        $this->deduplicated = $created === false;

        // 去重命中时存储层没有写入本次 token：绝不能拿它覆盖缓存，否则第二个
        // 上传者会取得该日志的删除凭证（IDOR）。此时也不写缓存，读路径回源即可。
        if ($this->id !== null && $created !== false && $this->isCacheEnabled()) {
            $filesBytes = array_sum(array_map(fn($file) => strlen($file['data']), $this->files));
            if ($this->shouldCacheToRedis($this->data, $filesBytes)) {
                try {
                    $this->saveToRedisCache([
                        'data' => $this->data,
                        'token' => $this->token->get(),
                        'metadata' => array_map(fn($entry) => $entry->jsonSerialize(), $this->metadata),
                        'source' => $this->source,
                        'created' => time(),
                        'files' => $this->files,
                    ]);
                } catch (\Exception $e) {
                    \App\Syslog::error('Redis', '缓存写入失败: ' . $e->getMessage() . '，已降级到 MariaDB');
                }
            }
        }

        return $this->id;
    }

    /**
     * Update log content (and optional files) in storage and redis cache.
     *
     * @param string $newData
     * @param array<int, array{name: string, data?: string, size?: int}>|null $newFiles
     * @return bool
     */
    public function updateContent(string $newData, ?array $newFiles = null): bool
    {
        if (!$this->id || !$this->exists) {
            return false;
        }

        $config = Config::Get('storage');
        $storageId = $this->id->getStorage();
        if (!isset($config['storages'][$storageId]) || !$config['storages'][$storageId]['enabled']) {
            return false;
        }

        /** @var \App\Storage\StorageInterface $storage */
        $storage = $config['storages'][$storageId]['class'];
        $success = $storage::Update($this->id, $newData, $newFiles);
        if (!$success) {
            return false;
        }

        $this->data = $newData;
        $this->lineCount = null;
        $this->log = null;
        $this->analysis = null;
        $this->headerOnly = false;
        if ($newFiles !== null) {
            $this->files = $newFiles;
        }

        // 同步刷新 Redis 缓存
        if ($this->isCacheEnabled() && !$this->isDeletedTombstoned()) {
            $filesBytes = array_sum(array_map(fn($file) => strlen($file['data'] ?? ''), $this->files));
            if ($this->shouldCacheToRedis($this->data, $filesBytes)) {
                try {
                    $this->saveToRedisCache([
                        'data' => $this->data,
                        'token' => $this->token?->get(),
                        'metadata' => array_map(fn($entry) => $entry->jsonSerialize(), $this->metadata),
                        'source' => $this->source,
                        'created' => time(),
                        'files' => $this->files,
                    ]);
                } catch (\Throwable $e) {
                    \App\Syslog::error('Redis', '缓存更新失败: ' . $e->getMessage());
                }
            }
        }

        return true;
    }

    /**
     * Renew the expiry timestamp to expand the ttl
     */
    public function renew(): void
    {
        if (!$this->id) {
            return;
        }

        $config = Config::Get('storage');

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$this->id->getStorage()]['class'];

        $storage::Renew($this->id);

        // 读路径绝不做分区维护：CleanupExpired() 会执行 ALTER TABLE ADD/DROP
        // PARTITION，需要元数据锁，可能阻塞同表上传并长时间占用连接池。
        // 过期回收由 mariadb-events 定时事件负责。

        if ($this->isCacheEnabled()) {
            try {
                $this->renewRedisCache();
            } catch (\Exception $e) {
                \App\Syslog::error("Redis", "缓存 TTL 续期失败: " . $e->getMessage());
            }
        }

        $this->expires = time() + (int) $config['storageTime'];
    }

    /**
     * Apply pre filters to data string
     */
    private function preFilter(): void
    {
        $this->data = $this->preFilterValue((string) $this->data);
    }

    /**
     * Run the pre filter chain over a single content string
     * (shared by the primary log body and additional files).
     */
    private function preFilterValue(string $data): string
    {
        $config = Config::Get('filter');
        foreach ($config['pre'] as $preFilterClass) {
            $data = $preFilterClass::filter($data);
        }
        return $data;
    }

    /**
     * Delete the log from storage (both Redis cache and MariaDB)
     *
     * @return bool Success
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        $config = Config::Get('storage');

        if (!isset($config['storages'][$this->id->getStorage()])) {
            return false;
        }

        if (!$config['storages'][$this->id->getStorage()]['enabled']) {
            return false;
        }

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$this->id->getStorage()]['class'];

        $result = $storage::Delete($this->id);

        if ($result) {
            $this->exists = false;
            $this->data = null;
        }

        // 仅在主存储删除成功后清理缓存；删除失败时写墓碑标记（TTL 与缓存一致），
        // 防止残留缓存在 TTL 窗口内继续返回已删日志
        if ($result && $this->isCacheEnabled()) {
            try {
                $this->deleteFromRedisCache();
            } catch (\Exception $e) {
                \App\Syslog::error("Redis", "缓存删除失败: " . $e->getMessage());
                try {
                    $this->deleteFromRedisCache();
                } catch (\Exception $retry) {
                    \App\Syslog::error("Redis", "缓存删除重试失败，写入墓碑标记: " . $retry->getMessage());
                    try {
                        $ttl = (int) (Config::Get('cache')['ttl'] ?? 1800);
                        \App\Cache\RedisCache::Set($this->getRedisCacheKey() . ':deleted', '1', $ttl);
                    } catch (\Exception $tombstone) {
                        \App\Syslog::error("Redis", "墓碑标记写入失败: " . $tombstone->getMessage());
                    }
                }
            }
        }

        return $result;
    }

    /**
     * 检查该日志 id 是否带有删除墓碑标记
     */
    private function isDeletedTombstoned(): bool
    {
        try {
            return \App\Cache\RedisCache::Exists($this->getRedisCacheKey() . ':deleted');
        } catch (\Exception $e) {
            // Redis 不可用时墓碑不可读，交给回源判定
            return false;
        }
    }

    /**
     * Get the token
     *
     * @return Token|null
     */
    public function getToken(): ?Token
    {
        return $this->token;
    }

    /**
     * Get the metadata
     *
     * @return MetadataEntry[]
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get the source
     *
     * @return string|null
     */
    public function getSource(): ?string
    {
        return $this->source !== null && $this->source !== '' ? $this->source : '未指定';
    }

    /**
     * Get the list of raw files stored under this log id (including content data).
     *
     * @return array<int, array{name: string, data?: string, size?: int}>
     */
    public function getRawFiles(): array
    {
        return $this->files;
    }

    /**
     * Get the list of additional files stored under this log id
     *
     * Each entry: ['name' => string, 'size' => int]
     *
     * @return array<int, array{name: string, size: int}>
     */
    public function getFiles(): array
    {
        return array_map(fn($file) => [
            'name' => $file['name'],
            'size' => isset($file['size']) ? (int) $file['size'] : strlen($file['data'] ?? ''),
        ], $this->files);
    }

    /**
     * Get the raw content of an additional file by its name
     *
     * @param string $name
     * @return string|null
     */
    public function getFile(string $name): ?string
    {
        foreach ($this->files as $file) {
            if ($file['name'] === $name) {
                return $file['data'] ?? '';
            }
        }
        return null;
    }

    /**
     * Check whether an additional file exists under this log id
     *
     * @param string $name
     * @return bool
     */
    public function hasFile(string $name): bool
    {
        return $this->getFile($name) !== null;
    }

    /**
     * Get the line count of an additional file
     *
     * @param string $name
     * @return int 0 if the file does not exist
     */
    public function getFileLineNumbers(string $name): int
    {
        $content = $this->getFile($name);
        return $content === null ? 0 : substr_count($content, "\n") + 1;
    }

    /**
     * Get the created timestamp
     *
     * @return int|null
     */
    public function getCreated(): ?int
    {
        return $this->created;
    }

    /**
     * Get the expires timestamp
     *
     * @return int|null
     */
    public function getExpires(): ?int
    {
        return $this->expires;
    }

    /**
     * Get the size of the log content
     *
     * @return int
     */
    public function getSize(): int
    {
        return strlen($this->data);
    }

    /**
     * Verify if the provided token matches
     *
     * @param string $token
     * @return bool
     */
    public function verifyToken(string $token): bool
    {
        if (!$this->token) {
            return false;
        }

        $matched = $this->token->matches($token);
        if ($matched && !$this->token->hasPrefix()) {
            // 存量旧记录：验证通过后顺手把存储值升级为带前缀的哈希，
            // 迁移是惰性的，不需要停机脚本。
            $this->upgradeStoredToken();
        }

        return $matched;
    }

    /**
     * 把遗留的裸哈希存储值补上 `sha256:` 前缀（仅写存储，失败不影响鉴权）。
     */
    private function upgradeStoredToken(): void
    {
        if (!$this->id || $this->token === null) {
            return;
        }

        $config = Config::Get('storage');
        $storageId = $this->id->getStorage();
        if (!isset($config['storages'][$storageId]['class'])) {
            return;
        }

        try {
            /** @var class-string<StorageInterface> $storage */
            $storage = $config['storages'][$storageId]['class'];
            if (is_callable([$storage, 'UpgradeToken'])) {
                $storage::UpgradeToken($this->id, $this->token->normalizedValue());
            }
        } catch (\Throwable $e) {
            \App\Syslog::error('Log', 'token 格式升级失败: ' . $e->getMessage());
        }
    }

    private function getRedisCacheKey(): string
    {
        return "log:" . $this->id->getRaw();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFromRedis(): ?array
    {
        $cacheKey = $this->getRedisCacheKey();

        try {
            $cachedData = \App\Cache\RedisCache::Get($cacheKey);
        } catch (\Exception $e) {
            throw new \Exception("Redis Get 操作失败: " . $e->getMessage(), 0, $e);
        }

        if ($cachedData === null) {
            return null;
        }

        $decoded = json_decode($cachedData, true);
        if ($decoded === null || !is_array($decoded)) {
            \App\Syslog::error("Redis", "缓存数据 JSON 解析失败: " . $cacheKey);
            return null;
        }

        return [
            'data' => $decoded['data'] ?? null,
            'token' => $decoded['token'] ?? null,
            'metadata' => $decoded['metadata'] ?? [],
            'source' => $decoded['source'] ?? null,
            'files' => $decoded['files'] ?? [],
            'created' => $decoded['created'] ?? null,
        ];
    }

    /**
     * 判断日志是否应该缓存到 Redis。
     *
     * 统一口径：主内容 + 附加文件字节总量均不得超过 cache.maxSize，
     * load 回源与 put 写入两条路径共用本判定。
     *
     * @param string $data 主日志数据
     * @param int $filesBytes 附加文件字节总量
     * @return bool
     */
    private function shouldCacheToRedis(string $data, int $filesBytes = 0): bool
    {
        $config = Config::Get('cache');
        $maxCacheSize = $config['maxSize'] ?? (5 * 1024 * 1024);
        return strlen($data) + $filesBytes <= $maxCacheSize;
    }

    /**
     * 检查 Redis 缓存是否启用
     *
     * @return bool
     */
    private function isCacheEnabled(): bool
    {
        $config = Config::Get('cache');
        return $config['enabled'] ?? true;
    }

    /**
     * 保存日志到 Redis 缓存
     *
     * @param array<string, mixed> $data
     * @return void
     * @throws \Exception
     */
    private function saveToRedisCache(array $data): void
    {
        $cacheKey = $this->getRedisCacheKey();
        $config = Config::Get('cache');

        $cacheDataArray = $data;

        $cacheData = json_encode($cacheDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($cacheData === false) {
            throw new \Exception("Redis 缓存数据 JSON 编码失败: " . json_last_error_msg());
        }

        try {
            \App\Cache\RedisCache::Set($cacheKey, $cacheData, $config['ttl'] ?? 1800);
        } catch (\Exception $e) {
            throw new \Exception("Redis Set 操作失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 续期 Redis 缓存 TTL
     *
     * @return void
     * @throws \Exception
     */
    private function renewRedisCache(): void
    {
        $cacheKey = $this->getRedisCacheKey();
        $config = Config::Get('cache');

        try {
            $cacheData = \App\Cache\RedisCache::Get($cacheKey);
        } catch (\Exception $e) {
            throw new \Exception("Redis Get 操作失败: " . $e->getMessage(), 0, $e);
        }

        if ($cacheData !== null) {
            try {
                \App\Cache\RedisCache::Set($cacheKey, $cacheData, $config['ttl'] ?? 1800);
            } catch (\Exception $e) {
                throw new \Exception("Redis Set 操作失败: " . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * 从 Redis 缓存删除日志
     *
     * @return void
     * @throws \Exception
     */
    private function deleteFromRedisCache(): void
    {
        $cacheKey = $this->getRedisCacheKey();

        try {
            \App\Cache\RedisCache::Delete($cacheKey);
        } catch (\Exception $e) {
            throw new \Exception("Redis Delete 操作失败: " . $e->getMessage(), 0, $e);
        }
    }

}
