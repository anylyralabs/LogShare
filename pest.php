<?php

declare(strict_types=1);

use Pest\TestCase;
use App\Data\Token;
use App\Filter\Filter;
use App\Filter\UuidFilter;
use App\Filter\XuidFilter;
use App\Filter\SessionTokenFilter;
use App\Filter\ClientIdFilter;
use App\Filter\CoordinateFilter;
use App\Filter\IPv4Filter;
use App\Filter\IPv6Filter;
use App\Filter\IPv6ShortFilter;
use App\Filter\UsernameFilter;
use App\Filter\AccessTokenFilter;
use App\Filter\TrimFilter;

return [
    'test_case' => TestCase::class,
    'bootstraps' => [
        __DIR__ . '/tests/bootstrap.php',
    ],
    'coverage' => [
        'include' => [
            'app/*',
        ],
        // 只排除无法在 CI 静态跑通的扩展封装；Storage / Data / Cache 是
        // 历史上缺陷最集中的层，必须计入覆盖率，不能再整体排除。
        'exclude' => [
            'app/Client/SpinYarnClient.php',
        ],
    ],
    'architectural' => [
        'namespace' => 'App',
        'rules' => [
            'no direct use of \$_SERVER in handlers',
            'no direct use of \$_GET/\$_POST in handlers',
        ],
    ],
];
