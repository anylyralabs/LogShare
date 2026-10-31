<?php

declare(strict_types=1);

namespace App\Agent;

use App\Sse\AnalysisEmitter;

/**
 * 一次分析运行的不可变上下文（对齐 plan.md §3.3 AgentContext）。
 *
 * 把原先散落在 analyze() 局部变量与 \Hyperf\Context 中的请求级状态收敛为显式
 * 依赖：emitter 由外部注入而非藏进协程 Context，使 AgentRuntime 可在无 Swoole
 * 环境下以替身 emitter 单测。
 */
final class AgentContext
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $cacheKey,
        public readonly ?string $logId,
        public readonly AnalysisEmitter $emitter,
        public readonly string $mode = AnalysisMode::DEEP,
        public readonly string $promptVersion = 'v1',
        public readonly int $cacheTTL = 1800,
    ) {
    }
}
