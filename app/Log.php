<?php

namespace App;

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

/**
 * 一条日志分享记录。
 *
 * 记录不再区分“正文”和“附件”：一个 ID 下就是一组文件。上传时逐文件
 * 脱敏并在命中 SpinYarn 映射时反混淆；全文 Codex 分析只在显式请求某个
 * 文件的分析结果时执行。
 */
class Log
{
    private bool $exists = false;
    private ?Id $id = null;
    private ?Token $token = null;
    /** @var MetadataEntry[] */
    private array $metadata = [];
    private ?string $source = null;
    private ?int $created = null;
    private ?int $expires = null;
    /** @var array<int, array{name: string, data: string, size: int}> */
    private array $files = [];
    /** 最近一次 put() 是否命中了已有内容指纹（去重） */
    private bool $deduplicated = false;
    /** 上传期反混淆时识别出的第一份 Minecraft 日志，仅用于补生态元数据 */
    private ?MinecraftLog $detectedLog = null;

    /**
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
     * Load the log from storage (primary storage with optional Redis cache).
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
                if ($this->shouldCacheToRedis($filesBytes)) {
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

        // 存储里永远是哈希（可能是不带前缀的旧格式），用 fromHash() 构造，
        // 让 matches() 只走哈希比较，绝不把「库里的值」当明文凭证。
        $this->token = Token::fromHash(isset($result['token']) ? (string) $result['token'] : null);
        $this->metadata = MetadataEntry::allFromArray($result['metadata'] ?? []);
        $this->source = (isset($result['source']) && $result['source'] !== '') ? (string) $result['source'] : '未指定';
        $createdValue = $result['created'] ?? null;
        $this->created = is_numeric($createdValue) ? (int) $createdValue : null;
        $this->expires = $this->created !== null ? $this->created + (int) $config['storageTime'] : null;
        $this->files = $this->normalizeLoadedFiles($result['files'] ?? []);
        $this->exists = true;
    }

    /**
     * @param array<int, array{name?: string, data?: string, size?: int}> $files
     * @return array<int, array{name: string, data: string, size: int}>
     */
    private function normalizeLoadedFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $file) {
            $data = (string) ($file['data'] ?? '');
            $normalized[] = [
                'name' => (string) ($file['name'] ?? ''),
                'data' => $data,
                'size' => isset($file['size']) ? (int) $file['size'] : strlen($data),
            ];
        }
        return $normalized;
    }

    /**
     * Store a log with one or more files.
     *
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     * @param MetadataEntry[] $metadata
     * @return ?Id
     * @throws ApiError
     */
    public function put(array $files, ?Token $token = null, array $metadata = [], ?string $source = null): ?Id
    {
        if ($files === []) {
            throw new ApiError(400, "At least one file is required.");
        }

        $config = Config::Get('storage');
        $maxTotalBytes = (int) ($config['uploadFiles']['maxTotalBytes'] ?? (20 * 1024 * 1024));

        $this->detectedLog = null;
        $prepared = [];
        $seenNames = [];
        $totalBytes = 0;

        foreach ($files as $file) {
            $name = (string) $file['name'];
            if (!UploadParser::validateFileName($name)) {
                throw new ApiError(400, "Invalid file name: " . mb_strcut($name, 0, 64));
            }
            if (isset($seenNames[$name])) {
                throw new ApiError(400, "Duplicate file name: " . mb_strcut($name, 0, 64));
            }
            $seenNames[$name] = true;

            $content = $this->preFilterValue((string) ($file['data'] ?? ''));
            $content = $this->deobfuscateContent($content);
            $totalBytes += strlen($content);

            if ($maxTotalBytes > 0 && $totalBytes > $maxTotalBytes) {
                throw new ApiError(413, "Upload exceeds maximum total size of {$maxTotalBytes} bytes.");
            }

            $prepared[] = [
                'name' => $name,
                'data' => $content,
                'size' => strlen($content),
            ];
        }

        $plainToken = $token ?? new Token();
        // 存储层只落带前缀的 SHA-256；上传响应通过调用方持有的 $token 原对象返回原文。
        $this->token = new Token(Token::hashValue((string) $plainToken->get()));
        try {
            $this->metadata = $this->deriveEcosystemMetadata($metadata);
        } catch (\Throwable $e) {
            \App\Syslog::error('Log', 'Metadata derivation failed: ' . $e->getMessage());
            $this->metadata = $metadata;
        }
        $this->source = ($source !== null && trim($source) !== '')
            ? mb_strcut(trim($source), 0, 64, 'UTF-8')
            : '未指定';
        $this->files = $prepared;
        $this->detectedLog = null;

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$config['storageId']]['class'];

        $created = null;
        $this->id = $storage::Put($this->files, $this->token, $this->metadata, $this->source, $created);
        $this->exists = $this->id !== null;
        $this->deduplicated = $created === false;

        // 去重命中时存储层没有写入本次 token：绝不能拿它覆盖缓存，否则第二个
        // 上传者会取得该日志的删除凭证（IDOR）。此时也不写缓存，读路径回源即可。
        if ($this->id !== null && $created !== false && $this->isCacheEnabled()) {
            $filesBytes = array_sum(array_map(static fn(array $file): int => strlen($file['data']), $this->files));
            if ($this->shouldCacheToRedis($filesBytes)) {
                try {
                    $this->saveToRedisCache([
                        'token' => $this->token->get(),
                        'metadata' => array_map(static fn(MetadataEntry $entry): array => $entry->jsonSerialize(), $this->metadata),
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
     * Apply the sensitive-data filter chain to one file.
     */
    private function preFilterValue(string $data): string
    {
        $config = Config::get('filter');
        foreach ($config['pre'] as $preFilterClass) {
            $data = $preFilterClass::filter($data);
        }
        return $data;
    }

    /**
     * Best-effort SpinYarn deobfuscation for one file.
     *
     * 上传阶段只做反混淆；识别失败、映射缺失或扩展异常都原样返回，
     * 不能因为反混淆失败阻断日志分享。
     */
    private function deobfuscateContent(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        try {
            $detected = (new Detective())->setLogFile(new StringLogFile($content))->detect();
            if (!$detected instanceof MinecraftLog) {
                return $content;
            }

            $detected->parse();
            if ($this->detectedLog === null) {
                $this->detectedLog = $detected;
            }

            $mappingType = $this->getMappingTypeFor($detected);
            if ($mappingType === null) {
                return $content;
            }

            $version = $detected->getVersion();
            if ($version === null) {
                return $content;
            }

            $deobfuscated = \App\Client\SpinYarnClient::deobfuscate($content, $version, $mappingType);
            return $deobfuscated ?? $content;
        } catch (\Throwable $e) {
            \App\Syslog::error('Log', 'Deobfuscation failed: ' . $e->getMessage());
            return $content;
        }
    }

    /**
     * Resolve the SpinYarn mapping type for a detected Codex log.
     *
     * @return string|null "yarn" (Fabric) / "vanilla" (Mojang official), or null to skip
     */
    private function getMappingTypeFor(\Aternos\Codex\Log\Log $log): ?string
    {
        if (in_array(get_class($log), [
            VanillaServerLog::class,
            VanillaClientLog::class,
            VanillaCrashReportLog::class,
            VanillaNetworkProtocolErrorReportLog::class,
        ], true)) {
            return 'vanilla';
        }

        if ($log instanceof FabricLog) {
            return 'yarn';
        }

        return null;
    }

    /**
     * 用上传期识别出的 Minecraft 版本与 Mod Loader 补全生态统计元数据。
     *
     * 只使用反混淆阶段已经识别出的第一份日志，不额外跑全文分析。
     * 客户端显式提交同名键时一律以客户端值为准。
     *
     * @param MetadataEntry[] $metadata
     * @return MetadataEntry[]
     */
    private function deriveEcosystemMetadata(array $metadata): array
    {
        if (!$this->detectedLog instanceof MinecraftLog || count($metadata) >= MetadataEntry::MAX_ENTRIES) {
            return $metadata;
        }

        /** @var array<string, array{value: string, label: string}> $derived */
        $derived = [];

        $version = $this->detectedLog->getVersion();
        if (is_string($version) && $version !== '') {
            $derived['version'] = ['value' => $version, 'label' => 'Minecraft 版本'];
        }

        $loader = $this->detectedLog->getNameId();
        if (is_string($loader) && $loader !== '') {
            $derived['loader'] = ['value' => $loader, 'label' => '模组加载器'];
        }

        foreach ($metadata as $entry) {
            unset($derived[$entry->getKey()]);
        }

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
     * 重复分析应命中缓存。缓存按文件内容而不是 ID 计算，避免不同文件互相污染。
     *
     * @var array<string, string>
     */
    private static array $analysisJsonCache = [];

    private const MAX_ANALYSIS_JSON_CACHE = 32;
    private const MAX_ANALYSIS_JSON_ENTRY_BYTES = 4 * 1024 * 1024;
    private const MAX_ANALYSIS_JSON_TOTAL_BYTES = 32 * 1024 * 1024;

    private static int $analysisJsonCacheBytes = 0;

    /**
     * Return the Codex analysis JSON for one stored file.
     */
    public function getFileAnalysisJson(string $name): string
    {
        $content = $this->getFile($name);
        if ($content === null) {
            throw new ApiError(404, "File not found.");
        }

        return $this->getAnalysisJsonForContent($content);
    }

    /**
     * Return Codex analysis JSON for an arbitrary content string.
     */
    public function getAnalysisJsonForContent(string $content): string
    {
        if ($content === '') {
            throw new ApiError(400, "File is empty.");
        }

        $key = md5($content);
        if (isset(self::$analysisJsonCache[$key])) {
            $json = self::$analysisJsonCache[$key];
            // LRU 语义：命中后重插到尾部，避免热条目被 FIFO 挤出
            unset(self::$analysisJsonCache[$key]);
            self::$analysisJsonCache[$key] = $json;
            return $json;
        }

        $codexLog = $this->analyseContent($content);
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
     * Detect, parse and analyse a piece of log content.
     */
    public function analyseContent(string $content): \Aternos\Codex\Log\Log
    {
        if ($content === '') {
            throw new ApiError(400, "File is empty.");
        }

        $detected = (new Detective())->setLogFile(new StringLogFile($content))->detect();
        if (!$detected instanceof \Aternos\Codex\Log\AnalysableLog) {
            throw new ApiError(500, 'Unsupported log type.');
        }
        $detected->parse();
        $detected->analyse();

        return $detected;
    }

    /**
     * Checks if the log exists.
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
     * Get the log id.
     */
    public function getId(): ?Id
    {
        return $this->id;
    }

    /**
     * Get the total line count across all files.
     */
    public function getLineNumbers(): int
    {
        $lines = 0;
        foreach ($this->files as $file) {
            $lines += substr_count($file['data'], "\n") + 1;
        }
        return $lines;
    }

    /**
     * Get the total size across all files.
     */
    public function getSize(): int
    {
        return array_sum(array_map(static fn(array $file): int => strlen($file['data']), $this->files));
    }

    /**
     * Renew the expiry timestamp to expand the ttl.
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
     * Delete the log from storage (both Redis cache and MariaDB).
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
            $this->files = [];
            $this->metadata = [];
            $this->token = null;
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
     * 检查该日志 id 是否带有删除墓碑标记。
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
     * Get the token.
     */
    public function getToken(): ?Token
    {
        return $this->token;
    }

    /**
     * Get the metadata.
     *
     * @return MetadataEntry[]
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get the source.
     */
    public function getSource(): ?string
    {
        return $this->source !== null && $this->source !== '' ? $this->source : '未指定';
    }

    /**
     * Get the list of raw files stored under this log id (including content data).
     *
     * @return array<int, array{name: string, data: string, size: int}>
     */
    public function getRawFiles(): array
    {
        return $this->files;
    }

    /**
     * Get the public file list.
     *
     * Each entry: ['name' => string, 'size' => int, 'lines' => int]
     *
     * @return array<int, array{name: string, size: int, lines: int}>
     */
    public function getFiles(): array
    {
        $files = array_map(static fn(array $file): array => [
            'name' => $file['name'],
            'size' => strlen($file['data']),
            'lines' => substr_count($file['data'], "\n") + 1,
        ], $this->files);
        // 缓存命中时保留上传顺序，数据库回源时按 name 排序；统一按名称排序，
        // 避免同一个 ID 在不同缓存状态下返回不同的文件顺序。
        usort($files, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $files;
    }

    /**
     * Get the raw content of a file by its name.
     */
    public function getFile(string $name): ?string
    {
        foreach ($this->files as $file) {
            if ($file['name'] === $name) {
                return $file['data'];
            }
        }
        return null;
    }

    /**
     * Check whether a file exists under this log id.
     */
    public function hasFile(string $name): bool
    {
        return $this->getFile($name) !== null;
    }

    /**
     * Get the line count of a file.
     *
     * @return int 0 if the file does not exist
     */
    public function getFileLineNumbers(string $name): int
    {
        $content = $this->getFile($name);
        return $content === null ? 0 : substr_count($content, "\n") + 1;
    }

    /**
     * Get the created timestamp.
     */
    public function getCreated(): ?int
    {
        return $this->created;
    }

    /**
     * Get the expires timestamp.
     */
    public function getExpires(): ?int
    {
        return $this->expires;
    }

    /**
     * Verify if the provided token matches.
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
     * 统一口径：所有文件字节总量不得超过 cache.maxSize。
     */
    private function shouldCacheToRedis(int $filesBytes): bool
    {
        $config = Config::Get('cache');
        $maxCacheSize = $config['maxSize'] ?? (5 * 1024 * 1024);
        return $filesBytes <= $maxCacheSize;
    }

    /**
     * 检查 Redis 缓存是否启用。
     */
    private function isCacheEnabled(): bool
    {
        $config = Config::Get('cache');
        return $config['enabled'] ?? true;
    }

    /**
     * 保存日志到 Redis 缓存。
     *
     * @param array<string, mixed> $data
     * @throws \Exception
     */
    private function saveToRedisCache(array $data): void
    {
        $cacheKey = $this->getRedisCacheKey();
        $config = Config::Get('cache');

        $cacheData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
     * 续期 Redis 缓存 TTL。
     *
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
     * 从 Redis 缓存删除日志。
     *
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
