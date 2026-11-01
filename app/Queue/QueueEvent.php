<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * 队列事件上下文。
 *
 * 只承载业务数据与流转控制；重试次数由消费者单独维护。
 */
class QueueEvent
{
    private string $id;
    private string $name;
    /** @var array<string, mixed> */
    private array $payload;
    private bool $propagationStopped = false;
    private ?string $lastError = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $name,
        array $payload = [],
        ?string $id = null
    ) {
        $this->name = $name;
        $this->payload = $payload;
        $this->id = $id ?? uniqid('evt_', true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * 中断后续处理器继续执行。
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function setLastError(?string $error): void
    {
        $this->lastError = $error;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * 序列化为适合投递到 Redis Stream 的数组。
     *
     * @return array<string, string>
     */
    public function toStreamData(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->name,
            'payload' => json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
        ];
    }

    /**
     * 从 Redis Stream 原始字段还原事件对象。
     *
     * @param array<string, mixed> $fields
     */
    public static function fromStreamData(array $fields, ?string $streamId = null): self
    {
        $name = (string) ($fields['event'] ?? 'unknown');
        $rawPayload = (string) ($fields['payload'] ?? '{}');
        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $id = isset($fields['id']) && $fields['id'] !== '' ? (string) $fields['id'] : ($streamId ?? uniqid('evt_', true));

        return new self($name, $payload, $id);
    }
}
