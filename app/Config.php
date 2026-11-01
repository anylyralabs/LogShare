<?php

namespace App;

class Config
{
    /** admin.token 允许启用的最小长度 */
    public const MIN_ADMIN_TOKEN_LENGTH = 32;

    /** @var array<string, mixed> */
    private static array $data = [];
    private static bool $loaded = false;
    private static int $dynamicMtime = 0;
    private static int $dynamicSize = -1;
    private static string $dynamicVersion = '';
    private static string $baseConfigPath = '';
    private static bool $checkingFresh = false;
    /**
     * 当前协程（或 CLI 进程）是否已完成过一次新鲜度检查。
     *
     * 配置热生效的语义是「下一个请求可见」：同一个请求内调用 Get() 十几次时，
     * 只有第一次需要 stat 动态配置文件并查询 Redis 版本号，后续复用结果。
     * 用协程 Context 而不是时间窗口，既能省掉重复检查，又不会让「写完立刻读」
     * 的调用方（例如管理端保存后立即回读、单测）看到过期数据。
     */
    private const FRESH_CONTEXT_KEY = 'logshare_config_fresh_checked';

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
        // 旧版已移除的 ai/github 段不得再通过管理接口暴露
        unset($data['ai'], $data['github']);
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
     *
     * @param array<string, mixed> $data Config array by reference
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
        if (($adminEnabled = getenv('ADMIN_ENABLED')) !== false) {
            $data['admin']['enabled'] = in_array(strtolower($adminEnabled), ['1', 'true', 'on', 'yes'], true);
        }
        if ($adminToken = getenv('ADMIN_TOKEN')) {
            $data['admin']['token'] = $adminToken;
        }

    }

    /**
     * @param array<string, mixed> $data
     */
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

        $admin = $data['admin'] ?? [];
        if (($admin['enabled'] ?? false) === true) {
            $token = $admin['token'] ?? null;
            if (!is_string($token) || $token === '') {
                throw new \InvalidArgumentException('admin.token is required when admin is enabled');
            }
            if (self::isPlaceholderSecret($token)) {
                throw new \InvalidArgumentException(
                    'admin.token still uses a placeholder from the example config; set ADMIN_TOKEN to a random value (openssl rand -hex 32)'
                );
            }
            if (strlen($token) < self::MIN_ADMIN_TOKEN_LENGTH) {
                throw new \InvalidArgumentException(
                    'admin.token must be at least ' . self::MIN_ADMIN_TOKEN_LENGTH . ' characters long'
                );
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

        // 预过滤链是字符串类名，一旦写错（或动态配置被改成不存在的类），
        // 上传与 /analyse 会在调用处抛 Error 变 500，只能回滚配置才能恢复。
        $filters = $data['filter']['pre'] ?? [];
        if (!is_array($filters)) {
            throw new \InvalidArgumentException('filter.pre must be an array of filter class names');
        }
        foreach ($filters as $filterClass) {
            if (!is_string($filterClass) || $filterClass === '') {
                throw new \InvalidArgumentException('filter.pre entries must be non-empty class names');
            }
            if (!class_exists($filterClass)) {
                throw new \InvalidArgumentException("filter.pre class does not exist: {$filterClass}");
            }
            if (!is_subclass_of($filterClass, \App\Filter\Filter::class)) {
                throw new \InvalidArgumentException("filter.pre class must extend App\\Filter\\Filter: {$filterClass}");
            }
        }

        $storages = $data['storage']['storages'] ?? [];
        if (is_array($storages)) {
            foreach ($storages as $storageKey => $storageConfig) {
                $class = is_array($storageConfig) ? ($storageConfig['class'] ?? null) : null;
                if (!is_string($class) || $class === '') {
                    throw new \InvalidArgumentException("storage.storages.{$storageKey}.class is required");
                }
                if (!class_exists($class)) {
                    throw new \InvalidArgumentException("storage.storages.{$storageKey}.class does not exist: {$class}");
                }
                if (!is_subclass_of($class, \App\Storage\StorageInterface::class)) {
                    throw new \InvalidArgumentException("storage.storages.{$storageKey}.class must implement App\\Storage\\StorageInterface: {$class}");
                }
            }
        }

        $cacheClass = $data['cache']['cacheId'] ?? null;
        if (is_string($cacheClass) && $cacheClass !== '') {
            if (!class_exists($cacheClass)) {
                throw new \InvalidArgumentException("cache.cacheId class does not exist: {$cacheClass}");
            }
            if (!is_subclass_of($cacheClass, \App\Cache\CacheInterface::class)) {
                throw new \InvalidArgumentException("cache.cacheId must implement App\\Cache\\CacheInterface: {$cacheClass}");
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

        // 同一请求（协程）内只检查一次；CLI/单测没有协程时退化为每次都检查。
        if (self::freshCheckedInThisContext()) {
            return;
        }

        $dynamicPath = self::getDynamicConfigPath();
        clearstatcache(true, $dynamicPath);
        $exists = is_file($dynamicPath);
        $currentMtime = $exists ? (filemtime($dynamicPath) ?: 0) : 0;
        $currentSize = $exists ? (filesize($dynamicPath) ?: 0) : -1;

        if ($currentMtime !== self::$dynamicMtime || $currentSize !== self::$dynamicSize) {
            self::load($basePath);
            self::markFreshChecked(true);
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
                    self::markFreshChecked(true);
                }
            } catch (\Throwable) {
            } finally {
                self::$checkingFresh = false;
            }
        }
    }

    /**
     * 读取一个顶层配置段。
     *
     * 只接受段名（如 `admin`、`storage`）。传入 `admin.token` 这类点路径
     * 会静默返回空数组，曾让运维脚本把空值当成有效 token 继续发请求；
     * 这里直接抛错，把误用暴露在开发阶段。
     */
    /**
     * @return array<string, mixed>
     */
    public static function Get(string $name): array
    {
        if (str_contains($name, '.')) {
            throw new \InvalidArgumentException(
                "Config::Get() expects a top-level section name, got '{$name}'. Use Config::Get('admin')['token'] instead."
            );
        }

        self::ensureFresh();
        return self::$data[$name] ?? [];
    }

    /**
     * 按 `a.b.c` 形式读取嵌套配置项，缺失时返回 $default。
     */
    public static function GetPath(string $path, mixed $default = null): mixed
    {
        self::ensureFresh();

        $value = self::$data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function has(string $name): bool
    {
        self::ensureFresh();
        return isset(self::$data[$name]);
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * 判断是否为示例配置里的占位值（这些值必须被部署方替换）。
     */
    public static function isPlaceholderSecret(string $value): bool
    {
        $lower = strtolower(trim($value));
        if ($lower === '') {
            return false;
        }

        foreach (['change-this', 'change-me', 'changeme', 'replace-with', 'your-', 'example', 'placeholder', 'xxxx'] as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param array<string, mixed> $data
     */
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
                ['security', 'contentRules'],
                ['security', 'ipBans'],
                ['security', 'trustedProxies'],
                ['rateLimit', 'routes'],
                ['rateLimit', 'trustedProxies'],
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
        // Scalar secret fields
        $secretFields = [
            ['admin', 'token'],
            ['cache', 'redis', 'password'],
            ['storage', 'mariadb', 'password'],
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
        unset($updates['ai'], $updates['github']);
        if (isset($updates['_meta'])) {
            unset($updates['_meta']);
        }
        self::restoreMaskedSecrets($updates, self::$data);

        $explicitKeys = [
            ['security', 'contentRules'],
            ['security', 'ipBans'],
            ['security', 'trustedProxies'],
            ['rateLimit', 'routes'],
            ['rateLimit', 'trustedProxies'],
            ['filter', 'pre'],
        ];

        $candidate = self::deepMerge(self::$data, $updates);
        foreach ($explicitKeys as [$section, $field]) {
            if (isset($updates[$section][$field]) && is_array($updates[$section][$field])) {
                $candidate[$section][$field] = $updates[$section][$field];
            }
        }

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

        // 校验的必须是「落盘内容合并到基准配置后」的结果：$candidate 基于
        // 内存快照，与磁盘上的 $newDynamic 在并发写时可能不同，只用前者会
        // 放过非法合并结果；而 $newDynamic 本身只是增量，直接校验会误判
        // 缺失的配置段。这里以 baseConfig 为基准重建一次。
        self::validate(self::applyDynamicToBase($newDynamic));

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
        // 动态配置含加密后的安全规则与连接凭据掩码，禁止 world-readable
        @chmod($tmpPath, 0600);

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to atomic-rename dynamic config file');
        }

        clearstatcache(true, $path);
        self::markFreshChecked(false);
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
     * 当前上下文是否已经检查过新鲜度。
     */
    private static function freshCheckedInThisContext(): bool
    {
        if (!class_exists(\Hyperf\Context\Context::class)) {
            return false;
        }
        return \Hyperf\Context\Context::get(self::FRESH_CONTEXT_KEY) === true;
    }

    /**
     * 标记（或清除）当前上下文的新鲜度检查状态。
     *
     * @param bool $checked 为 false 时清除标记，强制下次 Get() 重新检查
     */
    private static function markFreshChecked(bool $checked): void
    {
        if (!class_exists(\Hyperf\Context\Context::class)) {
            return;
        }
        if ($checked) {
            \Hyperf\Context\Context::set(self::FRESH_CONTEXT_KEY, true);
            return;
        }
        \Hyperf\Context\Context::destroy(self::FRESH_CONTEXT_KEY);
    }

    /**
     * 把动态配置合并到「示例配置 + Config.inc.php」基准之上，得到落盘后
     * 真正会生效的完整配置（供 saveDynamic() 校验使用）。
     *
     * @param array<string, mixed> $dynamic
     * @return array<string, mixed>
     */
    private static function applyDynamicToBase(array $dynamic): array
    {
        $base = require CORE_PATH . '/Config.inc.example.php';
        if (!is_array($base)) {
            $base = [];
        }

        $path = self::$baseConfigPath !== '' ? self::$baseConfigPath : (CORE_PATH . '/Config.inc.php');
        if (is_file($path)) {
            $userConfig = require $path;
            if (is_array($userConfig)) {
                $base = self::deepMerge($base, $userConfig);
            }
        }

        $decoded = $dynamic;
        unset($decoded['_meta']);

        $merged = self::deepMerge($base, $decoded);
        unset($merged['ai'], $merged['github']);

        return $merged;
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
        self::markFreshChecked(false);
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


