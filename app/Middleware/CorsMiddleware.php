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
        $isAdmin = $this->isAdminRequest($request);

        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCorsHeaders(new Response(), $request, $isAdmin);
        }

        return $this->withCorsHeaders($handler->handle($request), $request, $isAdmin);
    }

    private function isAdminRequest(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        return (bool) preg_match('#^/(?:v?1)/admin(/|$)#', $path);
    }

    private function withCorsHeaders(ResponseInterface $response, ServerRequestInterface $request, bool $isAdmin): ResponseInterface
    {
        if ($isAdmin) {
            // 管理端用 Bearer token：只回显配置里声明的来源，绝不使用通配符，
            // 否则任何页面都能带着已泄露的 token 发起跨站读请求。
            $allowed = (array) (\App\Config::Get('urls')['corsOrigins'] ?? []);
            $origin = $request->getHeaderLine('Origin');
            $allow = in_array($origin, $allowed, true) ? $origin : (string) ($allowed[0] ?? '');

            return $response
                ->withHeader('Access-Control-Allow-Origin', $allow)
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Admin-Token')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->withHeader('Access-Control-Max-Age', '600')
                ->withHeader('Vary', 'Origin');
        }

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
