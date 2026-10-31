<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiError;
use App\Config;
use App\Middleware\RateLimitMiddleware;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use Mockery;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionMethod;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
});

test('RateLimitMiddleware normalizePath collapses dynamic segments', function () {
    expect(RateLimitMiddleware::normalizePath('/v1/raw/abc1234'))->toBe('/v1/raw/*');
    expect(RateLimitMiddleware::normalizePath('/1/raw/xyz'))->toBe('/1/raw/*');
    expect(RateLimitMiddleware::normalizePath('/v1/log/xyz'))->toBe('/v1/log/*');
    expect(RateLimitMiddleware::normalizePath('/v1/insights/test'))->toBe('/v1/insights/*');
    expect(RateLimitMiddleware::normalizePath('/v1/limits'))->toBe('/v1/limits');
});

test('RateLimitMiddleware skips when cache is disabled', function () {
    $cfg = $this->origData;
    $cfg['cache']['enabled'] = false;
    $this->dataProp->setValue(null, $cfg);

    $middleware = new RateLimitMiddleware();
    $request = new Request('GET', '/v1/limits');
    $handler = Mockery::mock(RequestHandlerInterface::class);
    $handler->shouldReceive('handle')->once()->andReturn(new Response());

    $resp = $middleware->process($request, $handler);
    expect($resp)->toBeInstanceOf(\Psr\Http\Message\ResponseInterface::class);
});

test('RateLimitMiddleware resolves client IP according to trusted proxies', function () {
    $method = new ReflectionMethod(RateLimitMiddleware::class, 'clientIp');

    // 1. Not trusted proxy -> returns remote_addr
    $server1 = [
        'remote_addr' => '1.2.3.4',
        'http_x_real_ip' => '5.6.7.8',
    ];
    $ip1 = $method->invoke(null, $server1, ['trustedProxies' => ['127.0.0.1']]);
    expect($ip1)->toBe('1.2.3.4');

    // 2. Trusted proxy -> returns http_x_real_ip
    $server2 = [
        'remote_addr' => '127.0.0.1',
        'http_x_real_ip' => '5.6.7.8',
    ];
    $ip2 = $method->invoke(null, $server2, ['trustedProxies' => ['127.0.0.1']]);
    expect($ip2)->toBe('5.6.7.8');
});

test('RateLimitMiddleware limitsFor returns route specific or default limits', function () {
    $method = new ReflectionMethod(RateLimitMiddleware::class, 'limitsFor');

    $config = [
        'limit' => 500,
        'window' => 60,
        'routes' => [
            '/v1/log' => ['limit' => 20, 'window' => 30],
        ],
    ];

    $limits1 = $method->invoke(null, 'POST', '/v1/log', $config);
    expect($limits1)->toBe([20, 30]);

    $limits2 = $method->invoke(null, 'GET', '/v1/limits', $config);
    expect($limits2)->toBe([500, 60]);
});
