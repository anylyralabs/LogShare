<?php

use App\Agent\LogAgent;

beforeAll(function () {
    $mcp = startMockServer(CORE_PATH . '/tests/Fixtures/mcp_server.php', 19200, 500);
    $llm = startMockServer(CORE_PATH . '/tests/Fixtures/llm_server.php', 19700, 500);

    if ($mcp === null || $llm === null) {
        if ($mcp !== null) {
            @posix_kill($mcp['pid'], 15);
        }
        if ($llm !== null) {
            @posix_kill($llm['pid'], 15);
        }
        $GLOBALS['mcp_url'] = null;
        $GLOBALS['llm_url'] = null;
        $GLOBALS['mcp_pid'] = null;
        $GLOBALS['llm_pid'] = null;
        return;
    }

    $GLOBALS['mcp_pid'] = $mcp['pid'];
    $GLOBALS['llm_pid'] = $llm['pid'];
    $GLOBALS['mcp_url'] = $mcp['base'];
    $GLOBALS['llm_url'] = $llm['base'];
});

afterAll(function () {
    if (!empty($GLOBALS['mcp_pid'])) {
        @posix_kill((int) $GLOBALS['mcp_pid'], 15);
    }
    if (!empty($GLOBALS['llm_pid'])) {
        @posix_kill((int) $GLOBALS['llm_pid'], 15);
    }
});

beforeEach(function () {
    $configRef = new ReflectionClass(\App\Config::class);
    $dataProp = $configRef->getProperty('data');
    $this->origData = $dataProp->getValue();

    $data = $this->origData;
    $data['ai'] = [
        'apiKeys' => ['mock-key-1'],
        'baseUrl' => ($GLOBALS['llm_url'] ?? 'http://127.0.0.1:1') . '/v1/chat/completions',
        'model' => 'mock-model',
        'timeout' => 10,
        'mcp' => [
            'webSearch' => ['url' => $GLOBALS['mcp_url'] ?? 'http://127.0.0.1:1'],
        ],
    ];
    $data['cache']['enabled'] = false;
    $dataProp->setValue(null, $data);
});

afterEach(function () {
    $configRef = new ReflectionClass(\App\Config::class);
    $dataProp = $configRef->getProperty('data');
    $dataProp->setValue(null, $this->origData);
});

test('LogAgent runs a full tool loop and streams SSE events', function () {
    skipWithoutMockServer($GLOBALS['llm_url'] ?? null);
    ob_start();
    LogAgent::analyze('some minecraft crash log', ['logId' => 'aB3x9K']);
    $output = ob_get_clean();

    // Thinking trace from the model
    expect($output)->toContain('event: status');
    expect($output)->toContain('"type":"thinking"');

    // Tool invocation event
    expect($output)->toContain('"type":"tool"');
    expect($output)->toContain('web_search_exa');

    // Tool result event
    expect($output)->toContain('"type":"tool_result"');

    // Final answer content (second round)
    expect($output)->toContain('最终分析结果');

    // Completion event
    expect($output)->toContain('event: done');
});

test('LogAgent streams a plain answer when no tools are configured', function () {
    skipWithoutMockServer($GLOBALS['llm_url'] ?? null);
    $configRef = new ReflectionClass(\App\Config::class);
    $dataProp = $configRef->getProperty('data');
    $data = $dataProp->getValue();
    $data['ai']['mcp'] = [];
    $dataProp->setValue(null, $data);

    ob_start();
    LogAgent::analyze('hello log');
    $output = ob_get_clean();

    expect($output)->toContain('Hello');
    expect($output)->toContain(' World');
    expect($output)->toContain('event: done');
    // No tool events when tools are absent
    expect($output)->not->toContain('"type":"tool"');
});