<?php

declare(strict_types=1);

namespace App\Client\Mongo;

use App\Client\MongoDBClient;

/**
 * ext-mongodb 原生实现。
 *
 * 该实现会同步阻塞当前进程，只能在 CLI、测试或紧急回滚时使用；HTTP worker
 * 应通过 GoTaskGateway 访问 MongoDB。
 */
final class NativeGateway implements GatewayInterface
{
    use NormalizesDocuments;

    public function findOne(string $collection, array $filter, array $options = []): ?array
    {
        return self::asArray(MongoDBClient::collection($collection)->findOne($filter, $options));
    }

    public function find(string $collection, array $filter, array $options = []): array
    {
        $rows = [];
        foreach (MongoDBClient::collection($collection)->find($filter, $options) as $document) {
            $row = self::asArray($document);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function insertMany(string $collection, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        MongoDBClient::collection($collection)->insertMany($documents);
    }

    public function updateOne(string $collection, array $filter, array $update): int
    {
        return MongoDBClient::collection($collection)->updateOne($filter, $update)->getMatchedCount();
    }

    public function updateMany(string $collection, array $filter, array $update): int
    {
        return MongoDBClient::collection($collection)->updateMany($filter, $update)->getMatchedCount();
    }

    public function deleteOne(string $collection, array $filter): int
    {
        return MongoDBClient::collection($collection)->deleteOne($filter)->getDeletedCount();
    }

    public function deleteMany(string $collection, array $filter): int
    {
        return MongoDBClient::collection($collection)->deleteMany($filter)->getDeletedCount();
    }

    public function runCommand(string $database, array $command): array
    {
        $documents = MongoDBClient::client()
            ->selectDatabase($database)
            ->command(self::asDocument($command))
            ->toArray();

        return isset($documents[0]) ? (self::asArray($documents[0]) ?? []) : [];
    }

    public function ping(): bool
    {
        return MongoDBClient::ping();
    }
}
