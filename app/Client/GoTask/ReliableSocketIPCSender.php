<?php

declare(strict_types=1);

namespace App\Client\GoTask;

use Hyperf\GoTask\IPC\SocketIPCSender;
use Spiral\Goridge\RPC;

/**
 * 使用 ReliableCoroutineSocketRelay 的 GoTask sender。
 *
 * 继承 SocketIPCSender 以保持 GoTaskConnection 的类型契约，只替换内部 RPC
 * 的中继实现；父类构造的 handler 不会被使用。
 */
final class ReliableSocketIPCSender extends SocketIPCSender
{
    private RPC $handler;

    public function __construct(string $address = '127.0.0.1:6001')
    {
        parent::__construct($address);

        $split = explode(':', $address, 2);
        $relay = count($split) === 1
            ? new ReliableCoroutineSocketRelay($split[0], 0, ReliableCoroutineSocketRelay::SOCK_UNIX)
            : new ReliableCoroutineSocketRelay($split[0], (int) $split[1], ReliableCoroutineSocketRelay::SOCK_TCP);

        $this->handler = new RPC($relay);
    }

    public function call(string $method, $payload, int $flags = 0): mixed
    {
        return $this->handler->call($method, $payload, $flags);
    }
}
