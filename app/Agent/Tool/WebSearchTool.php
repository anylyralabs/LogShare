<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\Support\McpClientFactory;
use App\Agent\ToolSession;

final class WebSearchTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'web_search_exa';
    }

    protected function description(): string
    {
        return '搜索互联网，查找新版本 mod/服务端兼容性、小众报错、官方公告等公开问题。'
            . '查询词使用错误类名或报错关键词原文。';
    }

    protected function parameterSchema(): array
    {
        return $this->objectSchema([
            'query' => ['type' => 'string', 'description' => '搜索关键词，使用错误类名或报错关键词原文'],
        ], ['query']);
    }

    public function run(array $arguments, ToolSession $session): string
    {
        if ($session->webSearchCalls >= 5) {
            return '网络搜索次数已达本次分析上限。请基于已有证据完成分析，未能核实的信息在结论中明确标注。';
        }
        $session->webSearchCalls++;

        $endpoint = $this->config['mcp']['webSearch'] ?? [];
        return McpClientFactory::call('web_search_exa', $arguments, $endpoint, $session);
    }

    public function retryStrategy(): RetryStrategy
    {
        return RetryStrategy::network();
    }
}
