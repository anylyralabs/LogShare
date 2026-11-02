<?php

declare(strict_types=1);

/**
 * GoTask 边车配置。
 *
 * GoTask 把 MongoDB 等阻塞 I/O 放到独立 Go 进程，PHP worker 只通过 Unix
 * socket 收发 BSON，不再被 libmongoc 的同步网络调用占住。默认关闭，生产
 * 由 docker/compose.yaml 显式设置 GOTASK_ENABLE=true 启用。
 */
$socket = getenv('GOTASK_SOCKET');
if (!is_string($socket) || $socket === '') {
    $socket = BASE_PATH . '/runtime/gotask.sock';
}

$executable = getenv('GOTASK_EXECUTABLE');
if (!is_string($executable) || $executable === '') {
    $executable = BASE_PATH . '/bin/logshare-gotask';
}

return [
    'enable' => filter_var(getenv('GOTASK_ENABLE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'executable' => $executable,
    // 固定路径，保证 master、worker 与 sidecar 使用同一个 socket；不要用
    // Hyperf\GoTask\ConfigProvider::address()，它在多进程下会生成不同随机名。
    'socket_address' => $socket,
    'go_log' => [
        'redirect' => true,
        'level' => 'info',
    ],
    'pool' => [
        'min_connections' => 1,
        'max_connections' => 64,
        'connect_timeout' => 5.0,
        'wait_timeout' => 10.0,
        'heartbeat' => -1,
        'max_idle_time' => 60.0,
    ],
];
