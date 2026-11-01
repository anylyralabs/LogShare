#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('memory_limit', '1G');

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));
! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

require dirname(__DIR__) . '/vendor/autoload.php';

$isProduction = getenv('APP_ENV') === 'prod';
ini_set('display_errors', $isProduction ? 'off' : 'on');
ini_set('display_startup_errors', $isProduction ? 'off' : 'on');

use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;

// Self-called anonymous function that creates its own scope and keep the global namespace clean.
(function () {
    Hyperf\Di\ClassLoader::init();
    /** @var \Hyperf\Di\Container $container */
    $container = new Container((new DefinitionSourceFactory())());
    ApplicationContext::setContainer($container);

    $application = $container->get(Hyperf\Contract\ApplicationInterface::class);
    $application->run();
})();
