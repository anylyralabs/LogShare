<?php

declare(strict_types=1);

namespace App\Client\Mongo;

/**
 * MongoDB 访问抽象。
 *
 * HTTP 请求路径使用 GoTaskGateway，把网络 I/O 交给 Go 边车；CLI、测试和
 * 紧急回滚使用 NativeGateway。业务层只依赖本接口，不直接接触 ext-mongodb。
 */
interface GatewayInterface
{
    /**
     * 查询单个文档；不存在时返回 null。
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function findOne(string $collection, array $filter, array $options = []): ?array;

    /**
     * 查询多个文档。
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function find(string $collection, array $filter, array $options = []): array;

    /**
     * 批量插入文档。
     *
     * @param list<array<string, mixed>> $documents
     */
    public function insertMany(string $collection, array $documents): void;

    /**
     * 更新一个文档，返回匹配数量。
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    public function updateOne(string $collection, array $filter, array $update): int;

    /**
     * 更新多个文档，返回匹配数量。
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    public function updateMany(string $collection, array $filter, array $update): int;

    /**
     * 删除一个文档，返回删除数量。
     *
     * @param array<string, mixed> $filter
     */
    public function deleteOne(string $collection, array $filter): int;

    /**
     * 删除多个文档，返回删除数量。
     *
     * @param array<string, mixed> $filter
     */
    public function deleteMany(string $collection, array $filter): int;

    /**
     * 在指定数据库执行命令。
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function runCommand(string $database, array $command): array;

    /**
     * 探测 MongoDB 是否可用。
     */
    public function ping(): bool;
}
