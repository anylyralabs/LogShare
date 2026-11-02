<?php

declare(strict_types=1);

namespace App\Client\Mongo;

use Hyperf\GoTask\GoTask;
use MongoDB\BSON\Document;

/**
 * 通过 GoTask Unix socket 访问 MongoDB。
 *
 * Go 边车复用 hyperf/gotask 的 pkg/mongo_client，网络 I/O 与 BSON 编解码在
 * Go 进程内完成；PHP 侧只负责把业务数组编码成 BSON，并把结果解码回数组。
 * 这里不复用 Hyperf 自带的 MongoClient 封装：该封装依赖已被 ext-mongodb 2.x
 * 移除的 fromPHP()/toPHP() 函数，且 FindOne 无文档时会抛出远程错误。
 */
final class GoTaskGateway implements GatewayInterface
{
    use NormalizesDocuments;

    private const TYPE_MAP = [
        'root' => 'array',
        'document' => 'array',
        'array' => 'array',
    ];

    public function __construct(
        private readonly GoTask $goTask,
        private readonly string $database,
    ) {
    }

    public function findOne(string $collection, array $filter, array $options = []): ?array
    {
        $rows = $this->find($collection, $filter, array_replace($options, ['limit' => 1]));

        return $rows[0] ?? null;
    }

    public function find(string $collection, array $filter, array $options = []): array
    {
        $result = $this->call('Find', [
            'Database' => $this->database,
            'Collection' => $collection,
            'Filter' => self::asDocument($filter),
            'Opts' => self::asDocument($options),
        ]);

        if (!is_array($result)) {
            return [];
        }

        $rows = [];
        foreach ($result as $document) {
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

        $this->call('InsertMany', [
            'Database' => $this->database,
            'Collection' => $collection,
            'Records' => $documents,
        ]);
    }

    public function updateOne(string $collection, array $filter, array $update): int
    {
        $result = $this->call('UpdateOne', [
            'Database' => $this->database,
            'Collection' => $collection,
            'Filter' => self::asDocument($filter),
            'Update' => self::asDocument($update),
            'Opts' => new \stdClass(),
        ]);

        return self::intValue(is_array($result) ? ($result['matchedcount'] ?? 0) : 0);
    }

    public function updateMany(string $collection, array $filter, array $update): int
    {
        $result = $this->call('UpdateMany', [
            'Database' => $this->database,
            'Collection' => $collection,
            'Filter' => self::asDocument($filter),
            'Update' => self::asDocument($update),
            'Opts' => new \stdClass(),
        ]);

        return self::intValue(is_array($result) ? ($result['matchedcount'] ?? 0) : 0);
    }

    public function deleteOne(string $collection, array $filter): int
    {
        $result = $this->call('DeleteOne', [
            'Database' => $this->database,
            'Collection' => $collection,
            'Filter' => self::asDocument($filter),
            'Opts' => new \stdClass(),
        ]);

        return self::intValue(is_array($result) ? ($result['n'] ?? 0) : 0);
    }

    public function deleteMany(string $collection, array $filter): int
    {
        $result = $this->call('DeleteMany', [
            'Database' => $this->database,
            'Collection' => $collection,
            'Filter' => self::asDocument($filter),
            'Opts' => new \stdClass(),
        ]);

        return self::intValue(is_array($result) ? ($result['n'] ?? 0) : 0);
    }

    public function runCommand(string $database, array $command): array
    {
        $result = $this->call('RunCommand', [
            'Database' => $database,
            'Command' => self::asDocument($command),
            'Opts' => new \stdClass(),
        ]);

        return is_array($result) ? $result : [];
    }

    public function ping(): bool
    {
        try {
            $result = $this->runCommand($this->database, ['ping' => 1]);
        } catch (MongoException) {
            return false;
        }

        return self::intValue($result['ok'] ?? 0) === 1;
    }

    /**
     * 调用 Go 边车的 MongoProxy 方法。
     *
     * @param array<string, mixed> $payload
     */
    private function call(string $method, array $payload): mixed
    {
        try {
            $encoded = (string) Document::fromPHP($payload);
            $result = $this->goTask->call(
                'MongoProxy.' . $method,
                $encoded,
                GoTask::PAYLOAD_RAW
            );
        } catch (MongoException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::wrapException($e);
        }

        if (!is_string($result) || $result === '') {
            return null;
        }

        try {
            return Document::fromBSON($result)->toPHP(self::TYPE_MAP);
        } catch (\Throwable $e) {
            throw new MongoException('Failed to decode MongoDB gateway response.', 0, $e);
        }
    }

    private static function wrapException(\Throwable $e): MongoException
    {
        $message = strtolower($e->getMessage());
        $unavailable = $e instanceof \Hyperf\Pool\Exception\ConnectionException
            || $e instanceof \Hyperf\GoTask\Exception\InvalidGoTaskConnectionException
            || $e instanceof \Spiral\Goridge\Exceptions\RelayException
            || str_contains($message, 'unable to establish connection')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'server selection timeout')
            || str_contains($message, 'no such file or directory');

        if ($unavailable) {
            return new MongoUnavailableException('MongoDB gateway is unavailable.', 0, $e);
        }

        return new MongoException('MongoDB gateway call failed: ' . $e->getMessage(), 0, $e);
    }

    private static function intValue(mixed $value): int
    {
        if ($value instanceof \MongoDB\BSON\Int64) {
            return (int) (string) $value;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) (string) $value;
        }

        return 0;
    }
}
