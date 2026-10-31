<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tool\AbstractTool;
use App\Agent\Tool\ToolFactory;
use App\ApiError;
use App\Config;

/**
 * LogAgent 工具链管理服务。
 *
 * 统一管理 ToolRegistry 中各工具的在线启用/禁用状态、
 * 重试预算参数与降级链（Fallback）配置。
 */
final class ToolManager
{
    /**
     * 全部已知核心工具清单与分类。
     */
    public const KNOWN_TOOLS = [
        'web_search_exa' => [
            'name' => 'web_search_exa',
            'title' => 'Exa 互联网实时检索',
            'category' => 'retrieval',
            'requires' => 'ai.mcp.webSearch',
        ],
        'github_list_repos' => [
            'name' => 'github_list_repos',
            'title' => '官方支持启动器/渲染器列表',
            'category' => 'github',
            'requires' => 'github',
        ],
        'github_search' => [
            'name' => 'github_search',
            'title' => 'GitHub Issues/PRs/Discussions 检索',
            'category' => 'github',
            'requires' => 'github',
        ],
        'github_get_content' => [
            'name' => 'github_get_content',
            'title' => 'GitHub 条目详情与修复 PR 查看',
            'category' => 'github',
            'requires' => 'github',
        ],
        'list_log_files' => [
            'name' => 'list_log_files',
            'title' => '附加日志与崩溃报告文件列表',
            'category' => 'log',
            'requires' => 'logId',
        ],
        'read_log_file' => [
            'name' => 'read_log_file',
            'title' => '指定日志区间按行精准读取',
            'category' => 'log',
            'requires' => 'logId',
        ],
        'grep_log_file' => [
            'name' => 'grep_log_file',
            'title' => '日志全量正则与关键词反查',
            'category' => 'log',
            'requires' => 'logId',
        ],
    ];

    /**
     * 判断某个工具是否被启用。
     */
    public static function isToolEnabled(string $name, ?array $config = null): bool
    {
        $toolsConfig = $config['agent']['tools'] ?? (Config::Get('ai')['agent']['tools'] ?? []);
        if (is_array($toolsConfig) && isset($toolsConfig[$name]) && is_array($toolsConfig[$name]) && isset($toolsConfig[$name]['enabled'])) {
            return (bool) $toolsConfig[$name]['enabled'];
        }
        return true;
    }

    /**
     * 列出全部工具状态与策略参数。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listTools(): array
    {
        $config = Config::Get('ai');
        $toolsConfig = $config['agent']['tools'] ?? [];

        // 构建全量分发注册表以反射提取工具描述与降级链
        $dispatchRegistry = ToolFactory::buildDispatch($config, 'sample_log_id');

        $result = [];
        foreach (self::KNOWN_TOOLS as $name => $meta) {
            $tool = $dispatchRegistry->get($name);
            $custom = is_array($toolsConfig) && isset($toolsConfig[$name]) && is_array($toolsConfig[$name])
                ? $toolsConfig[$name]
                : [];

            $enabled = true;
            if (isset($custom['enabled'])) {
                $enabled = (bool) $custom['enabled'];
            }

            $description = $tool !== null ? $tool->definition()['function']['description'] ?? '' : '';
            $retry = $tool?->retryStrategy();
            $maxRetries = isset($custom['maxRetries']) && is_numeric($custom['maxRetries'])
                ? (int) $custom['maxRetries']
                : ($retry !== null ? max(0, $retry->maxAttempts - 1) : 0);

            $fallback = isset($custom['fallback']) && is_array($custom['fallback'])
                ? array_values(array_filter($custom['fallback'], 'is_string'))
                : ($tool?->fallbackTools() ?? []);

            $result[] = [
                'name' => $name,
                'title' => $meta['title'],
                'category' => $meta['category'],
                'requires' => $meta['requires'],
                'enabled' => $enabled,
                'description' => $description,
                'maxRetries' => $maxRetries,
                'fallbackTools' => $fallback,
            ];
        }

        return $result;
    }

    /**
     * 启用或禁用某个工具。
     */
    public static function setToolEnabled(string $name, bool $enabled): void
    {
        if (!isset(self::KNOWN_TOOLS[$name])) {
            throw new ApiError(404, "Tool [{$name}] is not a recognized agent tool.");
        }

        $rawDynamic = Config::getDynamicConfigRaw();
        $agent = $rawDynamic['ai']['agent'] ?? (Config::Get('ai')['agent'] ?? []);
        if (!is_array($agent)) {
            $agent = [];
        }
        $tools = $agent['tools'] ?? [];
        if (!is_array($tools)) {
            $tools = [];
        }

        if (!isset($tools[$name]) || !is_array($tools[$name])) {
            $tools[$name] = [];
        }

        $tools[$name]['enabled'] = $enabled;
        $agent['tools'] = $tools;

        $update = [
            'ai' => [
                'agent' => $agent,
            ],
        ];

        Config::saveDynamic($update);
        Config::touchDynamicConfig();
    }

    /**
     * 调整工具参数（重试次数、fallback 链等）。
     *
     * @param array<string, mixed> $params
     */
    public static function updateToolConfig(string $name, array $params): void
    {
        if (!isset(self::KNOWN_TOOLS[$name])) {
            throw new ApiError(404, "Tool [{$name}] is not a recognized agent tool.");
        }

        $rawDynamic = Config::getDynamicConfigRaw();
        $agent = $rawDynamic['ai']['agent'] ?? (Config::Get('ai')['agent'] ?? []);
        if (!is_array($agent)) {
            $agent = [];
        }
        $tools = $agent['tools'] ?? [];
        if (!is_array($tools)) {
            $tools = [];
        }

        if (!isset($tools[$name]) || !is_array($tools[$name])) {
            $tools[$name] = [];
        }

        if (isset($params['enabled'])) {
            $tools[$name]['enabled'] = (bool) $params['enabled'];
        }

        if (isset($params['maxRetries'])) {
            $tools[$name]['maxRetries'] = max(0, min(10, (int) $params['maxRetries']));
        }

        if (isset($params['fallback']) && is_array($params['fallback'])) {
            $tools[$name]['fallback'] = array_values(array_filter($params['fallback'], 'is_string'));
        }

        $agent['tools'] = $tools;

        $update = [
            'ai' => [
                'agent' => $agent,
            ],
        ];

        Config::saveDynamic($update);
        Config::touchDynamicConfig();
    }
}
