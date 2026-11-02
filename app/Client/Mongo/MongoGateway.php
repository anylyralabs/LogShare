<?php

declare(strict_types=1);

namespace App\Client\Mongo;

use Hyperf\Context\ApplicationContext;
use Hyperf\GoTask\GoTask;

/**
 * MongoDB 网关工厂。
 *
 * `mongo.driver` 为 `gotask` 时使用 Go 边车；其余情况（默认 native）使用
 * ext-mongodb。生产由 docker/compose.yaml 显式设置 MONGODB_DRIVER=gotask，
 * 避免本地开发/测试必须安装 Go。
 */
final class MongoGateway
{
    private static ?GatewayInterface $gateway = null;

    public static function get(): GatewayInterface
    {
        if (self::$gateway !== null) {
            return self::$gateway;
        }

        $config = \App\Config::Get('mongo');
        $driver = (string) ($config['driver'] ?? 'native');

        if ($driver === 'gotask') {
            if (!ApplicationContext::hasContainer()) {
                throw new MongoException('GoTask gateway requires a Hyperf container.');
            }

            self::$gateway = new GoTaskGateway(
                ApplicationContext::getContainer()->get(GoTask::class),
                (string) ($config['database'] ?? 'logshare')
            );
        } else {
            self::$gateway = new NativeGateway();
        }

        return self::$gateway;
    }

    /**
     * 替换网关，供单元测试使用。
     */
    public static function set(?GatewayInterface $gateway): void
    {
        self::$gateway = $gateway;
    }

    public static function reset(): void
    {
        self::$gateway = null;
    }
}
