<?php

namespace App;

use App\Data\MetadataEntry;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * Utility class for reading a log upload from the HTTP request.
 *
 * 上传契约只有文件列表：`files[] = {name, content}`。不再接受 `content`
 * 主日志字段，也不支持单独的“正文”。
 */
class ContentParser
{
    protected const int MAX_ENCODING_STEPS = 5;

    public function __construct(protected RequestInterface $request)
    {
    }

    /**
     * Get all supported content encodings.
     *
     * @return string[]
     */
    public static function getSupportedEncodings(): array
    {
        return ["deflate", "gzip", "x-gzip"];
    }

    /**
     * Get the upload payload from the HTTP request.
     *
     * @return array<string, mixed>|ApiError
     */
    public function getContent(): array|ApiError
    {
        $config = \App\Config::Get('storage');
        $uploadConfig = $config['uploadFiles'] ?? [];
        $limit = (int) ($uploadConfig['maxRequestBodyBytes'] ?? (32 * 1024 * 1024));

        $body = $this->request->getBody()->getContents();
        if (strlen($body) > $limit) {
            return new ApiError(413, "Request body exceeds maximum allowed size.");
        }

        $encodingHeader = $this->request->getHeaderLine('Content-Encoding');
        if ($encodingHeader !== '') {
            $encodingSteps = explode(',', $encodingHeader);
            if (count($encodingSteps) > static::MAX_ENCODING_STEPS) {
                return new ApiError(400, "Too many Content-Encoding steps.");
            }
            foreach (array_reverse($encodingSteps) as $step) {
                switch (trim(strtolower($step))) {
                    case "deflate":
                        $body = @gzinflate($body, $limit);
                        break;
                    case "x-gzip":
                    case "gzip":
                        $body = @gzdecode($body, $limit);
                        break;
                    case "br":
                        if (!function_exists('brotli_uncompress')) {
                            return new ApiError(501, "Brotli decompression is not supported on this server.");
                        }
                        try {
                            $uncompressed = @brotli_uncompress($body);
                            if ($uncompressed === false || ($limit > 0 && strlen($uncompressed) > $limit)) {
                                $body = false;
                            } else {
                                $body = $uncompressed;
                            }
                        } catch (\Throwable) {
                            $body = false;
                        }
                        break;
                    default:
                        return new ApiError(415, "Unsupported Content-Encoding: " . htmlspecialchars($step));
                }
                if ($body === false) {
                    return new ApiError(400, "Failed to decode request body with encoding: " . htmlspecialchars($step));
                }
            }
        }

        $contentTypeHeader = $this->request->getHeaderLine('Content-Type');
        if ($pos = strpos($contentTypeHeader, ';')) {
            $contentTypeHeader = substr($contentTypeHeader, 0, $pos);
        }
        $contentTypeHeader = trim($contentTypeHeader);

        switch ($contentTypeHeader) {
            case "application/json":
                $data = @json_decode($body, true);
                if (!is_array($data)) {
                    return new ApiError(400, "Failed to parse JSON body.");
                }
                return $this->parseJsonData($data);

            case "application/x-www-form-urlencoded":
            default:
                parse_str($body, $data);
                return $this->parseJsonData($data);
        }
    }

    /**
     * 解析上传字段（files / content / metadata / source）。
     *
     * `content` 是旧版单文件上传契约：继续接受并映射为 `latest.log`，
     * 这样已对接旧上传接口的客户端不需要迁移。新客户端仍应只提交 `files`。
     *
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>|ApiError
     */
    protected function parseJsonData(array $data): array|ApiError
    {
        $hasContent = array_key_exists('content', $data);
        $hasFiles = isset($data['files']) && is_array($data['files']) && $data['files'] !== [];

        if (array_key_exists('files', $data) && $data['files'] !== null && !is_array($data['files'])) {
            return new ApiError(400, "Field 'files' must be an array.");
        }

        if (!$hasContent && !$hasFiles) {
            return new ApiError(400, "Required field 'files' not found or empty.");
        }

        $legacyContent = '';
        if ($hasContent) {
            if (!is_string($data['content'])) {
                return new ApiError(400, "Field 'content' must be a string.");
            }
            $legacyContent = $data['content'];
            if ($legacyContent === '' && !$hasFiles) {
                return new ApiError(400, "Required field 'content' is empty.");
            }
        }

        $files = [];
        if ($hasFiles) {
            $files = UploadParser::parseFiles($data['files']);
            if ($files instanceof ApiError) {
                return $files;
            }
        }

        $mainFile = null;
        if ($legacyContent !== '') {
            $maxFiles = (int) (\App\Config::GetPath('storage.uploadFiles.maxFiles', UploadParser::DEFAULT_MAX_FILES));
            if (count($files) >= $maxFiles) {
                return new ApiError(413, "Too many files in upload. Maximum is {$maxFiles}.");
            }

            $mainFile = self::legacyMainFileName($files);
            array_unshift($files, [
                'name' => $mainFile,
                'data' => $legacyContent,
                'size' => strlen($legacyContent),
            ]);
        }

        if ($files === []) {
            return new ApiError(400, "Required field 'files' not found or empty.");
        }

        $result = [
            'files' => $files,
            'metadata' => [],
            'source' => null,
        ];
        if ($mainFile !== null) {
            $result['mainFile'] = $mainFile;
        }

        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $result['metadata'] = MetadataEntry::allFromArray($data['metadata']);
        }

        if (isset($data['source']) && is_string($data['source']) && trim($data['source']) !== '') {
            // 按字符截断：按字节截断会把中文启动器名切成非法 UTF-8，
            // 写 utf8mb4 时报 1366，或让 json_encode 直接失败。
            $result['source'] = mb_substr(trim($data['source']), 0, 64, 'UTF-8');
        } else {
            try {
                $ua = $this->request->getHeaderLine('User-Agent');
                $launcher = static::parseLauncherSource($ua);
                $result['source'] = $launcher ?? '未指定';
            } catch (\Throwable) {
                $result['source'] = '未指定';
            }
        }

        return $result;
    }

    /**
     * 旧版 content 字段映射后的文件名，避免与已提交的 files 重名。
     *
     * @param array<int, array{name: string, data?: string, size?: int}> $files
     */
    private static function legacyMainFileName(array $files): string
    {
        $taken = [];
        foreach ($files as $file) {
            $taken[(string) $file['name']] = true;
        }

        foreach (['latest.log', 'main.log'] as $candidate) {
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }

        for ($i = 2; $i < 1000; $i++) {
            $candidate = "latest ({$i}).log";
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return 'legacy-' . bin2hex(random_bytes(4)) . '.log';
    }

    /**
     * 从 User-Agent 字符串中解析启动器来源标识（必须符合“启动器/版本”结构）。
     *
     * 过滤掉通用浏览器（Mozilla/Chrome/Safari等）、通用HTTP工具（curl/Postman/okhttp等）
     * 以及非“名称/版本”结构的普通UA。
     */
    public static function parseLauncherSource(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $ua = trim($userAgent);
        if ($ua === '' || strlen($ua) > 64) {
            return null;
        }

        // 必须严格符合 "启动器名称/版本号" 单段格式（中间仅一个斜杠，无空格或多层段）
        // 名称与版本允许字母、数字、点、下划线、短横线、加号（兼容 semver build metadata）
        if (!preg_match('/^[\p{L}\p{N}_-]+\/[\p{L}\p{N}_.+\-]+$/u', $ua)) {
            return null;
        }

        [$name] = explode('/', $ua, 2);
        $lowerName = strtolower($name);

        // 排除常见通用浏览器、爬虫及通用 HTTP 客户端黑名单
        $blacklist = [
            'mozilla',
            'chrome',
            'safari',
            'firefox',
            'opera',
            'edge',
            'webkit',
            'gecko',
            'curl',
            'wget',
            'postman',
            'postmanruntime',
            'okhttp',
            'python',
            'python-requests',
            'go-http-client',
            'apache-httpclient',
            'java',
            'axios',
            'node-fetch',
            'undici',
            'insomnia',
            'httpie',
            'rest-client',
            'dalvik',
        ];

        if (in_array($lowerName, $blacklist, true)) {
            return null;
        }

        return $ua;
    }
}
