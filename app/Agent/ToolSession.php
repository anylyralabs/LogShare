<?php

declare(strict_types=1);

namespace App\Agent;

use App\Client\MCPClient;

/**
 * Per-analyze() mutable state shared across tool executions.
 *
 * Passed by value (object handle) instead of by-reference arrays: property
 * writes inside tools are visible to the whole session without by-ref
 * signatures, keeping the tool-call chain type-clean.
 */
final class ToolSession
{
    /**
     * Already-initialized MCP clients keyed by endpoint url; reusing them skips
     * the initialize handshake on repeated tool calls within one request.
     *
     * @var array<int|string, MCPClient>
     */
    public array $mcpClients = [];

    /**
     * Filenames already returned via read_log_file in this session; a second
     * read of the same file is answered with a duplicate notice instead of the
     * content, breaking repeated-read loops.
     *
     * @var array<string, bool>
     */
    public array $readFiles = [];

    /**
     * 检索预算计数器（会话级，与提示词「检索策略」的数字口径一致）。
     */
    public int $webSearchCalls = 0;
    public int $githubSearchCalls = 0;
    public int $githubDetailCalls = 0;

    /* ─── 以下为 LogAgent 完整化改造新增的富会话状态（对齐 plan.md §3.4）─────
     * 均为可选项，默认值不影响既有工具调用逻辑；由 AgentRuntime / ToolRegistry
     * 在多轮循环中逐步填充，供结果验证、评分与链路追踪消费。 */

    /**
     * 完整工具调用链：每项形如
     * ['round'=>int,'name'=>string,'args'=>array,'result'=>string,
     *  'ok'=>bool,'retried'=>bool,'durationMs'=>float]。
     *
     * @var array<int, array<string, mixed>>
     */
    public array $toolCallChain = [];

    /**
     * 已核实事实集合（由 ResultValidator 依据调用链回填），
     * 用于标注结论中「有工具证据支撑」的声明。
     *
     * @var array<int, string>
     */
    public array $verifiedFacts = [];

    /**
     * 日志中已锚定并检查过的行号区间（1-indexed，[start,end] 闭区间集合），
     * 由 LogWindowManager 依据 grep/read 命中动态维护。
     *
     * @var array<int, array{0:int,1:int}>
     */
    public array $anchoredRanges = [];

    /** 估算已消耗 token 数（粗粒度按字符数/3 估算，用于预算与压缩触发） */
    public int $estimatedTokens = 0;

    /** 当前日志窗口摘要（注入下一轮 system prompt，随锚点扩散更新） */
    public string $logWindowSummary = '';

    /** 分析完成后由 AnalysisScorer / ResultValidator 回填的整体置信度 */
    public ?float $confidence = null;

    /**
     * 追加一条工具调用链记录（统一入口，避免各调用点字段漂移）。
     *
     * @param array<string, mixed> $args
     */
    public function recordToolCall(
        int $round,
        string $name,
        array $args,
        string $result,
        bool $ok,
        bool $retried,
        float $durationMs
    ): void {
        $this->toolCallChain[] = [
            'round' => $round,
            'name' => $name,
            'args' => $args,
            'result' => $result,
            'ok' => $ok,
            'retried' => $retried,
            'durationMs' => $durationMs,
        ];
    }

    /**
     * 合并登记一个已检查行号区间（1-indexed 闭区间），自动并入重叠/相邻区间。
     */
    public function markRangeChecked(int $start, int $end): void
    {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $all = $this->anchoredRanges;
        $all[] = [$start, $end];
        usort($all, fn($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($all as $range) {
            $s = (int) $range[0];
            $e = (int) $range[1];
            if ($merged !== []) {
                $last = count($merged) - 1;
                // 相邻或重叠（端点相差 1 以内视作连续）则并入前一个区间
                if ($s <= $merged[$last][1] + 1) {
                    $merged[$last][1] = max($merged[$last][1], $e);
                    continue;
                }
            }
            $merged[] = [$s, $e];
        }

        $this->anchoredRanges = $merged;
    }
}
