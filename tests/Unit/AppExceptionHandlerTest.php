<?php

declare(strict_types=1);

use App\Client\Mongo\MongoUnavailableException;
use App\Exception\Handler\AppExceptionHandler;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpMessage\Server\Response;

test('mongo gateway outage is rendered as 503 with retry hint', function () {
    $logger = Mockery::mock(StdoutLoggerInterface::class);
    $logger->shouldReceive('error')->once();

    $handler = new AppExceptionHandler($logger);
    $response = $handler->handle(new MongoUnavailableException('sidecar down'), new Response());

    expect($response->getStatusCode())->toBe(503);
    expect($response->getHeaderLine('Retry-After'))->toBe('5');
    expect((string) $response->getBody())->toContain('Storage temporarily unavailable.');
});

test('unexpected exceptions remain 500', function () {
    $logger = Mockery::mock(StdoutLoggerInterface::class);
    $logger->shouldReceive('error')->twice();

    $handler = new AppExceptionHandler($logger);
    $response = $handler->handle(new RuntimeException('boom'), new Response());

    expect($response->getStatusCode())->toBe(500);
    expect((string) $response->getBody())->toContain('Internal Server Error.');
});
