<?php

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

// hyperf/testing 的属性默认值在类加载（反射）时求值 BASE_PATH，须在此提前定义
! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__));

// 干净 checkout 没有 tmp/（被 .gitignore 忽略），测试与上传解析都需要它
$tmpDir = CORE_PATH . '/tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0700, true);
}

// 扫描注解（填充 AnnotationCollector，供 DispatcherFactory 注册注解路由）
\Hyperf\Di\ClassLoader::init();

// Ensure config is loaded for tests
if (!file_exists(__DIR__ . '/../Config.inc.php')) {
    copy(__DIR__ . '/../Config.inc.example.php', __DIR__ . '/../Config.inc.php');
}

// Load mock classes before aliasing (they are outside the src/ autoloader)
require_once __DIR__ . '/Mocks/RedisMock.php';

// Mock Redis for unit tests
if (!extension_loaded('redis')) {
    class_alias('Tests\Mocks\RedisMock', 'Redis');
}

// Helper functions for tests
function createTestToken(): \App\Data\Token {
    return new \App\Data\Token();
}

function assertFilterRedacts(string $filterClass, string $input, string $expectedNotContains): void {
    $output = $filterClass::filter($input);
    expect($output)->not->toContain($expectedNotContains);
}