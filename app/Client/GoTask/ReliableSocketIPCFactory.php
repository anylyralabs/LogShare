<?php

declare(strict_types=1);

namespace App\Client\GoTask;

use Hyperf\GoTask\Config\DomainConfig;
use Hyperf\GoTask\IPC\SocketIPCSender;
use Hyperf\GoTask\SocketIPCFactory;

/**
 * 把 GoTask 的连接工厂替换为可靠发送实现。
 */
final class ReliableSocketIPCFactory extends SocketIPCFactory
{
    public function __construct(private readonly DomainConfig $domainConfig)
    {
        parent::__construct($domainConfig);
    }

    public function make(): SocketIPCSender
    {
        return new ReliableSocketIPCSender($this->domainConfig->getAddress());
    }
}
