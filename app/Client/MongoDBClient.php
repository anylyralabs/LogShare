<?php

declare(strict_types=1);

namespace App\Client;

use App\Config;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;

/**
 * MongoDB 连接与集合访问入口。
 *
 * 注意：ext-mongodb 基于 libmongoc，Swoole 的协程 hook 无法拦截其网络调用，
 * 在 HTTP worker 内调用会是同步阻塞 I/O。当前按业务量选择直连；如果并发
 * 压力上来，再评估 Swoole RemoteObject / 独立存储进程。
 */
class MongoDBClient
{
    protected static ?Client $connection = null;

    protected static ?string $connectionUri = null;

    public static function client(): Client
    {
        $config = Config::Get('mongo');
        $uri = (string) ($config['url'] ?? 'mongodb://127.0.0.1:27017');

        if (self::$connection !== null && self::$connectionUri === $uri) {
            return self::$connection;
        }

        $options = [
            // 数组类型映射让 metadata / files 在读取时直接是 PHP array，
            // 避免 BSONDocument 在 MetadataEntry::fromObject() 中丢失嵌套类型。
            'typeMap' => [
                'root' => 'array',
                'document' => 'array',
                'array' => 'array',
            ],
            'appname' => 'logshare',
            'serverSelectionTimeoutMS' => (int) ($config['serverSelectionTimeoutMs'] ?? 5000),
            'connectTimeoutMS' => (int) ($config['connectTimeoutMs'] ?? 5000),
            'socketTimeoutMS' => (int) ($config['socketTimeoutMs'] ?? 120000),
            'maxPoolSize' => (int) ($config['maxPoolSize'] ?? 32),
        ];

        self::$connection = new Client($uri, $options);
        self::$connectionUri = $uri;

        return self::$connection;
    }

    public static function database(): Database
    {
        $config = Config::Get('mongo');
        $database = (string) ($config['database'] ?? 'logshare');

        return self::client()->selectDatabase($database);
    }

    public static function collection(string $name): Collection
    {
        return self::database()->selectCollection($name);
    }

    public static function ping(): bool
    {
        try {
            self::database()->command(['ping' => 1])->toArray();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 测试用：丢弃进程级连接，让下一次访问重新读取配置。
     */
    public static function resetConnection(): void
    {
        self::$connection = null;
        self::$connectionUri = null;
    }
}
