<?php

namespace App\Storage;

use App\Data\Token;

class FilesystemStorage implements StorageInterface
{
    /** 单次 CleanupExpired 最多检查的文件数，限制请求路径内的清理工作量 */
    private const CLEANUP_BATCH = 500;

    /** 分配新 ID 的最大重试次数（O_EXCL 占位冲突上限） */
    private const MAX_ID_ATTEMPTS = 8;

    /**
     * @param \App\Data\MetadataEntry[] $metadata
     * @param array<int, array{name: string, data?: string, size?: int}>|null $files
     */
    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null, ?bool &$created = null): ?\App\Id
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];

        if (!is_writable($basePath)) {
            throw new \Exception("Filesystem storage driver could not write to " . $basePath . ". Please check if the directory exists and is writable.");
        }

        $id = new \App\Id();
        $id->setStorage("f");

        // 用 O_EXCL 语义（fopen 'x'）原子占位，防止并发下 rename 静默覆盖已有日志。
        // 必须设重试上限：目录不可写、inode 耗尽或磁盘满时，无界循环会把 worker 打死。
        $placeholder = false;
        for ($attempt = 0; $attempt < self::MAX_ID_ATTEMPTS; $attempt++) {
            $id->regenerate();
            $path = $basePath . $id->getRaw();
            $placeholder = @fopen($path, 'x');
            if ($placeholder !== false) {
                break;
            }
        }
        if ($placeholder === false) {
            throw new \RuntimeException("Filesystem storage could not allocate a free log file under {$basePath}; check directory permissions and free space.");
        }
        fclose($placeholder);
        $created = true;

        $document = [
            'data' => $data,
            'created' => time(),
        ];

        if ($token !== null) {
            $document['token'] = $token->get();
        }

        if (!empty($metadata)) {
            $document['metadata'] = array_map(fn($entry) => $entry->jsonSerialize(), $metadata);
        }

        $document['source'] = ($source !== null && trim($source) !== '')
            ? mb_strcut(trim($source), 0, 64, 'UTF-8')
            : '未指定';

        if (!empty($files)) {
            $document['files'] = array_values(array_map(
                fn($file) => [
                    'name' => $file['name'],
                    'data' => $file['data'] ?? '',
                    'size' => strlen($file['data'] ?? ''),
                ],
                $files
            ));
        }

        try {
            self::writeAtomically($path, $document);
        } catch (\Throwable $e) {
            // 占位文件已存在，写正文失败时清干净，避免留下空文件
            @unlink($path);
            @unlink($path . '.meta.json');
            throw $e;
        }
        self::writeAtomically($path . '.meta.json', [
            'created' => $document['created'],
            'source' => $document['source'],
            'filesCount' => count($document['files'] ?? []),
        ]);
        return $id;
    }

    /**
     * 把遗留的无前缀 token 哈希补齐 `sha256:` 前缀（惰性迁移用）。
     */
    public static function UpgradeToken(\App\Id $id, string $normalizedToken): bool
    {
        $config = \App\Config::Get("filesystem");
        $path = CORE_PATH . $config['path'] . $id->getRaw();

        if (!is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return false;
        }
        $document = json_decode($raw, true);
        if (!is_array($document)) {
            return false;
        }
        if (isset($document['token']) && is_string($document['token'])
            && !str_starts_with($document['token'], \App\Data\Token::HASH_PREFIX)) {
            $document['token'] = $normalizedToken;
            self::writeAtomically($path, $document);
        }
        return true;
    }

    /**
     * @param array<int, array{name: string, data?: string, size?: int}>|null $files
     */
    public static function Update(\App\Id $id, string $data, ?array $files = null): bool
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];
        $path = $basePath . $id->getRaw();

        if (!is_file($path)) {
            return false;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return false;
        }

        $document = json_decode($raw, true);
        if (!is_array($document)) {
            return false;
        }

        $document['data'] = $data;
        if ($files !== null) {
            $document['files'] = array_values(array_map(
                fn($file) => [
                    'name' => $file['name'],
                    'data' => $file['data'] ?? '',
                    'size' => strlen($file['data'] ?? ''),
                ],
                $files
            ));
        }

        self::writeAtomically($path, $document);

        // 同步更新 .meta.json
        $metaPath = $path . '.meta.json';
        if (is_file($metaPath)) {
            $metaRaw = @file_get_contents($metaPath);
            $metaDoc = $metaRaw ? json_decode($metaRaw, true) : [];
            if (is_array($metaDoc)) {
                $metaDoc['filesCount'] = count($document['files'] ?? []);
                self::writeAtomically($metaPath, $metaDoc);
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function Get(\App\Id $id, bool $includeContent = true): ?array
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];

        if (!file_exists($basePath . $id->getRaw())) {
            return null;
        }

        $content = file_get_contents($basePath . $id->getRaw());
        if ($content === false) {
            return null;
        }

        $document = json_decode($content, true);
        if ($document === null || !is_array($document)) {
            return null;
        }

        return [
            'data' => $document['data'] ?? null,
            'token' => $document['token'] ?? null,
            'metadata' => $document['metadata'] ?? [],
            'source' => (isset($document['source']) && $document['source'] !== '') ? (string) $document['source'] : '未指定',
            'created' => self::readCreated($basePath . $id->getRaw(), $document),
            // 与 MariaDbStorage 对称：includeContent=false 仅剥离附加文件内容
            'files' => self::normalizeFiles($document['files'] ?? [], $includeContent),
        ];
    }

    /**
     * @param array<string, mixed>|null $document
     */
    private static function readCreated(string $path, ?array $document): ?int
    {
        $meta = $path . '.meta.json';
        if (is_file($meta)) {
            $value = json_decode((string) file_get_contents($meta), true);
            if (is_array($value) && isset($value['created'])) {
                return (int) $value['created'];
            }
        }
        return isset($document['created']) ? (int) $document['created'] : null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function writeAtomically(string $path, array $document): bool
    {
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \Exception('Failed to write log file.');
        }
        return true;
    }

    public static function Renew(\App\Id $id): bool
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];
        $path = $basePath . $id->getRaw();

        if (!file_exists($path)) {
            return false;
        }

        $metaPath = $path . '.meta.json';
        $metadata = ['created' => time()];
        if (file_exists($metaPath)) {
            $content = file_get_contents($metaPath);
            $metadata = $content === false ? [] : json_decode($content, true);
            if (!is_array($metadata)) {
                $metadata = [];
            }
        }
        $metadata['created'] = time();

        $written = self::writeAtomically($metaPath, $metadata);

        // 复核主文件存在性（防御写入期间并发 Delete 导致的孤儿 .meta.json）
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            @unlink($metaPath);
            return false;
        }

        return $written;
    }

    /**
     * Delete expired log files. A document's `created` timestamp is resolved
     * the same way as in `Get()` (`.meta.json` first — written/updated by
     * `Renew()` — then the embedded field, then file mtime), so renewed logs
     * are not treated as expired. Processes at most self::CLEANUP_BATCH files
     * per call to bound the work done inside a request.
     *
     * @return int Number of deleted files
     */
    public static function CleanupExpired(): int
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];

        $storageConfig = \App\Config::Get('storage');
        $storageTime = (int) ($storageConfig['storageTime'] ?? (7 * 24 * 60 * 60));
        $now = time();
        $deleted = 0;

        if (!is_dir($basePath)) {
            return 0;
        }

        $files = glob($basePath . '*') ?: [];
        foreach (array_slice($files, 0, self::CLEANUP_BATCH) as $file) {
            if (!is_file($file)) {
                continue;
            }

            $content = @file_get_contents($file);
            $document = $content !== false ? json_decode($content, true) : null;
            $created = self::readCreated($file, is_array($document) ? $document : null)
                ?? @filemtime($file);

            if ($created > 0 && $created + $storageTime < $now) {
                @unlink($file);
                @unlink($file . '.meta.json');
                $deleted++;
            }
        }

        return $deleted;
    }

    public static function Delete(\App\Id $id): bool
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];
        $path = $basePath . $id->getRaw();

        if (!file_exists($path)) {
            return false;
        }

        $deleted = unlink($path);
        // 主文档删除成功时同步清理伴随的元数据文件，避免残留孤儿
        @unlink($path . '.meta.json');
        return $deleted;
    }

    /**
     * Normalize file entries; drops `data` when $includeContent is false.
     */
    /**
     * @param array<int|string, mixed> $files
     * @return array<int, array{name: string, data?: string, size?: int}>
     */
    private static function normalizeFiles(array $files, bool $includeContent): array
    {
        $result = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $entry = [
                'name' => $file['name'] ?? '',
                'size' => isset($file['size']) ? (int) $file['size'] : strlen($file['data'] ?? ''),
            ];
            if ($includeContent) {
                $entry['data'] = $file['data'] ?? '';
            }
            $result[] = $entry;
        }
        return $result;
    }

    public static function List(int $limit = 20, int $offset = 0, ?string $source = null, ?int $since = null, ?int $until = null, ?string $keyword = null): array
    {
        $entries = self::scanEntries($source, $since, $until, $keyword);
        return array_slice($entries, $offset, $limit);
    }

    public static function Count(?string $source = null, ?int $since = null, ?int $until = null, ?string $keyword = null): int
    {
        // Count 与 List 的过滤逻辑完全一致；一次扫描后复用同一实现，
        // 避免管理端每次翻页都做两遍「scandir + 逐文件读 meta」。
        return count(self::scanEntries($source, $since, $until, $keyword));
    }

    /**
     * 扫描目录并返回符合过滤条件的条目（按 created 倒序）。
     *
     * List 与 Count 共用，避免两处逻辑漂移与重复 IO。
     *
     * @return array<int, array{id: string, size: int, source: string, created: int, filesCount: int}>
     */
    private static function scanEntries(?string $source, ?int $since, ?int $until, ?string $keyword): array
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . ($config['path'] ?? '/storage/');
        if (!is_dir($basePath)) {
            return [];
        }

        $files = scandir($basePath);
        if ($files === false) {
            return [];
        }

        $rawKeyword = $keyword;
        if ($keyword !== null && $keyword !== '') {
            try {
                $parsedId = new \App\Id($keyword);
                $rawKeyword = $parsedId->getRaw();
            } catch (\Throwable) {
                // Not a valid full ID
            }
        }

        $entries = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || str_ends_with($file, '.meta.json')) {
                continue;
            }

            if ($keyword !== null && $keyword !== '') {
                if (!str_contains($file, $rawKeyword ?? $keyword)) {
                    continue;
                }
            }

            $metaPath = $basePath . $file . '.meta.json';
            $metaData = file_exists($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
            $created = is_array($metaData) && isset($metaData['created']) ? (int) $metaData['created'] : (int) filemtime($basePath . $file);

            if ($since !== null && $created < $since) {
                continue;
            }
            if ($until !== null && $created > $until) {
                continue;
            }

            $itemSource = is_array($metaData) && isset($metaData['source']) && (string) $metaData['source'] !== ''
                ? (string) $metaData['source']
                : null;
            if ($itemSource === null) {
                $rawContent = @file_get_contents($basePath . $file);
                if ($rawContent !== false) {
                    $doc = json_decode($rawContent, true);
                    if (is_array($doc) && isset($doc['source']) && (string) $doc['source'] !== '') {
                        $itemSource = (string) $doc['source'];
                    }
                }
            }
            $itemSource = $itemSource ?? '未指定';

            if ($source !== null && $source !== '') {
                if ($source === '未指定') {
                    if ($itemSource !== '未指定') {
                        continue;
                    }
                } else {
                    if ($itemSource !== $source) {
                        continue;
                    }
                }
            }

            $fullId = \App\Id::fromRaw('f', $file)->get();
            $fileSize = filesize($basePath . $file);
            $filesCount = is_array($metaData) && isset($metaData['filesCount']) ? (int) $metaData['filesCount'] : 0;

            $entries[] = [
                'id' => $fullId,
                'size' => $fileSize !== false ? $fileSize : 0,
                'source' => $itemSource,
                'created' => $created,
                'filesCount' => $filesCount,
            ];
        }

        usort($entries, fn($a, $b) => $b['created'] <=> $a['created']);
        return $entries;
    }
}
