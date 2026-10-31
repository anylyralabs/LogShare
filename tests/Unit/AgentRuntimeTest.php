<?php

use App\Agent\AgentContext;
use App\Agent\AgentRuntime;
use App\Agent\AnalysisMode;
use App\Agent\AnalysisResult;
use App\Agent\LogWindowManager;
use App\Agent\PromptBuilder;
use App\Agent\ToolRegistry;
use App\Agent\ToolSession;
use App\Agent\AnalysisTracer;
use App\Agent\Llm\LlmGateway;
use App\Agent\Llm\LlmStreamHandler;
use App\Sse\AnalysisEmitter;

/**
 * 脚本化的假网关：按调用轮次依次吐出预设的 content/reasoning/tool_calls。
 * 允许测试断言多轮 tool loop 行为而不触达 AIClient 或 Swoole。
 */
final class ScriptedGateway implements LlmGateway
{
    /** @var array<int, array{content?:string,reasoning?:array<int,string>,toolCalls?:array<int,array<string,mixed>>}> */
    public array $script;
    public int $calls = 0;
    /** 记录每次 stream() 收到的 messages/tools，用于断言上下文演进 */
    public array $observedMessages = [];
    public array $observedTools = [];

    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function stream(array $messages, array $tools, LlmStreamHandler $handler): void
    {
        $this->observedMessages[] = $messages;
        $this->observedTools[] = $tools;

        $step = $this->script[$this->calls] ?? ['content' => ''];
        $this->calls++;

        foreach ($step['reasoning'] ?? [] as $r) {
            $handler->onReasoningDelta($r);
        }
        if (isset($step['content'])) {
            $handler->onContentDelta($step['content']);
        }
        if (!empty($step['toolCalls'])) {
            $handler->onToolCalls($step['toolCalls'], implode('', $step['reasoning'] ?? []));
        }
    }
}

/** 记录型 emitter：把 emit 帧原样收集，供逐帧对比。 */
final class RecordingEmitter implements AnalysisEmitter
{
    /** @var array<int, array{event:string,data:string}> */
    public array $frames = [];
    public bool $finished = false;
    public ?string $finishEvent = null;
    public ?string $finishData = null;

    public function begin(): void
    {
    }

    public function emit(string $event, string $data): void
    {
        $this->frames[] = ['event' => $event, 'data' => $data];
    }

    public function finish(string $event, string $data): void
    {
        $this->finished = true;
        $this->finishEvent = $event;
        $this->finishData = $data;
    }

    /** 返回所有 status 帧的 type 序列，用于快速断言帧种类 */
    public function statusTypes(): array
    {
        $out = [];
        foreach ($this->frames as $f) {
            if ($f['event'] !== 'status') {
                continue;
            }
            $decoded = json_decode($f['data'], true);
            if (is_array($decoded) && isset($decoded['type'])) {
                $out[] = $decoded['type'];
            }
        }
        return $out;
    }
}

/** 简易工具：按注入闭包返回文本，记录调用次数。 */
function rtTool(string $name, callable $fn): App\Agent\Tool\AbstractTool {
    return new class($name, $fn) extends App\Agent\Tool\AbstractTool {
        public int $calls = 0;
        public function __construct(private string $n, private $f) {}
        public function name(): string { return $this->n; }
        protected function description(): string { return 'x'; }
        protected function parameterSchema(): array { return $this->objectSchema([]); }
        public function run(array $arguments, ToolSession $session): string {
            $this->calls++;
            return ($this->f)($arguments, $session);
        }
    };
}

function rtRegistry(): ToolRegistry
{
    $r = new ToolRegistry();
    $r->overrideSleeper(static fn () => null);
    return $r;
}

function rtBuild(ToolRegistry $registry, LlmGateway $gateway, ?ToolSession $session = null): array
{
    $emitter = new RecordingEmitter();
    $runtime = new AgentRuntime(
        $gateway,
        $registry,
        new PromptBuilder(),
        new LogWindowManager(),
        new AnalysisTracer()
    );
    return [$runtime, $emitter];
}

test('AgentRuntime emits content delta frames and completes without tools', function () {
    $gateway = new ScriptedGateway([
        ['content' => 'Hello ', 'reasoning' => ['think-1']],
    ]);
    [$runtime, $emitter] = rtBuild(rtRegistry(), $gateway);
    $ctx = new AgentContext(
        content: "short log",
        cacheKey: null,
        logId: null,
        emitter: $emitter,
    );

    $result = $runtime->run($ctx, new ToolSession(), []);

    expect($result)->toBeInstanceOf(AnalysisResult::class);
    expect($result->success)->toBeTrue();
    expect($result->rounds)->toBe(1);
    expect($result->fullAnswer)->toBe('Hello ');

    // thinking 帧 + content 帧（顺序：reasoning 先，content 后）
    $types = $emitter->statusTypes();
    expect($types)->toContain('thinking');
    $contentFrames = array_values(array_filter($emitter->frames, fn($f) => $f['event'] === ''));
    expect(count($contentFrames))->toBe(1);
    $decoded = json_decode($contentFrames[0]['data'], true);
    expect($decoded['choices'][0]['delta']['content'])->toBe('Hello ');
});

test('AgentRuntime runs multi-round tool loop with assistant + tool messages', function () {
    $registry = rtRegistry();
    $grep = rtTool('grep_log_file', fn ($args) => '在文件 main（共 5 行）中检索 "Foo"（忽略大小写）：共找到 1 处匹配：');
    $read = rtTool('read_log_file', fn ($args) => '文件 main（共 5 行，100 字节；本次行区间=1-5）');
    $registry->register($grep);
    $registry->register($read);

    $gateway = new ScriptedGateway([
        [
            'reasoning' => ['step-1'],
            'toolCalls' => [['id' => 'c1', 'name' => 'grep_log_file', 'arguments' => '{"query":"Foo"}']],
        ],
        [
            'reasoning' => ['step-2'],
            'toolCalls' => [['id' => 'c2', 'name' => 'read_log_file', 'arguments' => '{"filename":"main","line_start":1,"line_end":5"}']],
        ],
        ['content' => '结论：Foo 异常'],
    ]);
    [$runtime, $emitter] = rtBuild($registry, $gateway);
    $ctx = new AgentContext(
        content: "some log",
        cacheKey: 'k1',
        logId: 'logabc',
        emitter: $emitter,
    );

    $session = new ToolSession();
    $result = $runtime->run($ctx, $session, []);

    expect($result->success)->toBeTrue();
    expect($result->rounds)->toBe(3);
    expect($result->fullAnswer)->toBe('结论：Foo 异常');
    expect($grep->calls)->toBe(1);
    expect($read->calls)->toBe(1);
    expect(count($session->toolCallChain))->toBe(2);
    expect($session->toolCallChain[0]['name'])->toBe('grep_log_file');
    expect($session->toolCallChain[0]['ok'])->toBeTrue();

    // 消息演进：第二轮的 messages 应包含第一轮 assistant + tool
    $secondRoundMessages = $gateway->observedMessages[1];
    $roles = array_column($secondRoundMessages, 'role');
    expect($roles)->toContain('assistant');
    expect($roles)->toContain('tool');
    $toolPositions = array_keys($roles, 'tool', true);
    $lastTool = $secondRoundMessages[end($toolPositions)];
    expect($lastTool['tool_call_id'])->toBe('c1');

    // 第三轮的 messages 中应含第二个 assistant + tool
    $thirdRoundMessages = $gateway->observedMessages[2];
    $toolMsgs = array_values(array_filter($thirdRoundMessages, fn($m) => ($m['role'] ?? '') === 'tool'));
    expect($toolMsgs)->toHaveCount(2);
    expect($toolMsgs[1]['tool_call_id'])->toBe('c2');

    // SSE 帧顺序：thinking → tool → tool_result → thinking → tool → tool_result → content
    $types = $emitter->statusTypes();
    expect($types)->toBe(['thinking', 'tool', 'tool_result', 'thinking', 'tool', 'tool_result']);
    $contentFrames = array_values(array_filter($emitter->frames, fn($f) => $f['event'] === ''));
    expect(count($contentFrames))->toBe(1);
    $decoded = json_decode($contentFrames[0]['data'], true);
    expect($decoded['choices'][0]['delta']['content'])->toBe('结论：Foo 异常');
});

test('AgentRuntime emits limit frame and marks failure when rounds exhausted', function () {
    $registry = rtRegistry();
    $noop = rtTool('grep_log_file', fn () => 'x');
    $registry->register($noop);

    $script = [];
    for ($i = 0; $i < 3; $i++) {
        $script[] = ['toolCalls' => [['id' => "c$i", 'name' => 'grep_log_file', 'arguments' => '{}']]];
    }
    $gateway = new ScriptedGateway($script);
    [$runtime, $emitter] = rtBuild($registry, $gateway);
    $ctx = new AgentContext(
        content: "x",
        cacheKey: null,
        logId: null,
        emitter: $emitter,
        mode: AnalysisMode::QUICK, // maxRounds=1
    );

    $result = $runtime->run($ctx, new ToolSession(), []);

    // quick 模式 maxRounds=1 → 第 0 轮有 tool_calls 但预算已尽 → 循环退出
    expect($result->success)->toBeFalse();
    expect($result->rounds)->toBe(1);
    expect($emitter->statusTypes())->toBe(['tool', 'tool_result', 'limit']);
    $limit = array_values(array_filter($emitter->frames, fn($f) => str_contains($f['data'], '"limit"')));
    expect($limit)->toHaveCount(1);
    $decoded = json_decode($limit[0]['data'], true);
    expect($decoded['rounds'])->toBe(1);
});

test('AgentRuntime injects convergence system message when retrieval budget reached', function () {
    $registry = rtRegistry();
    $web = rtTool('web_search_exa', function ($args, ToolSession $s) {
        $s->webSearchCalls = 6; // 模拟预算耗尽
        return 'web result';
    });
    $registry->register($web);

    $gateway = new ScriptedGateway([
        ['toolCalls' => [['id' => 'c1', 'name' => 'web_search_exa', 'arguments' => '{}']]],
        ['content' => 'done'],
    ]);
    [$runtime, $emitter] = rtBuild($registry, $gateway);
    $ctx = new AgentContext(content: 'x', cacheKey: null, logId: null, emitter: $emitter);

    $result = $runtime->run($ctx, new ToolSession(), []);

    // 第二轮的 messages 应含 [收敛提示]
    $secondMessages = $gateway->observedMessages[1];
    $systemMsgs = array_values(array_filter($secondMessages, fn($m) => ($m['role'] ?? '') === 'system'));
    $hasConverge = false;
    foreach ($systemMsgs as $m) {
        if (str_contains((string) ($m['content'] ?? ''), '收敛提示')) {
            $hasConverge = true;
        }
    }
    expect($hasConverge)->toBeTrue();
    expect($result->success)->toBeTrue();
});

test('AgentRuntime fallback chain keeps single tool failure from blocking analysis', function () {
    $registry = rtRegistry();
    $github = new class extends App\Agent\Tool\AbstractTool {
        public function name(): string { return 'github_search'; }
        protected function description(): string { return 'd'; }
        protected function parameterSchema(): array { return $this->objectSchema([]); }
        public function run(array $arguments, ToolSession $session): string {
            throw new App\Agent\Tool\ToolExecutionException('github down');
        }
        public function retryStrategy(): App\Agent\Tool\RetryStrategy { return App\Agent\Tool\RetryStrategy::none(); }
        public function fallbackTools(): array { return ['web_search_exa']; }
    };
    $web = rtTool('web_search_exa', fn () => 'web fallback hit');
    $registry->register($github);
    $registry->register($web);

    $gateway = new ScriptedGateway([
        ['toolCalls' => [['id' => 'c1', 'name' => 'github_search', 'arguments' => '{}']]],
        ['content' => 'final'],
    ]);
    [$runtime, $emitter] = rtBuild($registry, $gateway);
    $ctx = new AgentContext(content: 'x', cacheKey: null, logId: null, emitter: $emitter);
    $session = new ToolSession();

    $result = $runtime->run($ctx, $session, []);
    expect($result->success)->toBeTrue();
    // tool_result 帧 name 仍是请求的 github_search（对齐 SSE 逐帧一致）
    $toolResultFrames = array_values(array_filter($emitter->frames, fn($f) => str_contains($f['data'], '"tool_result"')));
    expect(count($toolResultFrames))->toBe(1);
    $decoded = json_decode($toolResultFrames[0]['data'], true);
    expect($decoded['name'])->toBe('github_search');
    // 但会话链记录了实际产出者 web_search_exa，供验证/评分使用
    expect($session->toolCallChain[0]['name'])->toBe('web_search_exa');
    expect($web->calls)->toBe(1);
});

test('AgentRuntime writes structured validation and score into AnalysisResult', function () {
    $registry = rtRegistry();
    $grep = rtTool('grep_log_file', fn () => 'matched line content');
    $registry->register($grep);

    $gateway = new ScriptedGateway([
        ['toolCalls' => [['id' => 'c1', 'name' => 'grep_log_file', 'arguments' => '{}']]],
        ['content' => "根因是 Foo\n解决方案：改配置"],
    ]);
    [$runtime, $emitter] = rtBuild($registry, $gateway);
    $ctx = new AgentContext(content: 'x', cacheKey: null, logId: null, emitter: $emitter);

    $result = $runtime->run($ctx, new ToolSession(), []);

    expect($result->validation)->toBeArray()->toHaveKeys(['hasToolEvidence', 'unverifiedClaims', 'sourceTraceability', 'structuredOutput']);
    expect($result->validation['hasToolEvidence'])->toBeTrue();
    expect($result->score)->toBeArray()->toHaveKeys(['toolEfficiency', 'evidenceSufficiency', 'conclusionClarity', 'overall', 'issues']);
    expect($result->score['overall'])->toBeInt();
    expect($result->toolCallChain)->toHaveCount(1);
});

test('AgentRuntime dynamic window expansion records grep anchors and injects summary', function () {
    $registry = rtRegistry();
    $grep = rtTool('grep_log_file', fn () => implode("\n", [
        '在文件 main（共 200 行）中检索 "Foo"（忽略大小写）：共找到 2 处匹配：',
        '',
        '  41 | before',
        '> 42 | foo line one',
        '  43 | after',
        '--',
        '> 100 | foo line two',
        '  101 | trailing',
    ]));
    $registry->register($grep);

    $gateway = new ScriptedGateway([
        ['toolCalls' => [['id' => 'c1', 'name' => 'grep_log_file', 'arguments' => '{}']]],
        ['content' => 'concl'],
    ]);
    [$runtime, $emitter] = rtBuild($registry, $gateway);
    $ctx = new AgentContext(content: 'x', cacheKey: null, logId: null, emitter: $emitter);
    $session = new ToolSession();

    $runtime->run($ctx, $session, []);

    // 会话 anchoredRanges 已合并登记：命中行 42、100，各带 ±1 上下文
    expect($session->anchoredRanges)->toBe([[41, 43], [99, 101]]);
    // 第二轮 messages 应含「已检查区域」system 摘要
    $secondMsgs = $gateway->observedMessages[1];
    $checkedFound = false;
    foreach ($secondMsgs as $m) {
        if (($m['role'] ?? '') === 'system' && str_contains((string) ($m['content'] ?? ''), '[已检查区域]')) {
            $checkedFound = true;
        }
    }
    expect($checkedFound)->toBeTrue();
});
