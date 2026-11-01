<?php

declare(strict_types=1);

namespace App\System;

use App\ApiError;
use App\Client\RedisClient;
use App\Config;

/**
 * 边缘安全态势与主动管控联动服务。
 *
 * 负责 IP 黑名单封禁/解封、OpenLiteWaf 快照概览聚合、
 * 以及动态内容违规特征前置拦截校验。
 */
final class SecurityService
{
    private const RULES_FILE = '/runtime/content_reject_rules.json';
    private const BANS_FILE = '/runtime/security_bans.json';
    private const BAN_PREFIX = 'security:ban:';
    private const CIPHER_PREFIX = 'enc:v1:';
    private const SECRET_FILE = '/runtime/.security_secret';

    /**
     * 根据反向代理可信策略，解析外部客户端真实访问 IP。
     *
     * @param array<string, mixed> $serverParams
     * @param array<string, mixed>|object $headers
     * @return string
     */
    public static function resolveClientIp(array $serverParams, array|object $headers = []): string
    {
        $remote = (string) ($serverParams['remote_addr'] ?? '127.0.0.1');

        $security = Config::Get('security');
        $rateLimit = Config::Get('rateLimit');
        $trustedProxies = array_merge(
            (array) ($security['trustedProxies'] ?? ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']),
            (array) ($rateLimit['trustedProxies'] ?? [])
        );

        $isTrusted = self::isIpTrusted($remote, $trustedProxies);

        if ($isTrusted) {
            // 1. 优先提取反向代理强行覆盖的 X-Real-IP
            $realIp = $serverParams['http_x_real_ip'] ?? null;
            if (empty($realIp) && is_array($headers) && !empty($headers['x-real-ip'][0])) {
                $realIp = $headers['x-real-ip'][0];
            }
            if (is_string($realIp) && filter_var(trim($realIp), FILTER_VALIDATE_IP)) {
                return trim($realIp);
            }

            // 2. 提取 X-Forwarded-For 并从右向左逐级剥离信任代理，提取最外层的不可信真实 IP
            $forwardedFor = $serverParams['http_x_forwarded_for'] ?? null;
            if (empty($forwardedFor) && is_array($headers) && !empty($headers['x-forwarded-for'][0])) {
                $forwardedFor = $headers['x-forwarded-for'][0];
            }
            if (is_string($forwardedFor) && $forwardedFor !== '') {
                $parts = array_filter(
                    array_map('trim', explode(',', $forwardedFor)),
                    static fn ($p) => filter_var($p, FILTER_VALIDATE_IP) !== false
                );
                $parts = array_values($parts);
                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    $ip = $parts[$i];
                    if (!self::isIpTrusted($ip, $trustedProxies)) {
                        return $ip;
                    }
                }
                if (!empty($parts)) {
                    return $parts[0];
                }
            }
        }

        return $remote;
    }

    /**
     * 判断指定 IP 是否在受信任代理名单中。
     *
     * @param array<int, string> $trustedProxies
     */
    public static function isIpTrusted(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $proxy) {
            $proxy = trim((string) $proxy);
            if ($proxy === '') {
                continue;
            }
            if ($proxy === $ip) {
                return true;
            }
            if (str_contains($proxy, '/') && self::ipInCidr($ip, $proxy)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 判断指定 IP 是否属于私网或回环地址。
     */
    public static function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * 判断 IPv4 地址是否属于指定 CIDR 网段。
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if ($isV4) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
                return false;
            }
            if ($bits === 0) {
                return true;
            }
            $mask = -1 << (32 - $bits);
            $subnetLong &= $mask;
            return ($ipLong & $mask) === $subnetLong;
        }

        // IPv6 也必须支持：反向代理常用 IPv6 内网段，判不出来会让
        // resolveClientIp() 退化成代理地址，按代理 IP 聚合封禁与限流。
        $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if ($isV6) {
            if ($bits < 0 || $bits > 128) {
                return false;
            }
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $fullBytes = intdiv($bits, 8);
            $remainingBits = $bits % 8;
            if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
                return false;
            }
            if ($remainingBits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
        }

        return false;
    }

    /**
     * 主动封禁指定 IP。
     *
     * @param string $ip IPv4 或 IPv6 地址
     * @param int $ttlSeconds 封禁时长（秒，0 表示 30 天）
     * @param string $reason 封禁理由
     * @param string $operator 操作人
     * @return array{ip: string, reason: string, expiresAt: int, operator: string}
     */
    public static function banIp(string $ip, int $ttlSeconds = 86400, string $reason = '自动封禁', string $operator = 'system'): array
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ApiError(400, "Invalid IP address: {$ip}");
        }

        $ttlSeconds = $ttlSeconds <= 0 ? (30 * 86400) : min(365 * 86400, $ttlSeconds);
        $expiresAt = time() + $ttlSeconds;

        $record = [
            'ip' => $ip,
            'reason' => $reason,
            'bannedAt' => time(),
            'expiresAt' => $expiresAt,
            'operator' => $operator,
            'source' => 'manual',
        ];

        // 1. 写入 Redis
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $redis->setEx(self::BAN_PREFIX . $ip, $ttlSeconds, json_encode($record, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                \App\Syslog::error('SecurityService', 'Failed to write redis ban: ' . $e->getMessage());
            }
        }

        // 2. 双写本地持久化文件作为韧性降级
        self::writeFileBan($ip, $record);

        // 3. 联动 OpenLiteWaf 快照
        self::syncBanToWaf($ip, $expiresAt);

        return [
            'ip' => $ip,
            'reason' => $reason,
            'expiresAt' => $expiresAt,
            'operator' => $operator,
        ];
    }

    /**
     * 判断指定 IP 是否当前被封禁。
     *
     * @param string $ip
     * @return bool
     */
    public static function isIpBanned(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        // 0. 检查 Config 预设封禁配置（支持单个 IP 及 CIDR 网段）
        $security = Config::Get('security');
        if (($security['enabled'] ?? true) === false) {
            return false;
        }
        $configBans = (array) ($security['ipBans'] ?? []);
        foreach ($configBans as $bKey => $bVal) {
            $candidate = is_string($bVal) ? $bVal : (string) $bKey;
            $candidate = trim($candidate);
            if ($candidate === $ip) {
                return true;
            }
            if (str_contains($candidate, '/') && self::ipInCidr($ip, $candidate)) {
                return true;
            }
        }

        // 1. 检查 Redis
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                if ($redis->exists(self::BAN_PREFIX . $ip)) {
                    return true;
                }
            } catch (\Throwable) {
                // fall through to file check
            }
        }

        // 2. 检查本地持久化文件
        $fileBans = self::readFileBans();
        if (isset($fileBans[$ip])) {
            return (int) ($fileBans[$ip]['expiresAt'] ?? 0) > time();
        }

        // 3. 检查 OpenLiteWaf 快照
        $wafBans = self::readWafSnapshotBans();
        foreach ($wafBans as $item) {
            if (($item['ip'] ?? '') === $ip && (int) ($item['expiresAt'] ?? 0) > time()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function readFileBans(): array
    {
        $path = CORE_PATH . self::BANS_FILE;
        if (!is_file($path)) {
            return [];
        }
        $content = @file_get_contents($path);
        if (!$content) {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function writeFileBan(string $ip, array $record): void
    {
        $bans = self::readFileBans();
        $bans[$ip] = $record;
        self::writeJsonFile(CORE_PATH . self::BANS_FILE, $bans);
    }

    /**
     * 原子写入本地 JSON（临时文件 + rename），并把权限收紧到 0600。
     *
     * @param array<string, mixed> $data
     */
    private static function writeJsonFile(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            \App\Syslog::error('SecurityService', 'Failed to encode ban file: ' . $path);
            return;
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            @unlink($tmp);
            \App\Syslog::error('SecurityService', 'Failed to write ban file: ' . $path);
            return;
        }

        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            \App\Syslog::error('SecurityService', 'Failed to replace ban file: ' . $path);
        }
    }

    /**
     * OpenLiteWaf 运行在 nginx 容器内，证书按公网域名签发；内部直连时必须带
     * 与证书匹配的 Host。这里从 urls.apiBaseUrl 推导，测试部署无需改代码。
     */
    private static function wafHost(): string
    {
        $base = (string) \App\Config::GetPath('urls.apiBaseUrl', '');
        $host = parse_url($base, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'api.logshare.cn';
    }

    /**
     * 获取对称加密密钥（32 字节二进制）。
     */
    public static function getEncryptionKey(): string
    {
        static $cachedKey = null;
        if ($cachedKey !== null) {
            return $cachedKey;
        }

        $envKey = getenv('SECURITY_KEY') ?: getenv('SECURITY_ENCRYPTION_KEY');
        if (is_string($envKey) && $envKey !== '') {
            $cachedKey = hash('sha256', $envKey, true);
            return $cachedKey;
        }

        $cfg = Config::Get('security');
        if (isset($cfg['encryptionKey']) && is_string($cfg['encryptionKey']) && $cfg['encryptionKey'] !== '') {
            $cachedKey = hash('sha256', $cfg['encryptionKey'], true);
            return $cachedKey;
        }

        $secretPath = CORE_PATH . self::SECRET_FILE;
        if (is_file($secretPath)) {
            $raw = @file_get_contents($secretPath);
            if (is_string($raw) && strlen(trim($raw)) >= 32) {
                $cachedKey = hash('sha256', trim($raw), true);
                return $cachedKey;
            }
        }

        $dir = dirname($secretPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // 使用专属临时文件以 0600 安全权限写入，并在原子重命名前后保持权限，避免竞态覆盖
        $tempPath = $secretPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $generated = bin2hex(random_bytes(32));
        $oldUmask = umask(0077);
        $fp = @fopen($tempPath, 'wb');
        umask($oldUmask);
        if ($fp !== false) {
            @chmod($tempPath, 0600);
            fwrite($fp, $generated);
            fclose($fp);
            @chmod($tempPath, 0600);

            // 若已有其他并发 Worker 创建成功，则保留先创建的文件以保全密钥一致性
            if (!is_file($secretPath)) {
                if (!@rename($tempPath, $secretPath)) {
                    @unlink($tempPath);
                }
            } else {
                @unlink($tempPath);
            }
        }

        // 读取持久化文件中的最终唯一密钥，确保所有常驻 Worker 密钥绝对一致
        if (is_file($secretPath)) {
            $raw = @file_get_contents($secretPath);
            if (is_string($raw) && strlen(trim($raw)) >= 32) {
                $cachedKey = hash('sha256', trim($raw), true);
                return $cachedKey;
            }
        }

        $cachedKey = hash('sha256', $generated, true);
        return $cachedKey;
    }

    /**
     * 解密单个密文字符串。
     *
     * @throws \RuntimeException 当密文格式损坏或认证解密失败时
     */
    public static function decryptSecret(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, self::CIPHER_PREFIX)) {
            return $ciphertext;
        }

        $raw = base64_decode(substr($ciphertext, strlen(self::CIPHER_PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            \App\Syslog::error('SecurityService', 'Malformed encrypted secret: invalid base64 or length');
            throw new \RuntimeException('Malformed encrypted secret payload');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $key = self::getEncryptionKey();
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            \App\Syslog::error('SecurityService', 'Failed to decrypt secret: decryption authentication failed or key mismatch');
            throw new \RuntimeException('Failed to decrypt secret: integrity check failed');
        }

        return $plain;
    }

    /**
     * 批量解密规则列表。若单条损坏则安全忽略并报警，绝不回退密文。
     *
     * @param string[] $list
     * @return string[]
     */
    public static function decryptRulesList(array $list): array
    {
        $result = [];
        foreach ($list as $item) {
            $item = (string) $item;
            try {
                $decrypted = self::decryptSecret($item);
                if ($decrypted !== '') {
                    $result[] = $decrypted;
                }
            } catch (\Throwable $e) {
                \App\Syslog::error('SecurityService', "Skipping corrupt encrypted rule item: " . $e->getMessage());
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * 获取动态内容拒绝规则（支持解密返回明文或原样密文）。
     *
     * @param bool $decrypt 是否自动解密加密的关键词与正则表达式
     * @return array{enabled: bool, keywords: string[], patterns: string[]}
     */
    public static function getContentRules(bool $decrypt = true): array
    {
        $rawRules = null;

        // 1. 优先读取 Config.inc.php 中的静态规则
        $configRules = Config::Get('security')['contentRules'] ?? null;
        if (is_array($configRules) && (!empty($configRules['keywords']) || !empty($configRules['patterns']))) {
            $rawRules = [
                'enabled' => (bool) ($configRules['enabled'] ?? true),
                'keywords' => array_values(array_filter(array_map('trim', (array) ($configRules['keywords'] ?? [])))),
                'patterns' => array_values(array_filter(array_map('trim', (array) ($configRules['patterns'] ?? [])))),
            ];
        }

        // 2. 回退读取 runtime/content_reject_rules.json 持久化镜像
        if ($rawRules === null) {
            $path = CORE_PATH . self::RULES_FILE;
            if (is_file($path)) {
                $content = @file_get_contents($path);
                if ($content) {
                    $data = json_decode($content, true);
                    if (is_array($data) && (!empty($data['keywords']) || !empty($data['patterns']) || isset($data['enabled']))) {
                        $rawRules = [
                            'enabled' => (bool) ($data['enabled'] ?? true),
                            'keywords' => array_values(array_filter(array_map('trim', (array) ($data['keywords'] ?? [])))),
                            'patterns' => array_values(array_filter(array_map('trim', (array) ($data['patterns'] ?? [])))),
                        ];
                    }
                }
            }
        }

        if ($rawRules === null && is_array($configRules)) {
            $rawRules = [
                'enabled' => (bool) ($configRules['enabled'] ?? false),
                'keywords' => (array) ($configRules['keywords'] ?? []),
                'patterns' => (array) ($configRules['patterns'] ?? []),
            ];
        }

        if ($rawRules === null) {
            return [
                'enabled' => false,
                'keywords' => [],
                'patterns' => [],
            ];
        }

        if ($decrypt) {
            return [
                'enabled' => $rawRules['enabled'],
                'keywords' => self::decryptRulesList($rawRules['keywords']),
                'patterns' => self::decryptRulesList($rawRules['patterns']),
            ];
        }

        return $rawRules;
    }

    /**
     * 校验日志或文件文本是否包含违规内容。
     *
     * @param string $content
     * @throws ApiError 当命中违规规则时
     */
    public static function validateContent(string $content): void
    {
        if ($content === '') {
            return;
        }

        $rules = self::getContentRules();
        if (!$rules['enabled']) {
            return;
        }

        foreach ($rules['keywords'] as $keyword) {
            if ($keyword !== '' && stripos($content, $keyword) !== false) {
                throw new ApiError(400, "Content rejected: contains prohibited keyword '{$keyword}'");
            }
        }

        foreach ($rules['patterns'] as $pattern) {
            if ($pattern !== '' && @preg_match($pattern, $content) === 1) {
                throw new ApiError(400, "Content rejected: matches prohibited security pattern");
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function readWafSnapshotBans(): array
    {
        $data = self::loadWafData();
        if (!is_array($data) || !isset($data['bans'])) {
            return [];
        }

        $bansData = $data['bans'];
        if (!is_array($bansData)) {
            return [];
        }

        $now = time();
        $items = [];
        foreach ($bansData as $key => $b) {
            $ip = '';
            $exp = 0;
            if (is_array($b)) {
                $ip = (string) ($b['ip'] ?? (is_string($key) ? $key : ''));
                $exp = (int) ($b['exp'] ?? 0);
            } elseif (is_numeric($b) && is_string($key)) {
                $ip = $key;
                $exp = (int) $b;
            }

            if ($ip !== '' && $exp > $now) {
                // WAF v1.3.0 起快照里每条封禁带 why（形如 probe#57 / cc），
                // 旧快照没有该字段时退回笼统描述，不伪造 bannedAt 之外的信息
                $why = is_array($b) ? trim((string) ($b['why'] ?? '')) : '';
                $items[] = [
                    'ip' => $ip,
                    'reason' => $why === ''
                        ? 'OpenLiteWaf 自动封禁 (CC 或特征触发)'
                        : 'OpenLiteWaf 自动封禁（命中规则 ' . $why . '）',
                    'bannedAt' => $now - 60,
                    'expiresAt' => $exp,
                    'operator' => 'OpenLiteWaf',
                    'source' => 'waf',
                ];
            }
        }

        return $items;
    }

    /**
     * 加载 WAF 拦截统计数据，优先尝试多路径本地快照，失败时回退内部网络请求。
     *
     * @return array<string, mixed>|null
     */
    private static function loadWafData(): ?array
    {
        $candidatePaths = [
            (string) (getenv('WAF_SNAPSHOT_PATH') ?: ''),
            CORE_PATH . '/OpenLiteWaf/data/snapshot.json',
            '/data/openlitewaf/snapshot.json',
            dirname(CORE_PATH) . '/OpenLiteWaf/data/snapshot.json',
        ];

        foreach ($candidatePaths as $path) {
            if ($path !== '' && is_file($path)) {
                $content = @file_get_contents($path);
                if ($content) {
                    $json = json_decode($content, true);
                    if (is_array($json)) {
                        return $json;
                    }
                }
            }
        }

        // 内部网络请求回退（容器内直连 Nginx 容器暴露的 OpenLiteWaf 统计）
        $ch = null;
        try {
            $ch = curl_init('https://nginx/security/stats');
            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . self::wafHost()]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
                $res = curl_exec($ch);
                if (is_string($res) && $res !== '') {
                    $json = json_decode($res, true);
                    if (is_array($json)) {
                        return $json;
                    }
                }
            }
        } catch (\Throwable) {
            // 静默失败，继续降级
        } finally {
            $ch = null;
        }

        return null;
    }

    /**
     * 联动同步封禁至 OpenLiteWaf 快照（仅裸机部署可用：要求应用进程能写 WAF 数据目录）。
     *
     * 容器化部署下该目录在 hyperf 侧是只读挂载，本方法直接返回；且 shared dict 才是
     * 封禁的权威状态、worker 0 每 60 秒用 dict 全量覆盖快照文件，改文件不会即时生效。
     * 边缘封禁只由 WAF 规则产生，应用层封禁由 isIpBanned / 限流中间件负责。
     */
    private static function syncBanToWaf(string $ip, int $expiresAt): void
    {
        $path = self::getWritableWafSnapshotPath();
        if ($path === null) {
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        if (!isset($data['bans']) || !is_array($data['bans'])) {
            $data['bans'] = [];
        }
        $found = false;
        foreach ($data['bans'] as &$b) {
            if (is_array($b) && ($b['ip'] ?? '') === $ip) {
                $b['exp'] = $expiresAt;
                $found = true;
                break;
            }
        }
        unset($b);
        if (!$found) {
            $data['bans'][] = [
                'slot' => count($data['bans']) % 1024,
                'exp' => $expiresAt,
                'ip' => $ip,
            ];
        }
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * OpenLiteWaf 快照文件的可写路径探测（仅裸机部署可用，见 syncBanToWaf 说明）。
     */
    private static function getWritableWafSnapshotPath(): ?string
    {
        $candidates = [
            (string) (getenv('WAF_SNAPSHOT_PATH') ?: ''),
            CORE_PATH . '/OpenLiteWaf/data/snapshot.json',
            '/data/openlitewaf/snapshot.json',
        ];
        foreach ($candidates as $p) {
            if ($p !== '' && is_file($p) && is_writable($p)) {
                return $p;
            }
        }
        return null;
    }
}
