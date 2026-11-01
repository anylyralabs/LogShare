<?php

namespace App;

/**
 * Parses and validates uploaded log files, including ZIP archive expansion.
 */
class UploadParser
{
    public const DEFAULT_MAX_FILES = 200;
    public const DEFAULT_MAX_TOTAL_BYTES = 20 * 1024 * 1024;
    /** 与 MariaDB `log_files.name` VARCHAR(512) 及常见路径长度上限对齐 */
    public const MAX_NAME_LENGTH = 512;

    /** ZIP 流式解压的读取块大小（限制单个条目的峰值内存） */
    private const ZIP_READ_CHUNK = 65536;

    /**
     * Validate a file name for path traversal attacks.
     *
     * Rejects empty names, NUL bytes, absolute paths and any path segment
     * that is ".", ".." or empty. Directory separators are normalized to "/".
     *
     * @param string $name
     * @return bool
     */
    public static function validateFileName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }

        // 超长名称在 MariaDB 路径会触发 "Data too long" 500，这里统一提前拒绝
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            return false;
        }

        // 过滤不可见控制字符（包括 \r、\n 等），防范响应头拆分与异常文件名
        if (preg_match('/[\x00-\x1f\x7f]/', $name)) {
            return false;
        }

        $name = str_replace('\\', '/', $name);

        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse the raw files array from a request into normalized entries.
     *
     * Input format:  [['name' => string, 'content' => string], ...]
     * Output format: [['name' => string, 'data' => string], ...]
     *
     * ZIP archives are expanded into their entries. Limits (max files and
     * total size) are enforced across the whole expanded upload.
     *
     * @param array<int, mixed>|null $files
     * @return array<int, array{name: string, data?: string, size?: int}>|ApiError
     */
    public static function parseFiles(?array $files): array|ApiError
    {
        if ($files === null || $files === []) {
            return [];
        }

        $storageConfig = Config::Get('storage');
        $uploadConfig = $storageConfig['uploadFiles'] ?? [];
        $maxFiles = (int) ($uploadConfig['maxFiles'] ?? self::DEFAULT_MAX_FILES);
        $maxTotalBytes = (int) ($uploadConfig['maxTotalBytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES);

        $result = [];
        $totalBytes = 0;
        /** @var array<string, true> $taken */
        $taken = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                return new ApiError(400, "Each file entry must be an object.");
            }

            $name = $file['name'] ?? '';
            $content = $file['content'] ?? $file['data'] ?? '';

            if (!is_string($name) || !is_string($content)) {
                return new ApiError(400, "File name and content must be strings.");
            }

            if (self::isZipName($name)) {
                $remainingSlots = $maxFiles - count($result);
                $remainingBudget = $maxTotalBytes - $totalBytes;
                $expanded = self::expandZip($name, $content, $remainingBudget, $remainingSlots, $maxFiles);
                if ($expanded instanceof ApiError) {
                    return $expanded;
                }
                foreach ($expanded as $entry) {
                    $entry['name'] = self::uniqueName($entry['name'], $taken);
                    $result[] = $entry;
                    $totalBytes += strlen($entry['data'] ?? '');
                }
            } else {
                if (count($result) >= $maxFiles) {
                    return new ApiError(413, "Too many files in upload. Maximum is {$maxFiles}.");
                }
                if ($totalBytes + strlen($content) > $maxTotalBytes) {
                    return new ApiError(413, "Upload exceeds maximum total size of {$maxTotalBytes} bytes.");
                }
                if (!self::validateFileName($name)) {
                    return new ApiError(400, "Invalid file name: " . mb_strcut($name, 0, 64));
                }
                $result[] = ['name' => self::uniqueName($name, $taken), 'data' => $content];
                $totalBytes += strlen($content);
            }

            if (count($result) > $maxFiles) {
                return new ApiError(413, "Too many files in upload. Maximum is {$maxFiles}.");
            }
            if ($totalBytes > $maxTotalBytes) {
                return new ApiError(413, "Upload exceeds maximum total size of {$maxTotalBytes} bytes.");
            }
        }

        return $result;
    }

    private static function isZipName(string $name): bool
    {
        return str_ends_with(strtolower($name), '.zip');
    }

    /**
     * 为同名条目生成不冲突的名称。
     *
     * `log_files` 主键为 (log_id, name, created)，同名条目直接触发
     * Duplicate entry 并把上传打成 500。这里按 `name` / `name (2)` /
     * `name (3)` 递增，保留扩展名位置，行为可预期且便于客户端识别。
     *
     * @param array<string, true> $taken 已占用名称集合（引用传递，调用后写入新名称）
     */
    private static function uniqueName(string $name, array &$taken): string
    {
        if (!isset($taken[$name])) {
            $taken[$name] = true;
            return $name;
        }

        $dot = strrpos($name, '.');
        $slash = strrpos($name, '/');
        $hasExtension = $dot !== false && ($slash === false || $dot > $slash);
        $base = $hasExtension ? substr($name, 0, $dot) : $name;
        $extension = $hasExtension ? substr($name, $dot) : '';

        for ($i = 2; $i < 1000; $i++) {
            $candidate = $base . ' (' . $i . ')' . $extension;
            if (strlen($candidate) > self::MAX_NAME_LENGTH) {
                // 加后缀后超长：退化为截断基名，仍要保证唯一
                $keep = max(1, self::MAX_NAME_LENGTH - strlen($extension) - strlen(' (' . $i . ')'));
                $candidate = mb_strcut($base, 0, $keep, 'UTF-8') . ' (' . $i . ')' . $extension;
                if (isset($taken[$candidate])) {
                    continue;
                }
            }
            if (!isset($taken[$candidate])) {
                $taken[$candidate] = true;
                return $candidate;
            }
        }

        // 极端兜底：附加随机后缀，仍保证不冲突
        $candidate = mb_strcut($base, 0, 200, 'UTF-8') . '-' . bin2hex(random_bytes(4)) . $extension;
        $taken[$candidate] = true;
        return $candidate;
    }

    /**
     * Expand a ZIP archive provided as a raw byte string into file entries.
     *
     * @param string $zipName Original file name of the archive
     * @param string $zipData Raw ZIP bytes
     * @param int $remainingBudget Remaining total size budget in bytes
     * @param int $remainingSlots Remaining file count budget
     * @param int $maxFiles Configured file limit (for error messages)
     * @return array<int, array{name: string, data?: string, size?: int}>|ApiError
     */
    private static function expandZip(string $zipName, string $zipData, int $remainingBudget, int $remainingSlots, int $maxFiles): array|ApiError
    {
        $tmpDir = CORE_PATH . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0700, true);
        }

        $tmpFile = $tmpDir . '/upload_' . bin2hex(random_bytes(8)) . '.zip';
        if (@file_put_contents($tmpFile, $zipData) === false) {
            return new ApiError(500, "Failed to process upload archive.");
        }

        $zip = new \ZipArchive();
        $result = [];
        $opened = false;

        try {
            $openResult = $zip->open($tmpFile);
            if ($openResult !== true) {
                return new ApiError(400, "Invalid ZIP archive: " . mb_strcut($zipName, 0, 64));
            }
            $opened = true;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if ($entryName === false) {
                    continue;
                }
                if (str_ends_with($entryName, '/')) {
                    continue;
                }
                if (!self::validateFileName($entryName)) {
                    return new ApiError(400, "Invalid file name in archive: " . mb_strcut($entryName, 0, 64));
                }

                // 文件数配额先于解压检查，避免白耗一次解压
                if ($remainingSlots <= 0) {
                    return new ApiError(413, "Too many files in upload. Maximum is {$maxFiles}.");
                }

                // 先按声明大小快速拒绝，再用流式读取分块累计。
                // getFromIndex() 会一次性分配整条解压结果，central directory 的
                // 声明 size 被篡改时复检已经太晚（内存已分配）；流式读取把
                // 峰值内存压到单块大小，超预算立即中止。
                $stat = $zip->statIndex($i);
                $declaredSize = is_array($stat) ? $stat['size'] : 0;
                if ($declaredSize > $remainingBudget) {
                    return new ApiError(413, "Expanded upload exceeds maximum total size.");
                }

                $stream = $zip->getStream($entryName);
                if ($stream === false) {
                    continue;
                }

                $entryContent = '';
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, self::ZIP_READ_CHUNK);
                        if ($chunk === false) {
                            break;
                        }
                        $entryContent .= $chunk;
                        if (strlen($entryContent) > $remainingBudget) {
                            return new ApiError(413, "Expanded upload exceeds maximum total size.");
                        }
                    }
                } finally {
                    fclose($stream);
                }

                $result[] = ['name' => $entryName, 'data' => $entryContent];
                $remainingBudget -= strlen($entryContent);
                $remainingSlots--;
            }

            return $result;
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($tmpFile);
        }
    }
}