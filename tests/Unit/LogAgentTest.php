<?php

use App\Agent\LogAgent;

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $data = $this->origData;
    $data['storage']['storages']['f'] = [
        'name' => 'Filesystem',
        'class' => '\\App\\Storage\\FilesystemStorage',
        'enabled' => true,
    ];
    $data['storage']['storageId'] = 'f';
    $data['cache']['enabled'] = false;

    $this->tmpDir = CORE_PATH . '/tmp/logshare_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }
});

function agentCall(string $method, array $args = [])
{
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod($method);
    if ($method === 'executeTool') {
        $args[count($args) - 1] = new \App\Agent\ToolSession();
    }
    return $m->invokeArgs(null, $args);
}

test('buildTools returns empty when no mcp endpoints configured', function () {
    $tools = agentCall('buildTools', [[], null]);
    expect($tools)->toBe([]);
});

test('buildTools registers web_search_exa when webSearch url is set', function () {
    $config = ['mcp' => ['webSearch' => ['url' => 'https://mcp.exa.ai/mcp']]];
    $tools = agentCall('buildTools', [$config, null]);

    expect($tools)->toHaveCount(1);
    expect($tools[0]['function']['name'])->toBe('web_search_exa');
    expect($tools[0]['function']['parameters']['required'])->toContain('query');
});

test('assistantMessageWithToolCalls formats tool calls', function () {
    $message = agentCall('assistantMessageWithToolCalls', [[
        ['id' => 'call_1', 'name' => 'web_search_exa', 'arguments' => '{"query":"x"}'],
    ]]);

    expect($message['role'])->toBe('assistant');
    expect($message['content'])->toBeNull();
    expect($message['tool_calls'][0]['id'])->toBe('call_1');
    expect($message['tool_calls'][0]['function']['name'])->toBe('web_search_exa');
});

test('truncateForModel keeps short text intact', function () {
    $result = agentCall('truncateForModel', [str_repeat('a', 500)]);
    expect($result)->toBe(str_repeat('a', 500));
});

test('truncateForModel truncates long text with a visible marker', function () {
    $result = agentCall('truncateForModel', [str_repeat('a', 20000)]);
    expect(strlen($result))->toBeLessThan(20000);
    // 截断必须可见：模型需要知道结果不完整
    expect($result)->toContain('已截断至');
});

test('executeTool returns unknown tool message', function () {
    $result = agentCall('executeTool', ['nope_tool', [], [], null, []]);
    expect($result)->toContain('未知工具');
});

test('executeTool returns not configured message for unset endpoints', function () {
    $result = agentCall('executeTool', ['web_search_exa', ['query' => 'x'], [], null, []]);
    expect($result)->toContain('未配置');
});

test('executeTool degrades gracefully when MCP call fails', function () {
    $config = ['mcp' => ['webSearch' => ['url' => 'http://127.0.0.1:1']]];
    $result = agentCall('executeTool', ['web_search_exa', ['query' => 'x'], $config, null, []]);
    expect($result)->toContain('工具调用失败');
});

test('file tools return not-bound message without a log id', function () {
    $result = agentCall('executeTool', ['list_log_files', [], [], null, []]);
    expect($result)->toContain('未绑定日志文件');

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], null, []]);
    expect($result)->toContain('未绑定日志文件');
});

test('list_log_files lists session files with metadata', function () {
    $log = new \App\Log();
    $id = $log->put(
        "main line 1\nmain line 2\n",
        null,
        [],
        'test',
        [
            ['name' => 'crash-reports/crash-01.txt', 'data' => "java.lang.Error\nat a.b.c\nat c.d.e\n"],
            ['name' => 'debug.txt', 'data' => "GL: OpenGL 3.2\n"],
        ]
    );
    $rawId = $id->get();

    $result = agentCall('executeTool', ['list_log_files', [], [], $rawId, []]);
    expect($result)->toContain('main（主文件');
    expect($result)->toContain('crash-reports/crash-01.txt');
    expect($result)->toContain('debug.txt');
});

test('read_log_file returns full file content by default', function () {
    $log = new \App\Log();
    $id = $log->put("line1\nline2\nline3\n", null, [], null, null);
    $rawId = $id->get();

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], $rawId, []]);
    expect($result)->toContain('共 3 行');
    expect($result)->toContain('line1');
    expect($result)->toContain('line3');
});

test('read_log_file rejects duplicate reads of the same file', function () {
    $log = new \App\Log();
    $id = $log->put("main\n", null, [], null, null);
    $rawId = $id->get();

    // Shared session across both calls (as within one analyze() run)
    $session = new \App\Agent\ToolSession();
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('executeTool');

    $first = $m->invoke(null, 'read_log_file', ['filename' => 'main'], [], $rawId, $session);
    expect($first)->toContain('内容：');

    // Second read with identical arguments: duplicate notice
    $second = $m->invoke(null, 'read_log_file', ['filename' => 'main'], [], $rawId, $session);
    expect($second)->toContain('已读取');
    expect($second)->toContain('内容已在上文');
    expect($second)->toContain('不要重复调用');
});

test('read_log_file dedup treats omitted filename and main as the same key', function () {
    $log = new \App\Log();
    $id = $log->put("body\n", null, [], null, null);
    $rawId = $id->get();

    $session = new \App\Agent\ToolSession();
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('executeTool');

    $first = $m->invoke(null, 'read_log_file', ['filename' => 'main'], [], $rawId, $session);
    expect($first)->toContain('内容：');

    // Omitted filename resolves to main: must hit the duplicate guard
    $second = $m->invoke(null, 'read_log_file', [], [], $rawId, $session);
    expect($second)->toContain('已读取');
    expect($second)->not->toContain('内容：');
});

test('read_log_file returns not found for missing files', function () {
    $log = new \App\Log();
    $id = $log->put("main\n", null, [], null, null);
    $rawId = $id->get();

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'missing.log'], [], $rawId, []]);
    expect($result)->toContain('文件不存在');
});

test('read_log_file does not leak other logs', function () {
    $logA = new \App\Log();
    $idA = $logA->put("secret from A\n", null, [], null, null)->get();

    $logB = new \App\Log();
    $idB = $logB->put("public from B\n", null, [], null, null)->get();

    // Reading "main" under id B must not return A's content
    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], $idB, []]);
    expect($result)->toContain('public from B');
    expect($result)->not->toContain('secret from A');
});
/* ─── 工具描述 / 检索策略 / 预算兜底 / crash-reports 优先 ─── */

function agentSystemPrompt(array $config = [], ?string $logId = null): string
{
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('buildMessages');
    $messages = $m->invoke(null, '测试日志内容', $logId, $config);
    return $messages[0]['content'];
}

test('system prompt uses evidence-driven retrieval', function () {
    $system = agentSystemPrompt();

    expect($system)->toContain('检索策略');
    expect($system)->toContain('不超过 2 次');
    expect($system)->toContain('最多 5 次');
});

test('system prompt guides direct Java Error summary retrieval', function () {
    $system = agentSystemPrompt();
    expect($system)->toContain('Java Error 报错摘要');
    expect($system)->toContain('web_search_exa');
});

test('few-shot example present', function () {
    $system = agentSystemPrompt();
    expect($system)->toContain('MixinApplyError');
    expect($system)->toContain('示例（正确的检索路径）');
});

test('system prompt has crash-report priority', function () {
    $system = agentSystemPrompt();
    expect($system)->toContain('crash-reports');
    expect($system)->toContain('信息密度高于 latest.log');
});

test('web search hard-capped at 5', function () {
    $session = new \App\Agent\ToolSession();
    $session->webSearchCalls = 5;

    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('executeTool');
    // 空配置下若不拦截会返回「未配置」；拦截分支必须返回上限文案且不发起调用
    $result = $m->invoke(null, 'web_search_exa', ['query' => 'x'], [], null, $session);

    expect($result)->toContain('网络搜索次数已达本次分析上限');
    expect($session->webSearchCalls)->toBe(5);
});

test('list_log_files ranks crash reports first', function () {
    $log = new \App\Log();
    $id = $log->put(
        "main\n",
        null,
        [],
        null,
        [
            ['name' => 'debug.txt', 'data' => "d\n"],
            ['name' => 'crash-reports/crash-2024-01-01_12.00.00.txt', 'data' => "c\n"],
            ['name' => 'latest.log', 'data' => "l\n"],
            ['name' => 'crash-reports.txt', 'data' => "x\n"],
        ]
    )->get();

    $result = agentCall('executeTool', ['list_log_files', [], [], $id, []]);
    $lines = array_values(array_filter(explode("\n", $result), fn($l) => str_starts_with($l, '- ')));

    // crash-report 类（含目录形态与裸文件名）置顶并标注 [优先]，其余保持原顺序
    expect($lines[1])->toContain('[优先] crash-reports/crash-2024-01-01_12.00.00.txt');
    expect($lines[2])->toContain('[优先] crash-reports.txt');
    expect($lines[3])->toContain('debug.txt');
    expect($lines[4])->toContain('latest.log');
    expect($lines[3])->not->toContain('[优先]');
});

/* ─── 错误定位与长日志自适应窗口截取测试 ─── */

test('buildInitialLogWindow keeps short log intact', function () {
    $short = "Line 1\nLine 2\nLine 3\n";
    $result = LogAgent::buildInitialLogWindow($short, 12000);
    expect($result)->toBe($short);
});

test('buildInitialLogWindow focuses on error line when log exceeds budget', function () {
    $lines = [];
    for ($i = 1; $i <= 300; $i++) {
        $lines[] = "[$i] [main/INFO]: Normal operational log info line $i with some padding text";
    }
    $lines[200] = "[201] [main/ERROR]: Failed to start server due to unexpected critical exception";
    $lines[201] = "java.lang.NullPointerException: Registry key missing";
    $lines[202] = "Caused by: java.lang.IllegalStateException: Missing block definitions";
    for ($i = 203; $i <= 300; $i++) {
        $lines[$i] = "    at net.minecraft.core.Registry.get(Registry.java:$i)";
    }
    $content = implode("\n", $lines);

    $window = LogAgent::buildInitialLogWindow($content, 6000);

    // 必须包含错误锚点行和根因
    expect($window)->toContain('[main/ERROR]: Failed to start server');
    expect($window)->toContain('Caused by: java.lang.IllegalStateException');
    // 必须包含前文省略标记与定位说明
    expect($window)->toContain('前文已省略第 1 -');
    expect($window)->toContain('已自动定位到第 201 行错误发生处');
    expect(strlen($window))->toBeLessThan(8000);
});

test('buildInitialLogWindow returns null when long log has no error patterns', function () {
    $lines = [];
    for ($i = 1; $i <= 300; $i++) {
        $lines[] = "[$i] [main/INFO]: Server heartbeat normal step $i with quiet environment";
    }
    $content = implode("\n", $lines);

    $window = LogAgent::buildInitialLogWindow($content, 3000);

    // 未检测到错误时返回 null，上层不塞入正文
    expect($window)->toBeNull();
});

test('buildMessages omits body and injects grep keywords when long log has no errors', function () {
    $lines = [];
    for ($i = 1; $i <= 300; $i++) {
        $lines[] = "[$i] [main/INFO]: Normal operational line $i";
    }
    $content = implode("\n", $lines);

    $messages = agentCall('buildMessages', [$content, 'test1234', []]);
    $userMessage = $messages[1]['content'] ?? '';

    // 不包含大段日志正文
    expect($userMessage)->not->toContain('[main/INFO]: Normal operational line 5');
    // 包含概况、基础常用关键词与适可而止思维链
    expect($userMessage)->toContain('待分析日志概况');
    expect($userMessage)->toContain('常用 grep 关键词');
    expect($userMessage)->toContain('ERROR');
    expect($userMessage)->toContain('Caused by');
    expect($userMessage)->toContain('适可而止');
});

test('buildMessages includes focused window when long log has error anchor', function () {
    $lines = [];
    for ($i = 1; $i <= 300; $i++) {
        $lines[] = "[$i] [main/INFO]: Normal operational line $i with sufficient padding to exceed 12kb";
    }
    $lines[200] = "[201] [main/ERROR]: Fatal failure";
    $content = implode("\n", $lines);

    $messages = agentCall('buildMessages', [$content, 'test1234', []]);
    $userMessage = $messages[1]['content'] ?? '';

    expect($userMessage)->toContain('[main/ERROR]: Fatal failure');
    expect($userMessage)->toContain('已自动定位到第 201 行');
});

test('buildMessages includes full body when log is under 12KB', function () {
    $short = "[1] [main/INFO]: short log content here";
    $messages = agentCall('buildMessages', [$short, 'test1234', []]);
    $userMessage = $messages[1]['content'] ?? '';

    expect($userMessage)->toContain($short);
    expect($userMessage)->not->toContain('待分析日志概况');
});

test('findErrorAnchorLine detects Tier 1 and Tier 2 error patterns accurately', function () {
    $lines = [
        "[main/INFO]: starting",
        "[main/WARN]: resource missing",
        "Caused by: org.spongepowered.asm.mixin.transformer.MixinApplyError: Target not found",
        "    at net.minecraft.world.Level.<init>(Level.java:100)",
    ];
    $idx = LogAgent::findErrorAnchorLine($lines);
    expect($idx)->toBe(2);

    $pythonLines = [
        "Starting script...",
        "Traceback (most recent call last):",
        "  File \"main.py\", line 10, in <module>",
    ];
    expect(LogAgent::findErrorAnchorLine($pythonLines))->toBe(1);
});

test('buildTools registers grep_log_file when logId is set', function () {
    $tools = agentCall('buildTools', [[], 'abc1234']);
    $names = array_column(array_column($tools, 'function'), 'name');

    expect($names)->toContain('grep_log_file');
    $grepTool = null;
    foreach ($tools as $tool) {
        if ($tool['function']['name'] === 'grep_log_file') {
            $grepTool = $tool;
            break;
        }
    }
    expect($grepTool)->not->toBeNull();
    expect($grepTool['function']['parameters']['required'])->toBe(['query']);
    expect($grepTool['function']['parameters']['properties'])->toHaveKeys(['query', 'filename', 'case_sensitive', 'context_lines', 'max_matches']);
});

test('grep_log_file validates session binding and arguments', function () {
    // 未绑定日志 ID
    $res1 = agentCall('executeTool', ['grep_log_file', ['query' => 'foo'], [], null, []]);
    expect($res1)->toContain('未绑定日志文件');

    $log = new \App\Log();
    $id = $log->put("line 1\nline 2\n", null, [], null, null);
    $rawId = $id->get();

    // query 为空
    $res2 = agentCall('executeTool', ['grep_log_file', ['query' => ''], [], $rawId, []]);
    expect($res2)->toContain('query 不能为空');

    // 文件不存在
    $res3 = agentCall('executeTool', ['grep_log_file', ['query' => 'foo', 'filename' => 'not_exist.txt'], [], $rawId, []]);
    expect($res3)->toContain('文件不存在');
});

test('grep_log_file matches lines with context and formatting', function () {
    $log = new \App\Log();
    $content = implode("\n", [
        "Line 1: init system",
        "Line 2: loading modules",
        "Line 3: Caused by: MixinApplyError occurred",
        "Line 4: at net.minecraft.core",
        "Line 5: shutdown complete",
    ]);
    $id = $log->put($content, null, [], null, null);
    $rawId = $id->get();

    // 默认忽略大小写，带默认 1 行上下文
    $result = agentCall('executeTool', ['grep_log_file', ['query' => 'mixinapplyerror'], [], $rawId, []]);
    expect($result)->toContain('共找到 1 处匹配');
    expect($result)->toContain('> 3 | Line 3: Caused by: MixinApplyError occurred');
    expect($result)->toContain('  2 | Line 2: loading modules');
    expect($result)->toContain('  4 | Line 4: at net.minecraft.core');
    expect($result)->not->toContain('Line 1: init');

    // 区分大小写：小写找不到
    $caseResult = agentCall('executeTool', ['grep_log_file', ['query' => 'mixinapplyerror', 'case_sensitive' => true], [], $rawId, []]);
    expect($caseResult)->toContain('未找到包含 "mixinapplyerror" 的行');

    // context_lines = 0 只显示命中行
    $noContext = agentCall('executeTool', ['grep_log_file', ['query' => 'MixinApplyError', 'context_lines' => 0], [], $rawId, []]);
    expect($noContext)->toContain('> 3 | Line 3: Caused by: MixinApplyError occurred');
    expect($noContext)->not->toContain('Line 2');
    expect($noContext)->not->toContain('Line 4');
});

test('grep_log_file searches attachment files', function () {
    $log = new \App\Log();
    $id = $log->put(
        "main log content\n",
        null,
        [],
        null,
        [
            ['name' => 'crash.txt', 'data' => "Crash head\nFatal error in mod foo\nStacktrace follows\n"],
        ]
    );
    $rawId = $id->get();

    $result = agentCall('executeTool', ['grep_log_file', ['filename' => 'crash.txt', 'query' => 'Fatal error'], [], $rawId, []]);
    expect($result)->toContain('在文件 crash.txt');
    expect($result)->toContain('> 2 | Fatal error in mod foo');
    expect($result)->toContain('  1 | Crash head');
});

test('grep_log_file handles max_matches truncation and range merging', function () {
    $log = new \App\Log();
    $lines = [];
    for ($i = 1; $i <= 20; $i++) {
        $lines[] = "Row $i: " . ($i % 2 === 0 ? "TARGET_MATCH info" : "normal info");
    }
    $id = $log->put(implode("\n", $lines), null, [], null, null);
    $rawId = $id->get();

    // max_matches 限制为 3
    $result = agentCall('executeTool', ['grep_log_file', ['query' => 'TARGET_MATCH', 'max_matches' => 3], [], $rawId, []]);
    expect($result)->toContain('共找到 10 处匹配（已展示前 3 处）');
    expect($result)->toContain('已达上限 3 处，后续 7 处匹配已省略');
});

test('compact summary formats grep_log_file with match markers', function () {
    $rawGrepResult = implode("\n", [
        '在文件 main（共 100 行）中检索 "MixinApplyError"（忽略大小写）：共找到 2 处匹配：',
        '',
        '  141 | before',
        '> 142 | hit line 1',
        '  143 | after',
        '--',
        '> 180 | hit line 2',
        '  181 | after 2',
    ]);
    $summary = agentCall('buildCompactSummary', ['grep_log_file', $rawGrepResult]);
    expect($summary)->toContain('共找到 2 处匹配');
    expect($summary)->toContain('> 142 | hit line 1');
    expect($summary)->toContain('> 180 | hit line 2');
    expect($summary)->not->toContain('before');
});

test('buildTools registers github tools when github.enabled is true', function () {
    $cfg = [
        'github' => [
            'enabled' => true,
        ],
    ];
    $tools = agentCall('buildTools', [$cfg, null]);
    $names = array_column(array_column($tools, 'function'), 'name');

    expect($names)->toContain('github_list_repos');
    expect($names)->toContain('github_search');
    expect($names)->toContain('github_get_content');
});

test('executeTool dispatches github_list_repos and enforces budget limits', function () {
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('executeTool');

    $session = new \App\Agent\ToolSession();
    $listResult = $m->invoke(null, 'github_list_repos', [], [], null, $session);
    expect($listResult)->toContain('FoldCraftLauncher');

    // 预算限制：githubSearchCalls 上限为 2
    $session->githubSearchCalls = 2;
    $searchResult = $m->invoke(null, 'github_search', ['repo' => 'fcl', 'query' => 'crash'], [], null, $session);
    expect($searchResult)->toContain('GitHub 检索次数已达本次分析上限');

    // 预算限制：githubDetailCalls 上限为 2
    $session->githubDetailCalls = 2;
    $detailResult = $m->invoke(null, 'github_get_content', ['repo' => 'fcl', 'number' => 123], [], null, $session);
    expect($detailResult)->toContain('GitHub 详情阅读次数已达本次分析上限');
});


