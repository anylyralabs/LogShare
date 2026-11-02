<?php

declare(strict_types=1);

namespace Tests\Support;

use Hyperf\GoTask\GoTask;
use MongoDB\BSON\Document;

/**
 * 记录 GoTask 调用并返回预置 BSON 的测试替身。
 */
final class RecordingGoTask implements GoTask
{
    /** @var list<array{method: string, payload: array<string, mixed>, raw: string, flags: int}> */
    public array $calls = [];

    /** @var callable(string, array<string, mixed>): mixed */
    private $handler;

    /**
     * @param callable(string, array<string, mixed>): mixed $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function call(string $method, mixed $payload, int $flags = 0): mixed
    {
        $raw = is_string($payload) ? $payload : (string) Document::fromPHP($payload);
        $decoded = Document::fromBSON($raw)->toPHP([
            'root' => 'array',
            'document' => 'array',
            'array' => 'array',
        ]);

        $this->calls[] = [
            'method' => $method,
            'payload' => is_array($decoded) ? $decoded : [],
            'raw' => $raw,
            'flags' => $flags,
        ];

        $result = ($this->handler)($method, is_array($decoded) ? $decoded : []);
        if ($result === null) {
            return '';
        }
        if (is_string($result)) {
            return $result;
        }

        return (string) Document::fromPHP($result);
    }
}
