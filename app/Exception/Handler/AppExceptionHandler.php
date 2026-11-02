<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Client\Mongo\MongoUnavailableException;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if ($throwable instanceof MongoUnavailableException) {
            $this->logger->error(sprintf(
                'MongoDB gateway unavailable: %s in %s',
                $throwable->getMessage(),
                $throwable->getFile()
            ));

            return $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Retry-After', '5')
                ->withStatus(503)
                ->withBody(new SwooleStream((string) json_encode([
                    'success' => false,
                    'error' => 'Storage temporarily unavailable.',
                    'code' => 503,
                ], JSON_UNESCAPED_UNICODE)));
        }

        $this->logger->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
        $this->logger->error($throwable->getTraceAsString());

        return $response
            ->withHeader('Server', 'Hyperf')
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(500)
            ->withBody(new SwooleStream((string) json_encode([
                'success' => false,
                'error' => 'Internal Server Error.',
                'code' => 500,
            ], JSON_UNESCAPED_UNICODE)));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
