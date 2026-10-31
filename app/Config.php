<?php

namespace App;

class Config
{
    private static array $data = [];
    private static bool $loaded = false;
    private static int $dynamicMtime = 0;
    private static int $dynamicSize = -1;
    private static string $dynamicVersion = '';
    private static string $baseConfigPath = '';
    private static bool $checkingFresh = false;

    public static function load(string $path): void
    {
        self::$baseConfigPath = $path;

        $example = CORE_PATH . '/Config.inc.example.php';
        $defaultData = [];
        if (is_file($example)) {
            $defaultData = require $example;
            if (!is_array($defaultData)) {
                $defaultData = [];
            }
        }

        if (!is_file($path)) {
            if (!empty($defaultData)) {
                $data = $defaultData;
            } else {
                self::$loaded = true;
                return;
            }
        } else {
            $userConfig = require $path;
            if (!is_array($userConfig)) {
                throw new \InvalidArgumentException("Config file {$path} must return an array.");
            }
            $data = !empty($defaultData) ? self::deepMerge($defaultData, $userConfig) : $userConfig;
        }

        self::applyEnvironmentOverrides($data);
        self::applyDynamicOverrides($data);
        // Example 配置中的占位符（如 '${REDIS_PASSWORD}'）在非 Docker 部署下不会
        // 被替换，占位符形态一律视为未配置，避免拿字面量去 AUTH Redis
        if (isset($data['cache']['redis']['password'])
            && is_string($data['cache']['redis']['password'])
            && preg_match('/^\$\{[A-Z_]+\}$/', $data['cache']['redis']['password']) === 1) {
            $data['cache']['redis']['password'] = '';
        }
        self::validate($data);

        $dynamicPath = self::getDynamicConfigPath();
        clearstatcache(true, $dynamicPath);
        self::$dynamicMtime = is_file($dynamicPath) ? (filemtime($dynamicPath) ?: 0) : 0;
        self::$dynamicSize = is_file($dynamicPath) ? (filesize($dynamicPath) ?: 0) : -1;

        self::$data = $data;
        self::$loaded = true;
    }

    /**
     * Allow environment variables to override configuration values.
     *
     * Supported variables:
     *  - REDIS_HOST         → cache.redis.host
     *  - REDIS_PORT         → cache.redis.port
     *  - REDIS_TIMEOUT      → cache.redis.timeout
     *  - REDIS_PASSWORD     → cache.redis.password
     *  - AI_API_KEYS        → ai.apiKeys (comma-separated)
     *  - AI_BASE_URL        → ai.baseUrl
     *  - AI_MODEL           → ai.model
     *  - AI_ENABLED         → ai.enabled (1/true/on/yes = true)
     *
     * @param array $data Config array by reference
     * @return void
     */
    private static function applyEnvironmentOverrides(array &$data): void
    {
        if (($storageTime = getenv('STORAGE_TIME')) !== false && ctype_digit($storageTime) && (int) $storageTime > 0) {
            $data['storage']['storageTime'] = (int) $storageTime;
        }
        if ($host = getenv('REDIS_HOST')) {
            $data['cache']['redis']['host'] = $host;
        }
        if ($port = getenv('REDIS_PORT')) {
            $data['cache']['redis']['port'] = (int) $port;
        }
        if ($timeout = getenv('REDIS_TIMEOUT')) {
            $data['cache']['redis']['timeout'] = (float) $timeout;
        }
        if (($password = getenv('REDIS_PASSWORD')) !== false && $password !== '') {
            $data['cache']['redis']['password'] = $password;
        }
        if ($keys = getenv('AI_API_KEYS')) {
            $data['ai']['apiKeys'] = array_values(array_filter(array_map('trim', explode(',', $keys)), fn($k) => $k !== ''));
        }
        if ($baseUrl = getenv('AI_BASE_URL')) {
            $data['ai']['baseUrl'] = $baseUrl;
        }
        if ($model = getenv('AI_MODEL')) {
            $data['ai']['model'] = $model;
        }
        if (($enabled = getenv('AI_ENABLED')) !== false) {
            $data['ai']['enabled'] = in_array(strtolower($enabled), ['1', 'true', 'on', 'yes'], true);
        }
        if (($customHeaders = getenv('AI_HEADERS')) !== false && $customHeaders !== '') {
            $decoded = json_decode($customHeaders, true);
            if (is_array($decoded)) {
                $data['ai']['headers'] = $decoded;
            }
        }
        if (empty($data['ai']['apiKeys']) || !$data['ai']['enabled']) {
            $data['ai']['enabled'] = false;
        }

        if (($adminEnabled = getenv('ADMIN_ENABLED')) !== false) {
            $data['admin']['enabled'] = in_array(strtolower($adminEnabled), ['1', 'true', 'on', 'yes'], true);
        }
        if ($adminToken = getenv('ADMIN_TOKEN')) {
            $data['admin']['token'] = $adminToken;
        }

        // GitHub 排障工具配置覆盖
        if (!isset($data['github']) || !is_array($data['github'])) {
            $data['github'] = [];
        }
        if (($ghEnabled = getenv('GITHUB_ENABLED')) !== false) {
            $data['github']['enabled'] = in_array(strtolower($ghEnabled), ['1', 'true', 'on', 'yes'], true);
        }
        if ($ghTokens = getenv('GITHUB_TOKENS')) {
            $data['github']['tokens'] = array_values(array_filter(array_map('trim', explode(',', $ghTokens)), fn($k) => $k !== ''));
        } elseif ($ghToken = getenv('GITHUB_TOKEN')) {
            $data['github']['tokens'] = [$ghToken];
        }
        if ($ghProxy = getenv('GITHUB_PROXY')) {
            $data['github']['proxy'] = $ghProxy;
        }
        if (($ghCacheTtl = getenv('GITHUB_CACHE_TTL')) !== false && ctype_digit($ghCacheTtl)) {
            $data['github']['cache_ttl'] = (int) $ghCacheTtl;
        }
        // 若配置了旧版单个 token 则归一化进 tokens 列表
        if (isset($data['github']['token']) && is_string($data['github']['token']) && $data['github']['token'] !== '') {
            if (!isset($data['github']['tokens']) || !is_array($data['github']['tokens'])) {
                $data['github']['tokens'] = [];
            }
            if (!in_array($data['github']['token'], $data['github']['tokens'], true)) {
                array_unshift($data['github']['tokens'], $data['github']['token']);
            }
        }
    }

    private static function validate(array $data): void
    {
        $storage = $data['storage'] ?? [];
        $storageTime = (int) ($storage['storageTime'] ?? 0);
        if ($storageTime <= 0) {
            throw new \InvalidArgumentException('storage.storageTime must be greater than zero');
        }

        $cache = $data['cache'] ?? [];
        $redis = $cache['redis'] ?? [];
        if (($redis['host'] ?? '') === '' || (int) ($redis['port'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('cache.redis host and port are required');
        }

        $ai = $data['ai'] ?? [];
        if (($ai['enabled'] ?? false) === true) {
            if (empty($ai['apiKeys']) || !is_array($ai['apiKeys'])) {
                throw new \InvalidArgumentException('AI_API_KEYS is required when AI is enabled');
            }
            if (!filter_var($ai['baseUrl'] ?? '', FILTER_VALIDATE_URL) || ($ai['model'] ?? '') === '') {
                throw new \InvalidArgumentException('AI_BASE_URL and AI_MODEL are required when AI is enabled');
            }
        }

        $admin = $data['admin'] ?? [];
        if (($admin['enabled'] ?? false) === true) {
            if (empty($admin['token']) || !is_string($admin['token'])) {
                throw new \InvalidArgumentException('admin.token is required when admin is enabled');
            }
        }

        $github = $data['github'] ?? [];
        if (($github['enabled'] ?? false) === true) {
            if (isset($github['tokens']) && !is_array($github['tokens'])) {
                throw new \InvalidArgumentException('github.tokens must be an array');
            }
            if (isset($github['timeout']) && ((int) $github['timeout'] <= 0)) {
                throw new \InvalidArgumentException('github.timeout must be greater than zero');
            }
        }

        $security = $data['security'] ?? [];
        if (($security['enabled'] ?? true) === true) {
            if (isset($security['trustedProxies']) && !is_array($security['trustedProxies'])) {
                throw new \InvalidArgumentException('security.trustedProxies must be an array');
            }
            if (isset($security['contentRules']['patterns']) && is_array($security['contentRules']['patterns'])) {
                foreach ($security['contentRules']['patterns'] as $pt) {
                    $plainPt = is_string($pt) ? \App\System\SecurityService::decryptSecret($pt) : '';
                    if ($plainPt !== '' && @preg_match($plainPt, '') === false) {
                        throw new \InvalidArgumentException("Invalid regular expression in security patterns: {$pt}");
                    }
                }
            }
        }

        $rateLimit = $data['rateLimit'] ?? [];
        if (($rateLimit['enabled'] ?? false) === true) {
            if (isset($rateLimit['trustedProxies']) && !is_array($rateLimit['trustedProxies'])) {
                throw new \InvalidArgumentException('rateLimit.trustedProxies must be an array');
            }
            if (isset($rateLimit['default']) && (!is_array($rateLimit['default']) || count($rateLimit['default']) < 2)) {
                throw new \InvalidArgumentException('rateLimit.default must contain [limit, window]');
            }
        }
    }

    /**
     * Ensure in-memory configuration is fresh across Swoole resident worker processes
     * by detecting dynamic config file mtime/size changes and Redis version bump.
     */
    public static function ensureFresh(): void
    {
        if (self::$checkingFresh) {
            return;
        }

        $basePath = self::$baseConfigPath !== '' ? self::$baseConfigPath : (CORE_PATH . '/Config.inc.php');
        if (!self::$loaded) {
            self::load($basePath);
            return;
        }

        $dynamicPath = self::getDynamicConfigPath();
        clearstatcache(true, $dynamicPath);
        $exists = is_file($dynamicPath);
        $currentMtime = $exists ? (filemtime($dynamicPath) ?: 0) : 0;
        $currentSize = $exists ? (filesize($dynamicPath) ?: 0) : -1;

        if ($currentMtime !== self::$dynamicMtime || $currentSize !== self::$dynamicSize) {
            self::load($basePath);
            return;
        }

        if ($exists && extension_loaded('redis')) {
            self::$checkingFresh = true;
            try {
                $redis = \App\Client\RedisClient::getRedis();
                if ($redis !== null) {
                    $remoteVer = (string) ($redis->get('config:dynamic:version') ?: '');
                    if ($remoteVer !== '' && self::$dynamicVersion !== '' && $remoteVer !== self::$dynamicVersion) {
                        self::load($basePath);
                    }
                }
            } catch (\Throwable) {
            } finally {
                self::$checkingFresh = false;
            }
        }
    }

    public static function Get(string $name): array
    {
        self::ensureFresh();
        return self::$data[$name] ?? [];
    }

    public static function has(string $name): bool
    {
        self::ensureFresh();
        return isset(self::$data[$name]);
    }

    public static function all(): array
    {
        self::ensureFresh();
        return self::$data;
    }

    public static function getDynamicConfigPath(): string
    {
        $runtimeDir = CORE_PATH . '/runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }
        return $runtimeDir . '/dynamic_config.json';
    }

    public static function maskSecret(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '';
        }
        $len = strlen($secret);
        if ($len <= 8) {
            return '********';
        }
        return substr($secret, 0, 4) . '****' . substr($secret, -4);
    }

    /**
     * Deep merge two arrays. Sequential (list) arrays in $replacement overwrite $base completely.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $replacement
     * @return array<string, mixed>
     */
    public static function deepMerge(array $base, array $replacement): array
    {
        foreach ($replacement as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                if (array_is_list($value) || array_is_list($base[$key])) {
                    $base[$key] = $value;
                } else {
                    $base[$key] = self::deepMerge($base[$key], $value);
                }
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function applyDynamicOverrides(array &$data): void
    {
        $path = self::getDynamicConfigPath();
        if (!is_file($path)) {
            self::$dynamicVersion = '';
            return;
        }
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            self::$dynamicVersion = '';
            return;
        }
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            if (isset($decoded['_meta']['version'])) {
                self::$dynamicVersion = (string) $decoded['_meta']['version'];
                unset($decoded['_meta']);
            }
            $data = self::deepMerge($data, $decoded);
            $explicitKeys = [
                ['ai', 'headers'],
                ['security', 'contentRules'],
                ['security', 'ipBans'],
                ['security', 'trustedProxies'],
                ['rateLimit', 'routes'],
                ['rateLimit', 'trustedProxies'],
                ['github', 'tokens'],
                ['github', 'repos'],
                ['filter', 'pre'],
            ];
            foreach ($explicitKeys as [$section, $field]) {
                if (isset($decoded[$section][$field]) && is_array($decoded[$section][$field])) {
                    $data[$section][$field] = $decoded[$section][$field];
                }
            }
        }
    }

    /**
     * Restore original secret values if the incoming update contains masked placeholders.
     *
     * @param array<string, mixed> $updates
     * @param array<string, mixed> $original
     */
    private static function restoreMaskedSecrets(array &$updates, array $original): void
    {
        // 1. ai.apiKeys
        if (isset($updates['ai']['apiKeys']) && is_array($updates['ai']['apiKeys'])) {
            $origKeys = $original['ai']['apiKeys'] ?? [];
            $restoredKeys = [];
            foreach ($updates['ai']['apiKeys'] as $idx => $key) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                if (str_contains($key, '****') || $key === '********') {
                    $matched = false;
                    foreach ($origKeys as $orig) {
                        if (self::maskSecret($orig) === $key) {
                            $restoredKeys[] = $orig;
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched && isset($origKeys[$idx])) {
                        $restoredKeys[] = $origKeys[$idx];
                    }
                } else {
                    $restoredKeys[] = $key;
                }
            }
            $updates['ai']['apiKeys'] = $restoredKeys;
        }

        // 2. Scalar secret fields
        $secretFields = [
            ['admin', 'token'],
            ['cache', 'redis', 'password'],
            ['storage', 'mariadb', 'password'],
            ['github', 'token'],
        ];
        foreach ($secretFields as $path) {
            $curr = &$updates;
            $origCurr = $original;
            $found = true;
            foreach ($path as $p) {
                if (!isset($curr[$p])) {
                    $found = false;
                    break;
                }
                $curr = &$curr[$p];
                $origCurr = $origCurr[$p] ?? null;
            }
            if ($found && is_string($curr)) {
                if (str_contains($curr, '****') || $curr === '******' || $curr === '********') {
                    $curr = is_string($origCurr) ? $origCurr : '';
                }
            }
            unset($curr);
        }
        // 4. ai.headers
        if (isset($updates['ai']['headers']) && is_array($updates['ai']['headers'])) {
            $origHeaders = $original['ai']['headers'] ?? [];
            foreach ($updates['ai']['headers'] as $hKey => $hVal) {
                if (is_string($hVal) && (str_contains($hVal, '****') || $hVal === '******' || $hVal === '********')) {
                    if (isset($origHeaders[$hKey]) && is_string($origHeaders[$hKey])) {
                        $updates['ai']['headers'][$hKey] = $origHeaders[$hKey];
                    }
                }
            }
        }
        // 5. github.tokens
        if (isset($updates['github']['tokens']) && is_array($updates['github']['tokens'])) {
            $origTokens = $original['github']['tokens'] ?? [];
            if (isset($original['github']['token']) && is_string($original['github']['token']) && $original['github']['token'] !== '') {
                $origTokens[] = $original['github']['token'];
            }
            $restoredTokens = [];
            foreach ($updates['github']['tokens'] as $idx => $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }
                if (str_contains($token, '****') || $token === '******' || $token === '********') {
                    $matched = false;
                    foreach ($origTokens as $orig) {
                        if (self::maskSecret($orig) === $token) {
                            $restoredTokens[] = $orig;
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched && isset($origTokens[$idx])) {
                        $restoredTokens[] = $origTokens[$idx];
                    }
                } else {
                    $restoredTokens[] = $token;
                }
            }
            $updates['github']['tokens'] = $restoredTokens;
        }
    }

    /**
     * Return configuration tree with sensitive credentials masked.
     *
     * @return array<string, mixed>
     */
    public static function getMasked(): array
    {
        self::ensureFresh();
        $masked = self::$data;
        unset($masked['_meta']);

        if (isset($masked['admin']['token']) && (string) $masked['admin']['token'] !== '') {
            $masked['admin']['token'] = '******';
        }
        if (isset($masked['cache']['redis']['password']) && (string) $masked['cache']['redis']['password'] !== '') {
            $masked['cache']['redis']['password'] = '******';
        }
        if (isset($masked['storage']['mariadb']['password']) && (string) $masked['storage']['mariadb']['password'] !== '') {
            $masked['storage']['mariadb']['password'] = '******';
        }
        if (isset($masked['github']['token']) && (string) $masked['github']['token'] !== '') {
            $masked['github']['token'] = self::maskSecret((string) $masked['github']['token']);
        }
        if (isset($masked['github']['tokens']) && is_array($masked['github']['tokens'])) {
            $masked['github']['tokens'] = array_map(fn($t) => self::maskSecret((string) $t), $masked['github']['tokens']);
        }
        if (isset($masked['ai']['apiKeys']) && is_array($masked['ai']['apiKeys'])) {
            $masked['ai']['apiKeys'] = array_map(fn($k) => self::maskSecret((string) $k), $masked['ai']['apiKeys']);
        }
        if (isset($masked['ai']['headers']) && is_array($masked['ai']['headers'])) {
            foreach ($masked['ai']['headers'] as $hKey => &$hVal) {
                if (is_string($hVal) && is_string($hKey)) {
                    $lower = strtolower($hKey);
                    if (str_contains($lower, 'token') || str_contains($lower, 'auth') || str_contains($lower, 'key') || str_contains($lower, 'secret')) {
                        $hVal = self::maskSecret($hVal);
                    }
                }
            }
            unset($hVal);
        }
        if (isset($masked['security']['contentRules']['keywords']) && is_array($masked['security']['contentRules']['keywords'])) {
            $masked['security']['contentRules']['keywords'] = \App\System\SecurityService::decryptRulesList($masked['security']['contentRules']['keywords']);
        }
        if (isset($masked['security']['contentRules']['patterns']) && is_array($masked['security']['contentRules']['patterns'])) {
            $masked['security']['contentRules']['patterns'] = \App\System\SecurityService::decryptRulesList($masked['security']['contentRules']['patterns']);
        }

        return $masked;
    }

    /**
     * Persist dynamic configuration updates and hot-reload in-memory config.
     *
     * @param array<string, mixed> $updates
     */
    public static function saveDynamic(array $updates): void
    {
        self::ensureFresh();
        if (isset($updates['_meta'])) {
            unset($updates['_meta']);
        }
        self::restoreMaskedSecrets($updates, self::$data);

        $explicitKeys = [
            ['ai', 'headers'],
            ['security', 'contentRules'],
            ['security', 'ipBans'],
            ['security', 'trustedProxies'],
            ['rateLimit', 'routes'],
            ['rateLimit', 'trustedProxies'],
            ['github', 'tokens'],
            ['github', 'repos'],
            ['filter', 'pre'],
            ['ai', 'agent'],
        ];

        $candidate = self::deepMerge(self::$data, $updates);
        foreach ($explicitKeys as [$section, $field]) {
            if (isset($updates[$section][$field]) && is_array($updates[$section][$field])) {
                $candidate[$section][$field] = $updates[$section][$field];
            }
        }

        self::validate($candidate);

        $path = self::getDynamicConfigPath();
        $existing = [];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false && $raw !== '') {
                $existing = json_decode($raw, true) ?: [];
                unset($existing['_meta']);
            }
        }
        $newDynamic = self::deepMerge($existing, $updates);
        foreach ($explicitKeys as [$section, $field]) {
            if (isset($updates[$section][$field]) && is_array($updates[$section][$field])) {
                $newDynamic[$section][$field] = $updates[$section][$field];
            }
        }

        // 双向同步：若更新了 security.contentRules，加密持久化写入 runtime/content_reject_rules.json
        if (isset($updates['security']['contentRules']) && is_array($updates['security']['contentRules'])) {
            $rulesPath = CORE_PATH . '/runtime/content_reject_rules.json';
            $rulesDir = dirname($rulesPath);
            if (!is_dir($rulesDir)) {
                @mkdir($rulesDir, 0777, true);
            }
            $rawKw = (array) ($updates['security']['contentRules']['keywords'] ?? []);
            $rawPt = (array) ($updates['security']['contentRules']['patterns'] ?? []);
            $encKw = \App\System\SecurityService::encryptRulesList($rawKw);
            $encPt = \App\System\SecurityService::encryptRulesList($rawPt);

            $newDynamic['security']['contentRules']['keywords'] = $encKw;
            $newDynamic['security']['contentRules']['patterns'] = $encPt;

            $rulesData = [
                'enabled' => (bool) ($updates['security']['contentRules']['enabled'] ?? true),
                'keywords' => $encKw,
                'patterns' => $encPt,
                'updatedAt' => time(),
            ];
            @file_put_contents($rulesPath, json_encode($rulesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $version = (string) (int) (microtime(true) * 1000000);
        $newDynamic['_meta'] = [
            'version' => $version,
            'updatedAt' => time(),
        ];

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        $encoded = json_encode($newDynamic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($tmpPath, $encoded) === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to write dynamic config file');
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to atomic-rename dynamic config file');
        }

        clearstatcache(true, $path);
        self::$dynamicMtime = filemtime($path) ?: time();
        self::$dynamicSize = filesize($path) ?: strlen((string) $encoded);
        self::$dynamicVersion = $version;
        self::$data = $candidate;

        if (extension_loaded('redis')) {
            self::$checkingFresh = true;
            try {
                $redis = \App\Client\RedisClient::getRedis();
                $redis?->set('config:dynamic:version', $version);
            } catch (\Throwable) {
            } finally {
                self::$checkingFresh = false;
            }
        }
    }

    /**
     * Reset dynamic overrides and reload base configuration.
     */
    public static function resetDynamic(): void
    {
        $path = self::getDynamicConfigPath();
        if (is_file($path)) {
            @unlink($path);
            clearstatcache(true, $path);
        }
        self::$dynamicMtime = 0;
        self::$dynamicSize = -1;
        self::$dynamicVersion = '';
        if (extension_loaded('redis')) {
            self::$checkingFresh = true;
            try {
                $redis = \App\Client\RedisClient::getRedis();
                $redis?->del('config:dynamic:version');
            } catch (\Throwable) {
            } finally {
                self::$checkingFresh = false;
            }
        }
        self::load(self::$baseConfigPath !== '' ? self::$baseConfigPath : (CORE_PATH . '/Config.inc.php'));
    }

    /**
     * Get raw dynamic configuration array without _meta.
     *
     * @return array<string, mixed>
     */
    public static function getDynamicConfigRaw(): array
    {
        $path = self::getDynamicConfigPath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        unset($decoded['_meta']);
        return $decoded;
    }

    /**
     * Touch dynamic config checking to ensure freshness across worker coroutines.
     */
    public static function touchDynamicConfig(): void
    {
        self::ensureFresh();
    }
}


