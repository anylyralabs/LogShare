<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\ApiError;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ApiExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->stopPropagation();

        // isValid() 已保证仅 ApiError 进入，此处兜底防御
        if (!$throwable instanceof ApiError) {
            return $response;
        }

        return $response
            ->withStatus($throwable->getHttpCode())
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream((string) json_encode($throwable->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ApiError;
    }
}
