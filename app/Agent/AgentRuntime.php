<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Llm\LlmGateway;
use App\Agent\Tool\ResultTruncator;
use App\Agent\Tool\StatusSummarizer;

/**
 * 多轮 tool loop 引擎（对齐 plan.md §3.3）。
 *
 * 从 LogAgent::analyze() 抽出循环控制：每轮经 LlmGateway 流式调用、经 ToolRegistry
 * 分发工具（含重试 + 降级），发射与旧实现逐帧一致的 SSE，并落地分层停止条件
 * （无 tool_calls 完成 / 轮次超限 emitLimit / 检索预算耗尽注入收敛提示 /
 * 工具失败走降级不阻塞）。
 *
 * 依赖全部显式注入（gateway/registry/prompt/window/tracer），因此可在无 Swoole、
 * 无网络环境下用替身网关 + 记录型 emitter 完整单测多轮行为。
 */
final class AgentRuntime
{
    private const SSE_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** 检索类工具结果放宽到 32KB，read_log_file 原样回传，其余 12KB（与旧实现一致） */
    private const MAX_RETRIEVAL_RESULT_BYTES = 32000;

    public function __construct(
        private readonly LlmGateway $gateway,
        private readonly ToolRegistry $registry,
        private readonly PromptBuilder $prompt,
        private readonly LogWindowManager $window,
        private readonly AnalysisTracer $tracer,
    ) {
    }

    /**
     * @param array $config ai 配置（用于取 maxToolRounds 等）
     */
    public function run(AgentContext $ctx, ToolSession $session, array $config): AnalysisResult
    {
        $limits = AnalysisMode::limits($ctx->mode);
        $maxRounds = (int) ($config['agent']['maxToolRounds'] ?? $limits['maxRounds']);
        if ($ctx->mode !== AnalysisMode::DEEP) {
            // 非默认模式以模式上限为准，避免 deep 的 50 覆盖 quick/launcher 的收敛意图
            $maxRounds = $limits['maxRounds'];
        }

        $window = $this->window->buildInitialWindow($ctx->content);
        // 初始锚点登记为已检查区间，供后续轮「动态窗口扩散」作为基准
        if ($window->anchorLine !== null) {
            $session->markRangeChecked($window->startLine, $window->endLine);
        }
        $messages = $this->prompt->buildMessages($ctx->content, $ctx->logId, $config, $ctx->mode);

        $this->tracer->start($ctx);

        $fullAnswer = '';
        $success = false;
        $convergenceInjected = false;
        $lastCheckedSummary = '';
        $roundsUsed = 0;

        for ($round = 0; $round < $maxRounds; $round++) {
            $roundsUsed = $round + 1;
            $handler = new RoundHandler($ctx->emitter);
            $this->gateway->stream($messages, $this->registry->schemas(), $handler);

            $roundContent = $handler->content();
            $fullAnswer .= $roundContent;

            $toolCalls = $handler->toolCalls();
            if ($toolCalls === []) {
                $success = true;
                break;
            }

            // 空 name 过滤（与旧实现一致）：无 name 的调用上游会 400，全部无效视为本轮完成
            $toolCalls = array_values(array_filter($toolCalls, static fn($c) => !empty($c['name'])));
            if ($toolCalls === []) {
                $success = true;
                break;
            }

            $messages[] = self::assistantMessage($toolCalls, $handler->reasoning());

            foreach ($toolCalls as $call) {
                $name = (string) $call['name'];
                $arguments = json_decode((string) ($call['arguments'] ?? ''), true);
                if (!is_array($arguments)) {
                    $arguments = [];
                }

                $this->emitTool($ctx, $name, $arguments);

                $result = $this->registry->execute($name, $arguments, $session);

                $this->emitToolResult($ctx, $name, $result->content);
                $session->recordToolCall(
                    $round,
                    $result->producedBy,
                    $arguments,
                    $result->content,
                    $result->ok,
                    $result->retried,
                    $result->durationMs
                );
                $this->tracer->recordToolCall($name, $arguments, $result);

                // 动态窗口扩散：grep 命中行并入「已检查区间」，反馈下一轮上下文
                if ($name === 'grep_log_file' && $result->ok) {
                    $this->window->recordAnchors($session, self::parseGrepHitLines($result->content));
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'] ?? '',
                    'content' => $this->contentForModel($name, $result->content),
                ];
            }

            // 动态窗口：若本轮新锚点改变了已检查区间，向下一轮注入精炼「已检查区域」摘要
            $checked = $this->window->summary($session);
            if ($checked !== '' && $checked !== $lastCheckedSummary) {
                $messages[] = ['role' => 'system', 'content' => $checked];
                $lastCheckedSummary = $checked;
            }

            // 检索预算软收敛：合计达阈值且尚未注入过 → 追加一条 system 收敛提示
            if (!$convergenceInjected && $this->retrievalBudgetReached($session, $limits)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => '[收敛提示] 本次分析的检索类调用已达预算上限，请基于已有证据输出结论，未核实的方面在结论中明确标注。',
                ];
                $convergenceInjected = true;
            }
        }

        if (!$success) {
            $this->emitLimit($ctx, $maxRounds);
        }

        $validation = (new ResultValidator())->validate($fullAnswer, $session);
        $score = (new AnalysisScorer())->score($fullAnswer, $session, $validation);

        $metrics = $this->tracer->finish($success, $validation, $score);
        $trace = $this->tracer->export();

        return new AnalysisResult(
            fullAnswer: $fullAnswer,
            success: $success,
            rounds: $roundsUsed,
            toolCallChain: $session->toolCallChain,
            cacheKey: $ctx->cacheKey,
            metrics: $metrics,
            validation: $validation->toArray(),
            score: $score->toArray(),
            trace: $trace,
        );
    }

    /**
     * 从 grep_log_file 输出中解析命中行号（行内容形如 "> 142 | ..."）。
     *
     * @return int[] 1-indexed 命中行号
     */
    private static function parseGrepHitLines(string $result): array
    {
        if (preg_match_all('/^>\s*(\d+)\s*\|/m', $result, $m)) {
            return array_map('intval', $m[1]);
        }

        return [];
    }

    private function retrievalBudgetReached(ToolSession $session, array $limits): bool
    {
        return $session->webSearchCalls >= $limits['maxTotalRetrieval'];
    }

    private function contentForModel(string $name, string $result): string
    {
        return match ($name) {
            'read_log_file' => $result,
            'web_search_exa', 'grep_log_file', 'github_search', 'github_get_content'
                => ResultTruncator::truncate($result, self::MAX_RETRIEVAL_RESULT_BYTES),
            default => ResultTruncator::truncate($result),
        };
    }

    /** 组装携带 tool_calls 的 assistant 消息（含 reasoning_content 回传，与旧实现一致） */
    private static function assistantMessage(array $toolCalls, string $reasoning): array
    {
        $formatted = [];
        foreach ($toolCalls as $call) {
            $formatted[] = [
                'id' => $call['id'] ?? '',
                'type' => 'function',
                'function' => [
                    'name' => $call['name'] ?? '',
                    'arguments' => ($call['arguments'] ?? '') !== '' ? $call['arguments'] : '{}',
                ],
            ];
        }

        $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => $formatted];
        if ($reasoning !== '') {
            $message['reasoning_content'] = $reasoning;
        }

        return $message;
    }

    /* ─── SSE 帧发射（与 LogAgent 旧实现逐帧一致）──────────────── */

    private function emitTool(AgentContext $ctx, string $name, array $arguments): void
    {
        $ctx->emitter->emit('status', json_encode(
            ['type' => 'tool', 'name' => $name, 'arguments' => $arguments],
            self::SSE_JSON_FLAGS
        ));
    }

    private function emitToolResult(AgentContext $ctx, string $name, string $result): void
    {
        $summary = StatusSummarizer::summarize($name, $result);
        $ctx->emitter->emit('status', json_encode([
            'type' => 'tool_result',
            'name' => $name,
            'summary' => $summary,
            'truncated' => strlen($result) > strlen($summary),
        ], self::SSE_JSON_FLAGS));
    }

    private function emitLimit(AgentContext $ctx, int $rounds): void
    {
        $ctx->emitter->emit('status', json_encode(
            ['type' => 'limit', 'rounds' => $rounds],
            self::SSE_JSON_FLAGS
        ));
    }
}
