<?php

use App\Agent\Tool\ToolFactory;
use App\Agent\PromptBuilder;
use App\Agent\ToolSession;

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

test('ToolFactory schema matches PromptBuilder for webSearch only', function () {
    $config = ['mcp' => ['webSearch' => ['url' => 'https://mcp.exa.ai/mcp']]];
    $logId = null;

    $registry = ToolFactory::build($config, $logId);
    $promptBuilder = new PromptBuilder();
    $expected = $promptBuilder->buildTools($config, $logId);

    expect($registry->schemas())->toEqual($expected);
});

test('ToolFactory schema matches PromptBuilder for github enabled', function () {
    $config = ['github' => ['enabled' => true]];
    $logId = null;

    $registry = ToolFactory::build($config, $logId);
    $promptBuilder = new PromptBuilder();
    $expected = $promptBuilder->buildTools($config, $logId);

    expect($registry->schemas())->toEqual($expected);
    expect($registry->names())->toBe(['github_list_repos', 'github_search', 'github_get_content']);
});

test('ToolFactory schema matches PromptBuilder for file tools with logId', function () {
    $log = new \App\Log();
    $id = $log->put(
        "main line 1\nmain line 2\n",
        null,
        [],
        'test',
        [
            ['name' => 'crash-reports/crash-01.txt', 'data' => "java.lang.Error\nat a.b.c\n"],
        ]
    );
    $rawId = $id->get();

    $config = [];
    $registry = ToolFactory::build($config, $rawId);
    $promptBuilder = new PromptBuilder();
    $expected = $promptBuilder->buildTools($config, $rawId);

    expect($registry->schemas())->toEqual($expected);
    expect($registry->names())->toBe(['list_log_files', 'read_log_file', 'grep_log_file']);
});

test('ToolFactory schema matches PromptBuilder for combined config (all tools)', function () {
    $log = new \App\Log();
    $rawId = $log->put("main\n", null, [], null, null)->get();

    // 同时开启 webSearch + github + 文件工具：覆盖全部工具类型
    $config = [
        'mcp' => [
            'webSearch' => ['url' => 'https://mcp.exa.ai/mcp'],
        ],
        'github' => ['enabled' => true],
    ];

    $registry = ToolFactory::build($config, $rawId);
    $expected = (new PromptBuilder())->buildTools($config, $rawId);

    expect($registry->schemas())->toEqual($expected);
    expect($registry->names())->toBe([
        'web_search_exa',
        'github_list_repos', 'github_search', 'github_get_content',
        'list_log_files', 'read_log_file', 'grep_log_file',
    ]);
});

test('grep_log_file tool returns matching lines', function () {
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

    $config = [];
    $registry = ToolFactory::build($config, $rawId);
    $session = new ToolSession();

    $result = $registry->execute('grep_log_file', ['query' => 'mixinapplyerror'], $session);

    expect($result->ok)->toBeTrue();
    expect($result->content)->toContain('共找到 1 处匹配');
    expect($result->content)->toContain('> 3 | Line 3: Caused by: MixinApplyError occurred');
});

test('web_search_exa budget cap returns limit text', function () {
    $config = ['mcp' => ['webSearch' => ['url' => 'https://mcp.exa.ai/mcp']]];
    $registry = ToolFactory::build($config, null);
    $session = new ToolSession();
    $session->webSearchCalls = 5;

    $result = $registry->execute('web_search_exa', ['query' => 'test'], $session);

    expect($result->content)->toContain('网络搜索次数已达本次分析上限');
    expect($session->webSearchCalls)->toBe(5);
});
