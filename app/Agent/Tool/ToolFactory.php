<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\AnalysisMode;
use App\Agent\ToolManager;
use App\Agent\ToolRegistry;

/**
 * 工具注册表工厂：按配置与模式构建 ToolRegistry。
 *
 * 注册顺序与 PromptBuilder::buildTools 严格一致，保证 registry->schemas()
 * 与 buildTools 输出逐元素相等（对齐 plan.md §3.2 工具定义一致性要求）。
 */
final class ToolFactory
{
    /**
     * 按配置与模式构建工具注册表。
     *
     * @param array $config ai 配置（含 mcp/github 子键）
     * @param string|null $logId 日志 ID（非 null 时注册文件工具）
     * @param string $mode 分析模式（quick 模式不注册任何工具）
     */
    public static function build(array $config, ?string $logId, string $mode = AnalysisMode::DEEP): ToolRegistry
    {
        $registry = new ToolRegistry();
        $mcp = $config['mcp'] ?? [];

        // 1. web_search_exa
        if (!empty($mcp['webSearch']['url']) && $mode !== AnalysisMode::QUICK && ToolManager::isToolEnabled('web_search_exa', $config)) {
            $registry->register(new WebSearchTool($config, $logId));
        }

        // 2. github_* 工具
        $githubConfig = $config['github'] ?? \App\Config::Get('github');
        $githubAllowed = !empty($githubConfig['enabled']) && $mode !== AnalysisMode::QUICK;
        if ($githubAllowed) {
            if (ToolManager::isToolEnabled('github_list_repos', $config)) {
                $registry->register(new GithubListReposTool($config, $logId));
            }
            if (ToolManager::isToolEnabled('github_search', $config)) {
                $registry->register(new GithubSearchTool($config, $logId));
            }
            if (ToolManager::isToolEnabled('github_get_content', $config)) {
                $registry->register(new GithubGetContentTool($config, $logId));
            }
        }

        // 4. 文件工具
        $fileTools = $logId !== null && $mode !== AnalysisMode::QUICK;
        if ($fileTools) {
            if (ToolManager::isToolEnabled('list_log_files', $config)) {
                $registry->register(new ListLogFilesTool($config, $logId));
            }
            if (ToolManager::isToolEnabled('read_log_file', $config)) {
                $registry->register(new ReadLogTool($config, $logId));
            }
            if (ToolManager::isToolEnabled('grep_log_file', $config)) {
                $registry->register(new GrepLogTool($config, $logId));
            }
        }

        return $registry;
    }

    /**
     * 构建「分发注册表」：无条件登记全部工具，专供 executeTool 语义复用。
     *
     * 与 build()（决定「向模型暴露哪些工具」，受 config/mode 门控）不同，本方法
     * 只负责「按名调度执行」：即便某端点未配置，被调用的工具类仍会在 run() 内部
     * 依据空 endpoint 自行返回「该工具未配置」文案（而非「未知工具」），与旧
     * LogAgent::executeTool 的按名 switch 行为逐字对齐。故此处不做 config 门控。
     */
    public static function buildDispatch(array $config, ?string $logId): ToolRegistry
    {
        $registry = new ToolRegistry();
        $registry->register(new WebSearchTool($config, $logId));
        $registry->register(new GithubListReposTool($config, $logId));
        $registry->register(new GithubSearchTool($config, $logId));
        $registry->register(new GithubGetContentTool($config, $logId));
        $registry->register(new ListLogFilesTool($config, $logId));
        $registry->register(new ReadLogTool($config, $logId));
        $registry->register(new GrepLogTool($config, $logId));

        return $registry;
    }
}
