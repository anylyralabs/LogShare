<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Agent\AgentContext;
use App\Agent\AnalysisMode;
use App\Agent\AnalysisRecordManager;
use App\Agent\AnalysisResult;
use App\Agent\PromptManager;
use App\Agent\ToolManager;
use App\Config;

beforeEach(function () {
    $logFile = CORE_PATH . '/runtime/logs/ai_analyses.log';
    if (file_exists($logFile)) {
        @unlink($logFile);
    }

    // 控制器级用例需要容器（respondSuccess 经由 Hyperf Response 取用）
    if (!\Hyperf\Context\ApplicationContext::hasContainer()) {
        $container = \Mockery::mock(\Psr\Container\ContainerInterface::class);
        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('get')->andReturnUsing(function ($class) {
            if ($class === \Hyperf\HttpServer\Contract\RequestInterface::class) {
                return new \Hyperf\HttpServer\Request();
            }
            if ($class === \Hyperf\HttpServer\Response::class) {
                return new \Hyperf\HttpServer\Response();
            }
            return null;
        });
        \Hyperf\Context\ApplicationContext::setContainer($container);
    }
});

afterEach(function () {
    $logFile = CORE_PATH . '/runtime/logs/ai_analyses.log';
    if (file_exists($logFile)) {
        @unlink($logFile);
    }
});

test('AnalysisRecordManager records and lists analyses with filtering', function () {
    $emitter = new class implements \App\Sse\AnalysisEmitter {
        public function begin(): void {}
        public function emit(string $event, string $data): void {}
        public function finish(string $event, string $data): void {}
    };
    $ctx = new AgentContext('sample log content', 'sample_cache_key_1', 'log_123', $emitter, AnalysisMode::DEEP, 'v1');
    $result = new AnalysisResult(
        cacheKey: 'sample_cache_key_1',
        success: true,
        rounds: 2,
        toolCallChain: [['name' => 'web_search_exa']],
        metrics: ['durationMs' => 2500],
        validation: [
            'structuredOutput' => [
                'rootCause' => 'NullPointerException in Mod A',
            ],
        ],
        score: [
            'overall' => 88.5,
            'toolEfficiency' => 90.0,
            'evidenceSufficiency' => 85.0,
            'conclusionClarity' => 90.0,
        ],
        fullAnswer: 'Diagnosed crash: NullPointerException in Mod A.',
        trace: ['model' => 'test-model', 'startedAt' => '2026-09-22T00:00:00Z']
    );

    AnalysisRecordManager::record($result, $ctx);

    $detail = AnalysisRecordManager::get('sample_cache_key_1');
    expect($detail)->not->toBeNull();
    expect($detail['id'])->toBe('sample_cache_key_1');
    expect($detail['mode'])->toBe('deep');
    expect($detail['score']['overall'])->toBe(88.5);

    $trace = AnalysisRecordManager::getTrace('sample_cache_key_1');
    expect($trace)->toBeArray();
    expect($trace['model'])->toBe('test-model');

    $list = AnalysisRecordManager::list(['mode' => 'deep'], 1, 10);
    expect($list['total'])->toBeGreaterThanOrEqual(1);

    $scoreboard = AnalysisRecordManager::getScoreboard(7);
    expect($scoreboard)->toHaveKeys(['totalAnalyses', 'averageScore', 'modeDistribution']);

    $slow = AnalysisRecordManager::getSlowAnalyses(10, 1000.0);
    expect($slow)->toBeArray();

    $deleted = AnalysisRecordManager::delete('sample_cache_key_1');
    expect($deleted)->toBeTrue();
});

test('analysis detail, trace and delete accept the percent-encoded cacheKey the console sends', function () {
    // 线上真实 cacheKey 形如 ai:analysis:<logId>（见 AbstractController::runAiAnalysis），
    // 控制台 encodeURIComponent 后冒号变成 %3A，而 FastRoute 的 dispatch 不解码路径参数
    // （实测返回的仍是 ai%3Aanalysis%3A…）。控制器不还原就会 404：列表正常、
    // 详情与 Trace 全挂，正是这次修掉的缺陷。
    $key = 'ai:analysis:s1a2b3c';
    $emitter = new class implements \App\Sse\AnalysisEmitter {
        public function begin(): void {}
        public function emit(string $event, string $data): void {}
        public function finish(string $event, string $data): void {}
    };
    $ctx = new AgentContext('log content', $key, 's1a2b3c', $emitter, AnalysisMode::DEEP, 'v1');
    $result = new AnalysisResult(
        cacheKey: $key,
        success: true,
        rounds: 1,
        toolCallChain: [],
        metrics: ['durationMs' => 120],
        validation: [],
        score: ['overall' => 70.0, 'toolEfficiency' => 70.0, 'evidenceSufficiency' => 70.0, 'conclusionClarity' => 70.0],
        fullAnswer: 'ok',
        trace: ['model' => 'test-model', 'startedAt' => '2026-09-24T00:00:00Z']
    );
    AnalysisRecordManager::record($result, $ctx);

    expect(AnalysisRecordManager::get(rawurlencode($key)))->toBeNull();

    $controller = new \App\Controller\AdminController();
    \Hyperf\Context\Context::set(
        \Psr\Http\Message\ServerRequestInterface::class,
        new \Hyperf\HttpMessage\Server\Request('GET', '/v1/admin/ai/analyses')
    );
    (new \ReflectionProperty($controller, 'request'))->setValue($controller, new \Hyperf\HttpServer\Request());

    $detail = $controller->getAnalysis(rawurlencode($key));
    expect($detail->getStatusCode())->toBe(200);
    $body = json_decode((string) $detail->getBody(), true);
    expect($body['data']['id'] ?? $body['id'] ?? null)->toBe($key);

    expect($controller->getAnalysisTrace(rawurlencode($key))->getStatusCode())->toBe(200);
    $del = $controller->deleteAnalysis(rawurlencode($key));
    expect($del->getStatusCode())->toBe(200);
    // 删除按解码后的键执行，响应回显的也必须是解码形态（否则审计日志里是不成对的键）
    $delBody = json_decode((string) $del->getBody(), true);
    expect($delBody['data']['cacheKey'] ?? $delBody['cacheKey'] ?? null)->toBe($key);
});

test('scoreboard aggregates every record in the window, not just the first page', function () {
    // 回归：getScoreboard() 曾以 pageSize=1000 调 list()，而 list() 默认把页大小夹到
    // 100，导致 totalAnalyses 恒为 100、均值只统计最新 100 条，days 参数形同失效。
    $emitter = new class implements \App\Sse\AnalysisEmitter {
        public function begin(): void {}
        public function emit(string $event, string $data): void {}
        public function finish(string $event, string $data): void {}
    };

    $seeded = 105;
    foreach (range(1, $seeded) as $i) {
        $key = 'regression_scoreboard_' . $i;
        $ctx = new AgentContext('log content', $key, null, $emitter, AnalysisMode::DEEP, 'v1');
        AnalysisRecordManager::record(new AnalysisResult(
            cacheKey: $key,
            success: true,
            rounds: 1,
            toolCallChain: [],
            metrics: ['durationMs' => 1000],
            validation: [],
            // 全部低分且互不相同：均值与低分计数都能区分「聚合全量」与「只看 100 条」
            score: ['overall' => 10, 'toolEfficiency' => 10, 'evidenceSufficiency' => 10, 'conclusionClarity' => 10],
            fullAnswer: 'low',
            trace: ['model' => 'test-model', 'startedAt' => date('c')]
        ), $ctx);
    }

    $scoreboard = AnalysisRecordManager::getScoreboard(7);
    expect($scoreboard['totalAnalyses'])->toBeGreaterThanOrEqual($seeded);
    expect($scoreboard['lowScoreCount'])->toBeGreaterThanOrEqual($seeded);

    foreach (range(1, $seeded) as $i) {
        AnalysisRecordManager::delete('regression_scoreboard_' . $i);
    }
});

test('PromptManager manages prompt versions and lifecycle', function () {
    $prompts = PromptManager::listPrompts();
    expect($prompts)->toBeArray();
    expect(count($prompts))->toBeGreaterThanOrEqual(1);
    expect($prompts[0]['version'])->toBe('v1');

    $v1 = PromptManager::getPrompt('v1');
    expect($v1['isSystem'])->toBeTrue();
    expect($v1['content'])->toContain('Minecraft');

    // Create a new version
    PromptManager::savePrompt('v-test', 'Custom system prompt for testing.', true);
    $custom = PromptManager::getPrompt('v-test');
    expect($custom['version'])->toBe('v-test');
    expect($custom['content'])->toBe('Custom system prompt for testing.');

    // Activate
    PromptManager::activatePrompt('v-test');
    $activePrompts = PromptManager::listPrompts();
    $found = false;
    foreach ($activePrompts as $p) {
        if ($p['version'] === 'v-test' && $p['active'] === true) {
            $found = true;
            break;
        }
    }
    expect($found)->toBeTrue();

    // Switch back and delete
    PromptManager::activatePrompt('v1');
    PromptManager::deletePrompt('v-test');

    $afterDelete = PromptManager::listPrompts();
    $exists = false;
    foreach ($afterDelete as $p) {
        if ($p['version'] === 'v-test') {
            $exists = true;
            break;
        }
    }
    expect($exists)->toBeFalse();
});

test('ToolManager manages tool availability and configuration', function () {
    $tools = ToolManager::listTools();
    expect($tools)->toBeArray();
    expect(count($tools))->toBe(7);

    expect(ToolManager::isToolEnabled('grep_log_file'))->toBeTrue();

    // Disable a tool
    ToolManager::setToolEnabled('web_search_exa', false);
    expect(ToolManager::isToolEnabled('web_search_exa'))->toBeFalse();

    // Re-enable
    ToolManager::setToolEnabled('web_search_exa', true);
    expect(ToolManager::isToolEnabled('web_search_exa'))->toBeTrue();

    // Update config
    ToolManager::updateToolConfig('grep_log_file', ['maxRetries' => 4]);
    $updatedTools = ToolManager::listTools();
    foreach ($updatedTools as $t) {
        if ($t['name'] === 'grep_log_file') {
            expect($t['maxRetries'])->toBe(4);
        }
    }
});

test('AnalysisMode provides modes and limits', function () {
    $all = AnalysisMode::allModes();
    expect($all)->toBeArray();
    $modes = array_column($all, 'mode');
    expect($modes)->toContain('deep', 'launcher', 'quick');

    $limits = AnalysisMode::limits(AnalysisMode::LAUNCHER);
    expect($limits['maxRounds'])->toBe(20);
});
