<?php

declare(strict_types=1);

namespace App\Client\Mongo;

/**
 * MongoDB 网关不可用（边车未启动、socket 断开或连接超时）。
 *
 * 由 HTTP 异常处理器映射为 503，不能静默回退到会阻塞 worker 的原生驱动。
 */
final class MongoUnavailableException extends MongoException
{
}
