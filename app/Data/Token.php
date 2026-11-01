<?php

namespace App\Data;

use Random\RandomException;

/**
 * 删除凭证。
 *
 * 同一个类型承担两种角色，由构造来源决定：
 *  - 上传侧：`new Token()` 生成明文，只用于响应体返回给用户；
 *  - 存储侧：`Token::fromHash()` 承载库里的值，永远只做哈希比较。
 *
 * 存储格式为 `sha256:<hex>`。v1.8.4 及更早写入的是**不带前缀**的裸
 * SHA-256，`fromHash()` 同样按哈希处理，并在验证成功后由 `Log` 惰性补前缀。
 *
 * 历史缺陷是 `matches()` 保留了「原文直接比较」的回退分支，使库里的哈希
 * 本身可以当凭证提交。现在两条路径彻底分开：存储侧 Token 只比较哈希，
 * 无论请求方提交的是原文还是哈希，都必须先算出哈希才可能命中。
 */
class Token implements \JsonSerializable
{
    public const HASH_PREFIX = 'sha256:';

    private bool $hashed;

    public function __construct(protected ?string $value = null, bool $hashed = false)
    {
        if ($this->value === null) {
            $this->generate();
            $this->hashed = false;
            return;
        }

        $this->hashed = $hashed || str_starts_with($this->value, self::HASH_PREFIX);
    }

    /**
     * 从存储值构造（哈希形态，可能带或不带前缀）。
     */
    public static function fromHash(?string $storedValue): ?self
    {
        if ($storedValue === null || $storedValue === '') {
            return null;
        }
        return new self($storedValue, true);
    }

    /**
     * 把明文 token 转成存储形态。
     */
    public static function hashValue(string $plaintext): string
    {
        return self::HASH_PREFIX . hash('sha256', $plaintext);
    }

    /**
     * Constant-time comparison.
     *
     * 存储侧（哈希）只比较哈希；上传侧（明文）在测试与内部工具里保留
     * 原文比较能力，但它不参与任何线上鉴权路径。
     *
     * @param string $token
     * @return bool
     */
    public function matches(string $token): bool
    {
        if ($this->value === null || $this->value === '' || $token === '') {
            return false;
        }

        if (!$this->hashed) {
            return hash_equals($this->value, $token);
        }

        return hash_equals($this->normalizedValue(), self::hashValue($token));
    }

    /**
     * 存储值是否为哈希形态。
     */
    public function isHashed(): bool
    {
        return $this->hashed;
    }

    /**
     * 存储值是否已带 `sha256:` 前缀（用于判断是否需要惰性回写升级）。
     */
    public function hasPrefix(): bool
    {
        return is_string($this->value) && str_starts_with($this->value, self::HASH_PREFIX);
    }

    /**
     * 存储值的规范化形态：补上 `sha256:` 前缀。
     */
    public function normalizedValue(): string
    {
        $value = (string) $this->value;
        return str_starts_with($value, self::HASH_PREFIX) ? $value : self::HASH_PREFIX . $value;
    }

    public function jsonSerialize(): string
    {
        return (string) $this->value;
    }

    /**
     * @throws RandomException
     */
    protected function generate(): void
    {
        $this->value = bin2hex(random_bytes(32));
    }

    public function get(): ?string
    {
        return $this->value;
    }
}
