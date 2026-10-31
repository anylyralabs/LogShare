<?php

namespace App\Agent;

use App\Agent\Llm\AIClientGateway;
use App\Agent\Tool\ResultTruncator;
use App\Agent\Tool\StatusSummarizer;
use App\Agent\Tool\ToolFactory;
use App\Sse\AnalysisEmitter;
use App\Sse\SseEmitter;
use Hyperf\HttpServer\Response;

/**
 * LogAgent: 面向外的静态入口 + 逐帧一致的兼容薄壳。
 *
 * 主体循环、提示词装配、工具分发、窗口聚焦、截断与摘要均已下沉到
 * AgentRuntime / PromptBuilder / ToolRegistry / LogWindowManager /
 * ResultTruncator / StatusSummarizer 等单一来源组件（对齐 plan.md §三）。
 * 本类仅保留三件事：
 *   1. analyze()：绑定 emitter、缓存与锁、组装上下文并驱动 AgentRuntime；
 *   2. 一组私有/公开薄委托，维持既有反射测试与外部调用点的行为逐字不变；
 *   3. SSE 收尾帧（缓存命中直答、错误帧）。
 */
class LogAgent
{
    public const DEFAULT_MAX_TOOL_ROUNDS = 50;
    private const MAX_TOOL_RESULT_BYTES = 12000;

    /** SSE 帧统一 JSON 编码 flags：非法 UTF-8 时替换为 U+FFFD，避免 json_encode 返回 false 产生空帧 */
    private const SSE_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** 当前协程的事件发射端（SSE 直写或 Redis Stream），随协程上下文销毁 */
    private const EMITTER_CTX = 'logshare_analysis_emitter';

    /**
     * Run the agent loop and stream the result as SSE.
     *
     * @param string $content The log content to analyse
     * @param array $options Supported keys:
     *                       - cacheKey: string|null
     *                       - cacheTTL: int
     *                       - logId: string|null (bound log id enabling file tools)
     *                       - emitter: AnalysisEmitter|null (default: SseEmitter, direct SSE write)
     */
    public static function analyze(string $content, array $options = [], ?Response $response = null): void
    {
        $cacheKey = $options['cacheKey'] ?? null;
        $cacheTTL = $options['cacheTTL'] ?? 1800;
        $logId = $options['logId'] ?? null;

        // 发射端绑定在协程级 Context（常驻进程下静态会被并发请求串扰，与 SseWriter 同模式）
        $emitter = $options['emitter'] ?? new SseEmitter($response);
        \Hyperf\Context\Context::set(self::EMITTER_CTX, $emitter);
        $emitter->begin();

        // try 边界紧贴 begin()：SSE 开始输出后任何异常都必须以流内 error 收尾，
        // 不能逃逸到全局 JSON handler 造成坏帧。
        // $heldLock 记录本协程持有的缓存锁 key，finally 中无条件释放（幂等），
        // 覆盖异常与提前 return 路径，避免锁只能等 TTL 过期、互斥失效。
        $heldLock = null;
        try {
            if ($cacheKey !== null) {
                $cached = self::checkCache($cacheKey);
                if ($cached !== null) {
                    self::emitContent($cached);
                    self::emitDone();
                    return;
                }
                if (self::acquireCacheLock($cacheKey)) {
                    $heldLock = $cacheKey;
                } else {
                    $cached = self::waitForCache($cacheKey);
                    if ($cached !== null) {
                        self::emitContent($cached);
                        self::emitDone();
                        return;
                    }
                }
            }

            $config = \App\Config::Get('ai');
            $agentConfig = $config['agent'] ?? [];
            $mode = (string) ($agentConfig['mode'] ?? AnalysisMode::DEEP);
            $promptVersion = (string) ($agentConfig['promptVersion'] ?? 'v1');

            // 提示词版本开关（对齐 plan.md §3.1 / 验收「配置切换」）：
            // ai.agent.prompts.<version> 命中时作为 systemPrompt 覆盖注入
            // buildMessages（其已有的 config['systemPrompt'] 覆盖通道）；
            // 未配置则保持内置默认（v1），deep 输出逐字不变。A/B 或回滚只改配置。
            $versionedPrompt = $agentConfig['prompts'][$promptVersion] ?? null;
            if (is_string($versionedPrompt) && $versionedPrompt !== '') {
                $config['systemPrompt'] = $versionedPrompt;
            }

            // 会话级可变状态：MCP 客户端复用 + 已读文件记录（防重复读取循环）
            $session = new ToolSession();

            $runtime = new AgentRuntime(
                new AIClientGateway(),
                ToolFactory::build($config, $logId, $mode),
                (new PromptBuilder())->withVersion($promptVersion),
                new LogWindowManager(),
                new AnalysisTracer()
            );
            $ctx = new AgentContext(
                content: $content,
                cacheKey: $cacheKey,
                logId: $logId,
                emitter: $emitter,
                mode: $mode,
                promptVersion: $promptVersion,
                cacheTTL: $cacheTTL,
            );
            $result = $runtime->run($ctx, $session, $config);
            if ($result->cacheable() && $cacheKey !== null) {
                self::writeCache($cacheKey, $result->fullAnswer, $cacheTTL);
            }

            AnalysisRecordManager::record($result, $ctx);

            self::emitDone();
        } catch (\App\Exception\ClientDisconnectedException $e) {
            // 客户端已断开：SseWriter 无法再写入任何帧，仅记日志并中止，
            // 不再尝试 emitError（同样会写失败）
            \App\Syslog::error('LogAgent', '客户端已断开，分析中止: ' . $e->getMessage());
        } catch (\Throwable $e) {
            \App\Syslog::error('LogAgent', '分析失败: ' . $e->getMessage());
            self::emitError($e->getMessage());
        } finally {
            if ($heldLock !== null) {
                self::releaseCacheLock($heldLock);
            }
        }
    }

    /* ─── 兼容薄委托：保持既有反射测试与外部调用点的行为逐字不变 ─────── */

    private static function buildTools(array $config, ?string $logId): array
    {
        return (new PromptBuilder())->buildTools($config, $logId);
    }

    private static function buildMessages(string $content, ?string $logId, array $config): array
    {
        return (new PromptBuilder())->buildMessages($content, $logId, $config);
    }

    private static function defaultSystemPrompt(?string $logId): string
    {
        return (new PromptBuilder())->defaultSystemPrompt($logId);
    }

    /**
     * 按名分发工具，语义与旧 switch 版逐字一致：未知工具返回「未知工具」，
     * 端点未配置返回「该工具未配置」，硬性参数错误返回可读文本。工具内部预算计数
     * 与去重依赖传入的同一 $session 跨调用累积（注册表每次重建，状态在 session）。
     */
    private static function executeTool(string $name, array $arguments, array $config, ?string $logId, ToolSession $session): string
    {
        return ToolFactory::buildDispatch($config, $logId)
            ->execute($name, $arguments, $session)
            ->content;
    }

    private static function assistantMessageWithToolCalls(array $toolCalls, string $reasoningContent = ''): array
    {
        $formatted = [];
        foreach ($toolCalls as $call) {
            $formatted[] = [
                'id' => $call['id'] ?? '',
                'type' => 'function',
                // 空 arguments 部分网关会 400 拒绝，无参调用归一为 {}
                'function' => [
                    'name' => $call['name'] ?? '',
                    'arguments' => ($call['arguments'] ?? '') !== '' ? $call['arguments'] : '{}',
                ],
            ];
        }

        $message = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $formatted,
        ];
        if ($reasoningContent !== '') {
            $message['reasoning_content'] = $reasoningContent;
        }

        return $message;
    }

    /** 长日志智能聚焦窗口（唯一实现在 LogWindowManager）；命中错误锚点则返回窗口文本，否则 null */
    public static function buildInitialLogWindow(string $content, int $maxBytes = self::MAX_TOOL_RESULT_BYTES): ?string
    {
        return (new LogWindowManager())->buildInitialWindow($content, $maxBytes)->body;
    }

    /** @param string[] $lines */
    public static function findErrorAnchorLine(array $lines): ?int
    {
        return LogWindowManager::findErrorAnchorLine($lines);
    }

    private static function truncateForModel(string $text, int $maxBytes = self::MAX_TOOL_RESULT_BYTES): string
    {
        return ResultTruncator::truncate($text, $maxBytes);
    }

    private static function buildCompactSummary(string $tool, string $result): string
    {
        return StatusSummarizer::compactSummary($tool, $result);
    }

    /* ─── SSE emission（analyze 缓存命中直答 / 错误收尾所需）────────── */

    private static function emitter(): AnalysisEmitter
    {
        $emitter = \Hyperf\Context\Context::get(self::EMITTER_CTX);
        if (!$emitter instanceof AnalysisEmitter) {
            throw new \RuntimeException('analysis emitter not bound');
        }
        return $emitter;
    }

    private static function emitContent(string $delta): void
    {
        self::emitter()->emit('', json_encode(['choices' => [['delta' => ['content' => $delta]]]], self::SSE_JSON_FLAGS));
    }

    private static function emitDone(): void
    {
        self::emitter()->finish('done', '{"status":"completed"}');
    }

    private static function emitError(string $message): void
    {
        self::emitter()->finish('error', json_encode(['error' => $message], self::SSE_JSON_FLAGS));
    }

    /* ─── Cache ────────────────────────────────────────────── */

    private static function checkCache(string $cacheKey): ?string
    {
        try {
            $cached = \App\Cache\RedisCache::Get($cacheKey);
            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        } catch (\Exception $e) {
            \App\Syslog::error('LogAgent Cache', '读取失败: ' . $e->getMessage());
        }
        return null;
    }

    private static function acquireCacheLock(string $cacheKey): bool
    {
        try {
            return \App\Cache\RedisCache::Acquire($cacheKey . ':lock', 120);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function releaseCacheLock(string $cacheKey): void
    {
        try {
            \App\Cache\RedisCache::Delete($cacheKey . ':lock');
        } catch (\Throwable $e) {
        }
    }

    private static function waitForCache(string $cacheKey): ?string
    {
        for ($i = 0; $i < 10; $i++) {
            usleep(100000);
            $cached = self::checkCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        return null;
    }

    private static function writeCache(string $cacheKey, string $content, int $cacheTTL): void
    {
        try {
            \App\Cache\RedisCache::Set($cacheKey, $content, $cacheTTL);
        } catch (\Exception $e) {
            \App\Syslog::error('LogAgent Cache', '写入失败: ' . $e->getMessage());
        }
    }
}
