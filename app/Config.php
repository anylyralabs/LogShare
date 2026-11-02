<?php

namespace App;

class Config
{
    /** @var array<string, mixed> */
    private static array $data = [];

    public static function load(string $path): void
    {
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
        // 旧版已移除的 ai/github/事件队列/内容规则段直接丢弃，避免遗留密钥或行为残留
        unset($data['ai'], $data['github'], $data['eventQueue']);
        if (isset($data['security']) && is_array($data['security'])) {
            unset($data['security']['contentRules']);
        }
        // Example 配置中的占位符（如 '${REDIS_PASSWORD}'）在非 Docker 部署下不会
        // 被替换，占位符形态一律视为未配置，避免拿字面量去 AUTH Redis
        if (isset($data['cache']['redis']['password'])
            && is_string($data['cache']['redis']['password'])
            && preg_match('/^\$\{[A-Z_]+\}$/', $data['cache']['redis']['password']) === 1) {
            $data['cache']['redis']['password'] = '';
        }
        self::validate($data);

        self::$data = $data;
    }

    /**
     * Allow environment variables to override configuration values.
     *
     * Supported variables:
     *  - REDIS_HOST         → cache.redis.host
     *  - REDIS_PORT         → cache.redis.port
     *  - REDIS_TIMEOUT      → cache.redis.timeout
     *  - REDIS_PASSWORD     → cache.redis.password
     *  - MONGODB_URI        → mongo.url
     *  - MONGODB_DATABASE   → mongo.database
     *  - MONGODB_DRIVER     → mongo.driver（native / gotask）
     *  - CODEX_LANGUAGE     → codex.language
     *
     * @param array<string, mixed> $data Config array by reference
     * @return void
     */
    private static function applyEnvironmentOverrides(array &$data): void
    {
        if (($storageTime = getenv('STORAGE_TIME')) !== false && ctype_digit($storageTime) && (int) $storageTime > 0) {
            $data['storage']['storageTime'] = (int) $storageTime;
        }
        if (($mongoUri = getenv('MONGODB_URI')) !== false && $mongoUri !== '') {
            $data['mongo']['url'] = $mongoUri;
        }
        if (($mongoDatabase = getenv('MONGODB_DATABASE')) !== false && $mongoDatabase !== '') {
            $data['mongo']['database'] = $mongoDatabase;
        }
        if (($mongoDriver = getenv('MONGODB_DRIVER')) !== false && $mongoDriver !== '') {
            $data['mongo']['driver'] = $mongoDriver;
        }
        if (($codexLanguage = getenv('CODEX_LANGUAGE')) !== false && $codexLanguage !== '') {
            $data['codex']['language'] = $codexLanguage;
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

        $security = $data['security'] ?? [];
        if (($security['enabled'] ?? true) === true) {
            if (isset($security['trustedProxies']) && !is_array($security['trustedProxies'])) {
                throw new \InvalidArgumentException('security.trustedProxies must be an array');
            }
        }

        // 预过滤链是字符串类名，一旦写错，
        // 上传会在调用处抛 Error 变 500，只能回滚配置才能恢复。
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

        $mongo = $data['mongo'] ?? [];
        if (!is_array($mongo) || trim((string) ($mongo['url'] ?? '')) === '') {
            throw new \InvalidArgumentException('mongo.url is required');
        }
        if (!str_starts_with((string) $mongo['url'], 'mongodb://') && !str_starts_with((string) $mongo['url'], 'mongodb+srv://')) {
            throw new \InvalidArgumentException('mongo.url must start with mongodb:// or mongodb+srv://');
        }
        if (trim((string) ($mongo['database'] ?? '')) === '') {
            throw new \InvalidArgumentException('mongo.database is required');
        }
        $mongoDriver = (string) ($mongo['driver'] ?? 'native');
        if (!in_array($mongoDriver, ['native', 'gotask'], true)) {
            throw new \InvalidArgumentException("mongo.driver must be 'native' or 'gotask', got '{$mongoDriver}'");
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
     * 读取一个顶层配置段。
     *
     * 只接受段名（如 `storage`、`security`）。传入 `storage.storageTime`
     * 这类点路径会静默返回空数组，这里直接抛错，把误用暴露在开发阶段。
     *
     * @return array<string, mixed>
     */
    public static function Get(string $name): array
    {
        if (str_contains($name, '.')) {
            throw new \InvalidArgumentException(
                "Config::Get() expects a top-level section name, got '{$name}'. Use Config::GetPath('a.b') for nested values."
            );
        }

        return self::$data[$name] ?? [];
    }

    /**
     * 按 `a.b.c` 形式读取嵌套配置项，缺失时返回 $default。
     */
    public static function GetPath(string $path, mixed $default = null): mixed
    {

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
        return isset(self::$data[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$data;
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
}


