<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\HttpMessage\Server\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS middleware: allow cross-origin requests and answer OPTIONS preflight.
 * Mirrors the old `index.php` behaviour (Access-Control-Allow-Origin: *).
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCorsHeaders(new Response(), $request);
        }

        return $this->withCorsHeaders($handler->handle($request), $request);
    }

    private function withCorsHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            // 减少浏览器对同一接口的高频重复预检（长请求/上传场景每请求一次
            // OPTIONS 的开销可观）；Vary: Origin 为将来收紧 Origin 时的 CDN 缓存留路
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Vary', 'Origin');
    }
}
