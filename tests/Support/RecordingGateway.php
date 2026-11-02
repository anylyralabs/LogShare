<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Client\Mongo\GatewayInterface;

/**
 * 记录写入批次的内存网关，用于验证 MongoStorage 的分批逻辑。
 */
final class RecordingGateway implements GatewayInterface
{
    /** @var list<array{collection: string, documents: list<array<string, mixed>>}> */
    public array $inserts = [];

    public function findOne(string $collection, array $filter, array $options = []): ?array
    {
        return null;
    }

    public function find(string $collection, array $filter, array $options = []): array
    {
        return [];
    }

    public function insertMany(string $collection, array $documents): void
    {
        $this->inserts[] = ['collection' => $collection, 'documents' => $documents];
    }

    public function updateOne(string $collection, array $filter, array $update): int
    {
        return 1;
    }

    public function updateMany(string $collection, array $filter, array $update): int
    {
        return 1;
    }

    public function deleteOne(string $collection, array $filter): int
    {
        return 0;
    }

    public function deleteMany(string $collection, array $filter): int
    {
        return 0;
    }

    public function runCommand(string $database, array $command): array
    {
        return [];
    }

    public function ping(): bool
    {
        return true;
    }
}
