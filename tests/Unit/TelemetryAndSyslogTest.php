<?php

declare(strict_types=1);

use App\Syslog;
use App\System\SystemLogManager;
use App\Telemetry\TelemetryService;
use Tests\Mocks\RedisMock;

beforeEach(function () {
    RedisMock::reset();
    SystemLogManager::clearLogs();
    TelemetryService::clearStats();
});

test('Syslog writes structured log to Redis and file', function () {
    Syslog::info('TestComp', 'This is an info test');
    Syslog::warning('TestComp', 'This is a warning test');
    Syslog::error('TestComp', 'This is an error test');

    $logs = SystemLogManager::getLogs(10);
    expect($logs)->toBeArray();
    expect(count($logs))->toBeGreaterThanOrEqual(3);

    // Latest log first
    expect($logs[0]['message'])->toBe('This is an error test');
    expect($logs[0]['level'])->toBe('error');
    expect($logs[0]['component'])->toBe('TestComp');

    // Filter by level
    $errorOnly = SystemLogManager::getLogs(10, 'error');
    foreach ($errorOnly as $item) {
        expect($item['level'])->toBe('error');
    }

    // Filter by keyword
    $keywordLogs = SystemLogManager::getLogs(10, null, 'warning test');
    expect(count($keywordLogs))->toBe(1);
    expect($keywordLogs[0]['message'])->toContain('warning test');

    // Clear logs
    $cleared = SystemLogManager::clearLogs();
    expect($cleared)->toBeTrue();
    $afterClear = SystemLogManager::getLogs(10);
    expect($afterClear)->toBeEmpty();
});

test('TelemetryService records API metrics and computes stats', function () {
    $batch = [
        [
            'type' => 'api',
            'endpoint' => '/v1/log',
            'method' => 'POST',
            'duration' => 45.5,
            'status' => 200,
        ],
        [
            'type' => 'api',
            'endpoint' => '/v1/log',
            'method' => 'POST',
            'duration' => 55.5,
            'status' => 200,
        ],
        [
            'type' => 'api',
            'endpoint' => '/v1/analyse',
            'method' => 'POST',
            'duration' => 620.0, // slow query
            'status' => 500,
        ],
        [
            'type' => 'web_vitals',
            'name' => 'FCP',
            'value' => 850.2,
            'rating' => 'good',
        ],
        [
            'type' => 'web_vitals',
            'name' => 'LCP',
            'value' => 2600.0,
            'rating' => 'needs-improvement',
        ],
        [
            'type' => 'error',
            'message' => 'Uncaught TypeError: test error',
            'stack' => 'at Object.foo (app.js:1:2)',
            'url' => 'https://logshare.cn/test',
        ],
    ];

    $processed = TelemetryService::recordBatch($batch);
    expect($processed)->toBe(6);

    $stats = TelemetryService::getStats();
    expect($stats)->toBeArray();
    expect($stats)->toHaveKeys(['overview', 'endpoints', 'web_vitals', 'hourly_trends', 'recent_slow', 'recent_errors']);

    // Overview assertions
    $overview = $stats['overview'];
    expect($overview['total_requests'])->toBe(3);
    // (45.5 + 55.5 + 620.0) / 3 = 721 / 3 = 240.3
    expect($overview['avg_duration'])->toBe(240.3);
    expect($overview['status_distribution']['2xx'])->toBe(2);
    expect($overview['status_distribution']['5xx'])->toBe(1);
    expect($overview['error_count'])->toBe(1);
    expect($overview['total_vitals'])->toBe(2);

    // Endpoints ranking assertions
    $endpoints = $stats['endpoints'];
    expect($endpoints)->not->toBeEmpty();
    // /v1/log had 2 requests, /v1/analyse had 1
    expect($endpoints[0]['endpoint'])->toBe('/v1/log');
    expect($endpoints[0]['count'])->toBe(2);
    expect($endpoints[0]['avg_duration'])->toBe(50.5);

    // Web vitals assertions
    $vitals = $stats['web_vitals'];
    expect($vitals['FCP']['count'])->toBe(1);
    expect($vitals['FCP']['avg_value'])->toBe(850.2);
    expect($vitals['FCP']['ratings']['good'])->toBe(1);

    expect($vitals['LCP']['count'])->toBe(1);
    expect($vitals['LCP']['avg_value'])->toBe(2600.0);
    expect($vitals['LCP']['ratings']['needs_improvement'])->toBe(1);

    // Recent slow queries
    expect($stats['recent_slow'])->not->toBeEmpty();
    expect($stats['recent_slow'][0]['endpoint'])->toBe('/v1/analyse');
    expect($stats['recent_slow'][0]['duration'])->toEqual(620.0);

    // Recent errors
    expect($stats['recent_errors'])->not->toBeEmpty();
    expect($stats['recent_errors'][0]['message'])->toContain('TypeError: test error');

    // Clear stats
    $cleared = TelemetryService::clearStats();
    expect($cleared)->toBeTrue();
});
