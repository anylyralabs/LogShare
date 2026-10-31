<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\System\AiMetricsService;

beforeEach(function () {
    AiMetricsService::clearMetrics();
    $files = [
        CORE_PATH . '/runtime/ai_queue_paused',
        CORE_PATH . '/runtime/ai_dead_jobs.json',
    ];
    foreach ($files as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    AiMetricsService::setPaused(false);
    AiMetricsService::clearDeadJobs();
});

afterEach(function () {
    AiMetricsService::clearMetrics();
    $files = [
        CORE_PATH . '/runtime/ai_queue_paused',
        CORE_PATH . '/runtime/ai_dead_jobs.json',
    ];
    foreach ($files as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
    AiMetricsService::setPaused(false);
    AiMetricsService::clearDeadJobs();
});

test('AiMetricsService records analysis and computes summary statistics', function () {
    // 模拟记录 3 次成功，1 次失败
    AiMetricsService::recordAnalysis(true, 1200, 3500, 1050, ['web_search_exa'], ['forge', 'paper']);
    AiMetricsService::recordAnalysis(true, 2500, 4200, 1400, ['web_search_exa'], ['forge']);
    AiMetricsService::recordAnalysis(true, 800, 2100, 700, [], []);
    AiMetricsService::recordAnalysis(false, 300, 1000, 0, [], []);

    $metrics = AiMetricsService::getMetrics(7);

    expect($metrics)->toHaveKeys(['summary', 'trends', 'topics', 'durationDistribution']);
    $summary = $metrics['summary'];
    expect($summary['totalRequests'])->toBe(4);
    expect($summary['successRequests'])->toBe(3);
    expect($summary['failedRequests'])->toBe(1);
    expect($summary['successRate'])->toBe(75.0);
    expect($summary['avgDurationMs'])->toBe(1200); // (1200+2500+800+300)/4 = 1200
    expect($summary['p50DurationMs'])->toBeGreaterThan(0);
    expect($summary['estTotalTokens'])->toBeGreaterThan(0);
    expect($summary['ragCalls'])->toBe(0);

    // 检查 Topic 排行
    $topics = $metrics['topics'];
    expect($topics)->not->toBeEmpty();
    expect($topics[0]['topic'])->toBe('forge');
    expect($topics[0]['count'])->toBe(2);

    // 检查耗时分布
    $dist = $metrics['durationDistribution'];
    expect(count($dist))->toBe(4);
});

test('AiMetricsService handles pause and resume state correctly', function () {
    expect(AiMetricsService::isPaused())->toBeFalse();

    AiMetricsService::setPaused(true);
    expect(AiMetricsService::isPaused())->toBeTrue();

    AiMetricsService::setPaused(false);
    expect(AiMetricsService::isPaused())->toBeFalse();
});

test('AiMetricsService manages dead jobs and queue inspection', function () {
    // 初始死信为空
    expect(AiMetricsService::getDeadJobs())->toBeEmpty();

    // 记录两条死信
    AiMetricsService::recordDeadJob('job-dead-001', 'Payload timeout', ['deliveries' => 3]);
    AiMetricsService::recordDeadJob('job-dead-002', 'Syntax error in log payload');

    $deadList = AiMetricsService::getDeadJobs();
    expect(count($deadList))->toBe(2);
    expect($deadList[0]['jobId'])->toBe('job-dead-002');
    expect($deadList[1]['jobId'])->toBe('job-dead-001');

    // 探查队列状态
    $inspection = AiMetricsService::inspectQueue(10);
    expect($inspection)->toHaveKeys(['isPaused', 'enabled', 'depth', 'consumers', 'pendingJobs', 'deadJobs']);
    expect(count($inspection['deadJobs']))->toBe(2);

    // 清理死信
    $cleared = AiMetricsService::clearDeadJobs();
    expect($cleared)->toBe(2);
    expect(AiMetricsService::getDeadJobs())->toBeEmpty();

    // 安全清空积压队列（在无 Redis Streams 连接时优雅返回）
    $flushRes = AiMetricsService::flushQueue();
    expect($flushRes)->toHaveKey('cleared');
});
