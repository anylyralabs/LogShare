<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * 分析模式（对齐 plan.md §3.1）。决定工具集、轮次上限与检索预算。
 *
 * - quick：仅非工具直答，1 轮，不检索；
 * - deep：全部工具，最多 50 轮，web≤5、总检索≤6；
 * - launcher：GitHub 优先，20 轮，web≤3、github≤5、总检索≤6。
 */
final class AnalysisMode
{
    public const QUICK = 'quick';
    public const DEEP = 'deep';
    public const LAUNCHER = 'launcher';

    /** @return array{maxRounds:int,maxWebSearch:int,maxTotalRetrieval:int,maxGithubSearch:int} */
    public static function defaultLimits(string $mode): array
    {
        return match ($mode) {
            self::QUICK => [
                'maxRounds' => 1,
                'maxWebSearch' => 0,
                'maxTotalRetrieval' => 0,
                'maxGithubSearch' => 0,
            ],
            self::LAUNCHER => [
                'maxRounds' => 20,
                'maxWebSearch' => 3,
                'maxTotalRetrieval' => 6,
                'maxGithubSearch' => 5,
            ],
            default => [
                'maxRounds' => LogAgent::DEFAULT_MAX_TOOL_ROUNDS,
                'maxWebSearch' => 5,
                'maxTotalRetrieval' => 6,
                'maxGithubSearch' => 5,
            ],
        };
    }

    /** @return array{maxRounds:int,maxWebSearch:int,maxTotalRetrieval:int,maxGithubSearch:int} */
    public static function limits(string $mode): array
    {
        $defaults = self::defaultLimits($mode);
        try {
            $config = \App\Config::Get('ai');
            $custom = $config['agent']['modes'][$mode] ?? null;
            if (is_array($custom)) {
                return [
                    'maxRounds' => isset($custom['maxRounds']) ? (int) $custom['maxRounds'] : $defaults['maxRounds'],
                    'maxWebSearch' => isset($custom['maxWebSearch']) ? (int) $custom['maxWebSearch'] : $defaults['maxWebSearch'],
                    'maxTotalRetrieval' => isset($custom['maxTotalRetrieval']) ? (int) $custom['maxTotalRetrieval'] : $defaults['maxTotalRetrieval'],
                    'maxGithubSearch' => isset($custom['maxGithubSearch']) ? (int) $custom['maxGithubSearch'] : $defaults['maxGithubSearch'],
                ];
            }
        } catch (\Throwable) {
            // fallback
        }
        return $defaults;
    }

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, [self::QUICK, self::DEEP, self::LAUNCHER], true);
    }

    /**
     * 获取所有模式的详细信息及当前配置参数（供 Admin API 使用）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allModes(): array
    {
        $currentMode = (string) (\App\Config::Get('ai')['agent']['mode'] ?? self::DEEP);

        return [
            [
                'mode' => self::DEEP,
                'name' => '深度排障模式',
                'description' => '启用全部工具链与动态日志窗口扩散，最多 50 轮往返，具备完整的证据溯源与质量评分。',
                'active' => $currentMode === self::DEEP,
                'defaultLimits' => self::defaultLimits(self::DEEP),
                'limits' => self::limits(self::DEEP),
            ],
            [
                'mode' => self::LAUNCHER,
                'name' => '启动器优先模式',
                'description' => '面向 Pojav/FCL/HMCL 等启动器及渲染器崩溃，优先检索 GitHub，上限 20 轮。',
                'active' => $currentMode === self::LAUNCHER,
                'defaultLimits' => self::defaultLimits(self::LAUNCHER),
                'limits' => self::limits(self::LAUNCHER),
            ],
            [
                'mode' => self::QUICK,
                'name' => '极速直答模式',
                'description' => '单轮非工具直答，不调用任何外部工具与文件探查，极速响应，适合轻量诊断。',
                'active' => $currentMode === self::QUICK,
                'defaultLimits' => self::defaultLimits(self::QUICK),
                'limits' => self::limits(self::QUICK),
            ],
        ];
    }
}
