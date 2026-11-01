<?php

namespace App\Data;

class MetadataEntry implements \JsonSerializable
{
    public const int MAX_ENTRIES = 100;
    protected const int MAX_KEY_LENGTH = 64;
    protected const int MAX_LABEL_LENGTH = 128;
    protected const int MAX_VALUE_LENGTH = 1024;

    protected ?string $key = null;
    protected mixed $value = null;
    protected ?string $label = null;
    protected bool $visible = true;

    /**
     * @param iterable<mixed>|null $dataArray
     * @return MetadataEntry[]
     */
    public static function allFromArray(?iterable $dataArray): array
    {
        if ($dataArray === null) {
            return [];
        }
        $entries = [];
        $seen = [];
        foreach ($dataArray as $data) {
            if (is_array($data)) {
                $entry = static::fromArray($data);
            } else if (is_object($data)) {
                $entry = static::fromObject($data);
            } else {
                continue;
            }
            if ($entry === null) {
                continue;
            }

            // 存储层只保留每个 key 的首次出现，重复 key 直接丢弃。
            $key = $entry->getKey();
            if ($key !== null && isset($seen[$key])) {
                continue;
            }
            if ($key !== null) {
                $seen[$key] = true;
            }

            $entries[] = $entry;
            if (count($entries) >= static::MAX_ENTRIES) {
                break;
            }
        }
        return $entries;
    }

    /**
     * @param array<string, mixed> $data
     * @return MetadataEntry|null
     */
    public static function fromArray(array $data): ?MetadataEntry
    {
        $entry = new MetadataEntry()->setFromArray($data);
        if (!$entry->isValid()) {
            return null;
        }
        return $entry;
    }

    /**
     * @param object $data
     * @return MetadataEntry|null
     */
    public static function fromObject(object $data): ?MetadataEntry
    {
        $arrayData = get_object_vars($data);
        return static::fromArray($arrayData);
    }

    /**
     * @return array{key: string|null, value: mixed, label: string|null, visible: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            "key" => $this->key,
            "value" => $this->value,
            "label" => $this->label,
            "visible" => $this->visible,
        ];
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(?string $key): static
    {
        if (is_string($key)) {
            $key = trim($key);
            if ($key === '') {
                $this->key = null;
                return $this;
            }
            if (mb_strlen($key, 'UTF-8') > static::MAX_KEY_LENGTH) {
                $key = mb_substr($key, 0, static::MAX_KEY_LENGTH, 'UTF-8');
            }
        }
        $this->key = $key;
        return $this;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     * @return $this
     */
    public function setValue(mixed $value): static
    {
        if (is_string($value)) {
            if (mb_strlen($value, 'UTF-8') > static::MAX_VALUE_LENGTH) {
                // 按字符而非字节截断，避免在多字节字符中间切出非法 UTF-8
                $value = mb_substr($value, 0, static::MAX_VALUE_LENGTH, 'UTF-8');
            }
            $this->value = $value;
            return $this;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || is_null($value)) {
            $this->value = $value;
            return $this;
        }
        $encodedValue = @json_encode($value);
        if ($encodedValue === false) {
            $this->value = null;
            return $this;
        }
        if (mb_strlen($encodedValue, 'UTF-8') > static::MAX_VALUE_LENGTH) {
            // 编码后超长的复合值按字符截断为展示用字符串；截断结果不再是
            // 合法 JSON，仅用于展示（读取端的类型还原对此原样放行）
            $encodedValue = mb_substr($encodedValue, 0, static::MAX_VALUE_LENGTH, 'UTF-8');
        }
        $this->value = $encodedValue;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getDisplayLabel(): ?string
    {
        return $this->label ?? $this->key;
    }

    public function getDisplayValue(): string
    {
        if ($this->value === null) {
            return '';
        }
        if (is_string($this->value)) {
            return $this->value;
        }
        if (is_bool($this->value)) {
            return $this->value ? 'true' : 'false';
        }
        return (string) $this->value;
    }

    public function setLabel(?string $label): static
    {
        if (is_string($label)) {
            $label = trim($label);
            if ($label === '') {
                $this->label = null;
                return $this;
            }
            if (mb_strlen($label, 'UTF-8') > static::MAX_LABEL_LENGTH) {
                $label = mb_substr($label, 0, static::MAX_LABEL_LENGTH, 'UTF-8');
            }
        }
        $this->label = $label;
        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    /**
     * 公开接口可序列化的条目（过滤掉 visible=false 的隐私字段）。
     *
     * @param iterable<MetadataEntry> $entries
     * @return array<int, array<string, mixed>>
     */
    public static function serializeVisible(iterable $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry->isVisible()) {
                $out[] = $entry->jsonSerialize();
            }
        }
        return $out;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;
        return $this;
    }

    public function isValid(): bool
    {
        return $this->key !== null && $this->key !== '' && $this->value !== null;
    }

    /**
     * @param array<string, mixed> $data
     * @return $this
     */
    public function setFromArray(array $data): static
    {
        if (isset($data['key']) && is_string($data['key'])) {
            $this->setKey($data['key']);
        }
        if (isset($data['value'])) {
            $this->setValue($data['value']);
        }
        if (isset($data['label']) && is_string($data['label'])) {
            $this->setLabel($data['label']);
        }
        if (isset($data['visible'])) {
            $visible = $data['visible'];
            if (is_bool($visible)) {
                $this->setVisible($visible);
            } elseif (is_int($visible) || is_float($visible)) {
                $this->setVisible($visible != 0);
            } elseif (is_string($visible)) {
                $normalized = strtolower(trim($visible));
                if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                    $this->setVisible(true);
                } elseif (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                    $this->setVisible(false);
                }
            }
        }
        return $this;
    }
}
