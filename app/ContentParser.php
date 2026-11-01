<?php

namespace App;

use App\Data\MetadataEntry;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * Utility class for reading log content from the http request
 */
class ContentParser
{
    protected const int MAX_ENCODING_STEPS = 5;

    public function __construct(protected RequestInterface $request)
    {
    }

    /**
     * Get all supported content encodings
     * @return string[]
     */
    public static function getSupportedEncodings(): array
    {
        return ["deflate", "gzip", "x-gzip"];
    }

    /**
     * Get the content from the http request
     *
     * @return string|ApiError|array<string, mixed> The content string, an ApiError on failure, or array for JSON requests
     */
    public function getContent(): string|ApiError|array
    {
        $config = \App\Config::Get('storage');
        $limit = ((int) ($config['maxLength'] ?? (10 * 1024 * 1024))) * 2;

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
                break;
        }

        if (!isset($data['content'])) {
            return new ApiError(400, "Required POST argument 'content' not found.");
        }

        // content[]=x 之类会被 parse_str 解析为数组，提前拒绝避免下游类型混乱
        if (!is_string($data['content'])) {
            return new ApiError(400, "Field 'content' must be a string.");
        }

        if (empty($data['content'])) {
            return new ApiError(400, "Required POST argument 'content' is empty.");
        }

        return $data['content'];
    }

    /**
     * Parse JSON request data
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|ApiError
     */
    protected function parseJsonData(array $data): array|ApiError
    {
        $hasFiles = !empty($data['files']) && is_array($data['files']);

        if (!$hasFiles && !isset($data['content'])) {
            return new ApiError(400, "Required field 'content' not found.");
        }

        // When files are provided, content is optional (primary file is files[0])
        if (!isset($data['content'])) {
            $data['content'] = '';
        }

        if (empty($data['content']) && !$hasFiles) {
            return new ApiError(400, "Required field 'content' is empty.");
        }

        if (!is_string($data['content'])) {
            return new ApiError(400, "Field 'content' must be a string.");
        }

        $result = [
            'content' => $data['content'],
            'metadata' => [],
            'source' => null
        ];

        // Parse metadata if provided
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $result['metadata'] = MetadataEntry::allFromArray($data['metadata']);
        }

        // Parse source if provided, or fallback to User-Agent launcher format, otherwise '未指定'
        if (isset($data['source']) && is_string($data['source']) && trim($data['source']) !== '') {
            // 按字符截断：按字节截断会把中文启动器名切成非法 UTF-8，
            // 写 utf8mb4 时报 1366，或让 json_encode 直接失败。
            $result['source'] = mb_strcut(trim($data['source']), 0, 64, 'UTF-8');
        } else {
            try {
                $ua = $this->request->getHeaderLine('User-Agent');
                $launcher = static::parseLauncherSource($ua);
                $result['source'] = $launcher ?? '未指定';
            } catch (\Throwable) {
                $result['source'] = '未指定';
            }
        }

        // Parse optional log id (kept for request compatibility)
        if (isset($data['id']) && is_string($data['id'])) {
            $result['id'] = substr($data['id'], 0, 64);
        }

        // Parse additional files (multi-file uploads)
        if (isset($data['files']) && is_array($data['files'])) {
            $files = UploadParser::parseFiles($data['files']);
            if ($files instanceof ApiError) {
                return $files;
            }
            $result['files'] = $files;
        }

        return $result;
    }

    /**
     * 从 User-Agent 字符串中解析启动器来源标识（必须符合“启动器/版本”结构）。
     *
     * 过滤掉通用浏览器（Mozilla/Chrome/Safari等）、通用HTTP工具（curl/Postman/okhttp等）
     * 以及非“名称/版本”结构的普通UA。
     *
     * @param string|null $userAgent
     * @return string|null 合法的启动器来源标识（≤64字符），若不符合则返回 null
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
