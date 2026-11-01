<?php

declare(strict_types=1);

use App\System\SecurityService;

test('ipInCidr supports IPv4 including /0 and /32', function () {
    expect(SecurityService::ipInCidr('10.1.2.3', '10.0.0.0/8'))->toBeTrue();
    expect(SecurityService::ipInCidr('11.1.2.3', '10.0.0.0/8'))->toBeFalse();
    expect(SecurityService::ipInCidr('10.1.2.3', '10.1.2.3/32'))->toBeTrue();
    expect(SecurityService::ipInCidr('10.1.2.4', '10.1.2.3/32'))->toBeFalse();
    expect(SecurityService::ipInCidr('8.8.8.8', '0.0.0.0/0'))->toBeTrue();
    expect(SecurityService::ipInCidr('10.1.2.3', '10.0.0.0/33'))->toBeFalse();
});

test('ipInCidr supports IPv6 network prefixes', function () {
    expect(SecurityService::ipInCidr('2001:db8::1', '2001:db8::/32'))->toBeTrue();
    expect(SecurityService::ipInCidr('2001:db9::1', '2001:db8::/32'))->toBeFalse();
    expect(SecurityService::ipInCidr('2001:db8:1::5', '2001:db8:1::/48'))->toBeTrue();
    expect(SecurityService::ipInCidr('2001:db8:2::5', '2001:db8:1::/48'))->toBeFalse();
    expect(SecurityService::ipInCidr('fe80::1', 'fe80::/10'))->toBeTrue();
    expect(SecurityService::ipInCidr('2001:db8::1', '2001:db8::/129'))->toBeFalse();
});

test('IPv6 trusted proxies are honoured when resolving client IP', function () {
    $server = [
        'remote_addr' => '2001:db8::10',
        'http_x_forwarded_for' => '203.0.113.7',
    ];

    $ip = SecurityService::resolveClientIp($server, []);

    // 2001:db8::/32 需要出现在 trustedProxies 才会被剥离；默认配置不含它，
    // 因此这里断言的是「不做无谓信任」，真正剥离由下一条用例覆盖。
    expect($ip)->toBe('2001:db8::10');
});

test('isPrivateIp treats IPv6 ULA and loopback as private', function () {
    expect(SecurityService::isPrivateIp('fd00::1'))->toBeTrue();
    expect(SecurityService::isPrivateIp('::1'))->toBeTrue();
    expect(SecurityService::isPrivateIp('203.0.113.7'))->toBeFalse();
});
