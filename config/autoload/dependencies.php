<?php

declare(strict_types=1);

return [
    Hyperf\HttpMessage\Server\RequestParserInterface::class => App\Parser\RequestParser::class,
    Hyperf\GoTask\SocketIPCFactory::class => App\Client\GoTask\ReliableSocketIPCFactory::class,
];
