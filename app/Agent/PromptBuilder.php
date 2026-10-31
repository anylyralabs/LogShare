<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * 提示词与工具定义的唯一装配来源（对齐 plan.md §3.1 / §3.2）。
 *
 * 从 LogAgent 原样迁移 buildTools()/buildMessages()/defaultSystemPrompt() 三块
 * 逻辑为单一实现，保证 deep 模式输出与迁移前逐字一致（SSE 逐帧一致验收依赖此）。
 * 在此基础上提供按分析模式（quick/deep/launcher）裁剪提示词与工具集的能力，
 * 并支持版本化注入，使提示词可配置切换而无需改动 Agent 主体代码。
 */
final class PromptBuilder
{
    private const MAX_TOOL_RESULT_BYTES = 12000;

    public function __construct(
        private readonly LogWindowManager $window = new LogWindowManager(),
        private readonly string $version = 'v1',
    ) {
    }

    public function version(): string
    {
        return $this->version;
    }

    public function withVersion(string $version): self
    {
        return new self($this->window, $version);
    }

    /** 组装 function-calling 工具定义（与旧 LogAgent::buildTools 完全一致）。 */
    public function buildTools(array $config, ?string $logId, string $mode = AnalysisMode::DEEP): array
    {
        $tools = [];
        $mcp = $config['mcp'] ?? [];

        if (!empty($mcp['webSearch']['url']) && $mode !== AnalysisMode::QUICK && ToolManager::isToolEnabled('web_search_exa', $config)) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search_exa',
                    'description' => '搜索互联网，查找新版本 mod/服务端兼容性、小众报错、官方公告等公开问题。'
                        . '查询词使用错误类名或报错关键词原文。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => '搜索关键词，使用错误类名或报错关键词原文'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ];
        }

        $githubConfig = $config['github'] ?? \App\Config::Get('github');
        $githubAllowed = !empty($githubConfig['enabled']) && $mode !== AnalysisMode::QUICK;
        if ($githubAllowed) {
            if (ToolManager::isToolEnabled('github_list_repos', $config)) {
                $tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'github_list_repos',
                        'description' => '列出官方支持与推荐排障的 Minecraft 启动器与渲染器仓库列表（含官方全名、别名、owner/repo 与适用场景）。'
                            . '不确定启动器仓库名或检索方向时优先调用。',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => new \stdClass(),
                        ],
                    ],
                ];
            }
            if (ToolManager::isToolEnabled('github_search', $config)) {
                $tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'github_search',
                        'description' => '在指定开源启动器或渲染器的 GitHub 仓库中，按关键词联合检索相关的 Issues、Pull Requests（很多崩溃修复记录在 PR 解决说明中）与 Discussions。'
                            . '日志中出现启动器名称（如 FoldCraftLauncher、PojavLauncher 等）或渲染器（MobileGlues、gl4es 等）报错时使用。',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'repo' => [
                                    'type' => 'string',
                                    'description' => '启动器或渲染器名称：支持官方全名（如 FoldCraftLauncher、PojavLauncher、MobileGlues）、常用别名（如 fcl、pojav、mg、hmcl）或规范的 owner/repo（可先用 github_list_repos 查看）',
                                ],
                                'query' => [
                                    'type' => 'string',
                                    'description' => '检索关键词或报错原文信号（如异常类名、SIGSEGV、崩溃特征短语、中文症状）',
                                ],
                                'type' => [
                                    'type' => 'string',
                                    'enum' => ['all', 'issue', 'pr', 'discussion'],
                                    'description' => '检索范围：all（默认，综合检索）、issue（仅工单）、pr（仅合并请求/代码修复）、discussion（仅问答讨论）',
                                ],
                                'state' => [
                                    'type' => 'string',
                                    'enum' => ['all', 'closed', 'open'],
                                    'description' => '状态：all（默认）、closed（已解决/已合并，排障优先）、open（开放中）',
                                ],
                                'max_results' => [
                                    'type' => 'integer',
                                    'description' => '最大返回条目数（1-10，默认 5）',
                                ],
                            ],
                            'required' => ['repo', 'query'],
                        ],
                    ],
                ];
            }
            if (ToolManager::isToolEnabled('github_get_content', $config)) {
                $tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'github_get_content',
                        'description' => '获取指定 Issue、PR 或 Discussion 的详细描述、PR 修复说明与维护者采纳的高质量解答。在通过 github_search 定位到高相关条目后调用。',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'repo' => [
                                    'type' => 'string',
                                    'description' => '启动器全名、别名或 owner/repo',
                                ],
                                'number' => [
                                    'type' => 'integer',
                                    'description' => 'Issue/PR/Discussion 编号',
                                ],
                                'type' => [
                                    'type' => 'string',
                                    'enum' => ['auto', 'issue', 'pr', 'discussion'],
                                    'description' => '条目类型：auto（默认自动识别）、issue、pr、discussion',
                                ],
                            ],
                            'required' => ['repo', 'number'],
                        ],
                    ],
                ];
            }
        }

        $fileTools = $logId !== null && $mode !== AnalysisMode::QUICK;
        if ($fileTools) {
            if (ToolManager::isToolEnabled('list_log_files', $config)) {
                $tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'list_log_files',
                        'description' => '列出当前日志 ID 下的所有文件（含主文件与附加文件）。',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => new \stdClass(),
                        ],
                    ],
                ];
            }
            if (ToolManager::isToolEnabled('read_log_file', $config)) {
                $tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'read_log_file',
                        'description' => '读取当前日志下指定文件的内容。默认返回完整文件；需要控制范围时可使用 line_start/line_end 指定行区间，或使用 offset/max_bytes 指定字节区间。主文件名为 main。',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'filename' => ['type' => 'string', 'description' => '文件名（主文件为 main，或使用 list_log_files 列出的名称）'],
                                'line_start' => ['type' => 'integer', 'description' => '起始行号，从 1 开始；省略则从第 1 行开始'],
                                'line_end' => ['type' => 'integer', 'description' => '结束行号，包含该行；省略则读取到文件末尾'],
                                'offset' => ['type' => 'integer', 'description' => '字节起始位置；使用行区间时不要设置'],
                                'max_bytes' => ['type' => 'integer', 'description' => '字节读取模式下的最大字节数；使用行区间时不要设置'],
                            ],
                            'required' => ['filename'],
                        ],
                    ],
                ];
            }
            if (ToolManager::isToolEnabled('grep_log_file', $config)) {
                $tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'grep_log_file',
                        'description' => '在当前日志的指定文件中按关键词逐行检索（类似 grep），返回匹配行号、行内容与前后上下文。'
                            . '适合定位特定异常、报错关键字、mod ID 或崩溃特征，避免通读超大文件；获取行号后可按需配合 read_log_file 精确读取。',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => '检索关键词或文本短语（如异常类名、模组名、错误关键字）'],
                                'filename' => ['type' => 'string', 'description' => '文件名（主文件为 main，或使用 list_log_files 列出的名称；省略则默认 main）'],
                                'case_sensitive' => ['type' => 'boolean', 'description' => '是否区分大小写，默认 false（忽略大小写）'],
                                'context_lines' => ['type' => 'integer', 'description' => '命中行前后各显示的上下文行数（0-5，默认 1）'],
                                'max_matches' => ['type' => 'integer', 'description' => '最大返回匹配项数（1-30，默认 10）'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                ];
            }
        }

        return $tools;
    }

    /** 组装初始消息（system + user）。deep 模式与旧 LogAgent::buildMessages 逐字一致。 */
    public function buildMessages(
        string $content,
        ?string $logId,
        array $config,
        string $mode = AnalysisMode::DEEP
    ): array {
        $system = $config['systemPrompt'] ?? $this->defaultSystemPrompt($logId);

        if ($mode === AnalysisMode::QUICK) {
            // 快速模式：仅核心身份 + 直答约束，不注入检索策略与示例
            $system .= "\n\n本次为快速分析：不要调用任何工具，直接基于日志本身给出简明判断。";
            return [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "需要分析的日志内容：\n\n" . $content],
            ];
        }

        // 注入管理员统一维护的“已知领域知识”（LLM 无权修改）
        $domainKnowledge = \App\System\DomainKnowledgeManager::formatForPrompt();
        if ($domainKnowledge !== '') {
            $system .= "\n\n" . $domainKnowledge;
        }

        $system .= "\n\n检索与探查策略（深度因果驱动与合理工具调用）：\n"
            . "- 深入探查与线索原则：复杂日志绝非仅看表象错误。除非报错原因极其明确孤立无需上下文，否则必须至少进行一次日志上下文线索查找（如 read_log_file 读取报错发生前后上下文区间、读取相关 crash-reports 附件、或 grep_log_file 检索前置异常与模组加载信息）；切忌仅凭初始截取的单点错误切片草率下结论。\n"
            . "- 报错检索策略：日志报错时直接提取日志里的 Java Error 报错摘要（如 Caused by 后的异常类名、报错消息文本、关键错误特征等）作为 web_search_exa 的 query。\n"
            . "- 启动器与渲染器问题（动态实时排障）：当日志中出现启动器名称（如 FoldCraftLauncher、PojavLauncher、Amethyst-Launcher、PojavLauncher-Glow-Worm 等）或渲染器（MobileGlues、gl4es 等）报错时，优先调用 `github_list_repos` 确认官方全名与仓库，使用 `github_search` 在 GitHub 官方仓库的 Issues、PRs（很多疑难崩溃与修复记录在 PR 解决说明中）及 Discussions 中检索最新线索，并调用 `github_get_content` 获取最吻合条目的解决方案。\n"
            . "- 检索词提取规则：直接从日志中摘取 Java Error 原文报错摘要（如 Caused by: 后的异常全名或类名，如 NullPointerException、MixinApplyError 等），英文异常类名/错误串原样保留，中文症状直接用中文。\n"
            . "- 检索结果必须与日志中的异常真正对应才可采用；无关结果不进入分析。\n"
            . "- 无结果时换词重试最多一次，按以下方向改写（选一，不要叠加）：① 长类名去包路径取简短类名（org.spongepowered...MixinApplyError → MixinApplyError）；② 英文异常类名与中文症状词互译（OutOfMemoryError → 内存不足）；③ 叠加限定词（模组名/启动器全名）；④ 改用更短的关键词。\n"
            . "- 预算按信息缺口计数：每个独立待核实的信号或问题，web_search_exa 调用不超过 2 次、全对话最多 5 次；github_search 与 github_get_content 严格各限 2 次，超额直接拦截。此后应立即基于已有证据输出并标注未核实项。\n"
            . "- 引用来源：GitHub 结论注明具体仓库、条目类型与编号（如 FoldCraftLauncher PR #852）；网络结论标注 URL。\n"
            . "- 社区结论与日志证据矛盾时，以日志为准；结论中注明哪些方面未能核实，不要臆测。\n"
            . "- 附件中存在 crash-reports 类文件时，优先用 read_log_file 读取它：崩溃报告含完整堆栈、系统状态与 mod 列表，信息密度高于 latest.log 尾部；主日志仅用于补充崩溃报告未覆盖的时间线。\n"
            . "- 大日志与多附件定位：排查特定异常类名、报错文本、mod ID 或配置行时，优先使用 grep_log_file 检索行号与上下文，避免通读无用段落；确认具体行号后再使用 read_log_file(filename, line_start, line_end) 定向扩展。\n"
            . "- 适可而止的边界：已探明上下文且掌握充分因果证据时应及时收敛给出清晰结论；或长日志初始未匹配显式错误且按常用词探测 1~2 次确实无异常后客观告知用户未发现明显错误，避免无目的循环试错；但绝不能在尚未探查上下文前就过早放弃调用工具。\n"
            . "- read_log_file 返回的主日志和附加日志均已经过与上传主日志相同的脱敏过滤；不得声称附加日志未脱敏，也不得要求用户重新提供其中的敏感信息。\n\n"
            . "示例（正确的检索路径）：\n"
            . "1. 日志片段「Caused by: org.spongepowered.asm.mixin.transformer.MixinApplyError: ...」\n"
            . "   → 提取 Java Error 报错摘要 \"MixinApplyError\" → 调用 web_search_exa(query: \"MixinApplyError\") → 结合官方文档或社区结论给出处理步骤。\n"
            . "2. 日志出现 FoldCraftLauncher 启动时渲染崩溃并提示 SIGSEGV：\n"
            . "   → 调用 github_search(repo: \"FoldCraftLauncher\", query: \"SIGSEGV\", type: \"all\") → 命中 PR 修复或采纳回答\n"
            . "   → 调用 github_get_content(repo: \"FoldCraftLauncher\", number: ...) 获取具体解决方案并给出建议。\n\n"
            . "结构化输出（供前端分栏展示与质量校验）：正文全部结束后，另起一段在末尾输出且仅输出一个 ```json 代码块，"
            . "字段固定为 {\"rootCause\": \"一句话根因\", \"confidence\": 0 到 1 的小数, \"evidence\": [\"支撑该根因的日志行或检索来源\"], \"steps\": [\"按顺序的解决步骤\"]}；"
            . "confidence 依据证据充分度取值，凡未核实的推断须相应调低并不应臆造 evidence。";

        $logWindow = $this->window->buildInitialWindow($content, self::MAX_TOOL_RESULT_BYTES);

        if ($logWindow->hasWindow()) {
            $userContent = "需要分析的日志内容：\n\n" . $logWindow->body;
        } else {
            $totalLines = substr_count($content, "\n") + 1;
            $totalBytes = strlen($content);
            $userContent = "待分析日志概况：\n"
                . "- 日志总大小：{$totalBytes} 字节，共 {$totalLines} 行。\n"
                . "- 预扫描结果：日志总长度超出单次上下文预算，且系统预扫描未在日志中匹配到显式崩溃或致命错误标记（如 Caused by / Traceback / FATAL / Exception 等）。为防止开服期正常启动日志产生误导，初始未截取前置日志正文。\n\n"
                . "排查指引与常用 grep 关键词（遵循适可而止思维链）：\n"
                . "1. 推荐优先使用 `grep_log_file` 工具进行 1 至 2 次定向检索，基础常用关键词包括：\n"
                . "   - 异常与报错级别：`ERROR`、`FATAL`、`Exception`、`Throwable`\n"
                . "   - 根本原因与堆栈：`Caused by`、`Stacktrace`、`Traceback`\n"
                . "   - 停机与崩溃谓词：`Failed to`、`Shutting down`、`Stopping server`、`crash`\n"
                . "   - 常见故障特征：`MixinApplyError`、`OutOfMemory`、`NoSuchMethod`、`ClassNotFound`\n"
                . "2. 适可而止思维链：\n"
                . "   - 结合可能的问题线索，从中选择最具针对性的 1~2 个关键词检索即可；\n"
                . "   - 若通过 `grep_log_file` 定位到明确报错且上下文足够，立即输出分析结论；需要局部扩展时再使用 `read_log_file` 指定行区间读取；\n"
                . "   - 若经基础关键词排查后仍未发现致命错误或崩溃迹象，应适可而止，直接向用户客观说明“在当前日志中未检索到明显致命错误”，并给出常规排障或配置检查建议，切勿盲目反复试词或通读无用段落。";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ];
    }

    public function defaultSystemPrompt(?string $logId): string
    {
        $prompt = <<<PROMPT
你是一个专业的 Minecraft 日志排障与根因分析助手。你的任务是深入分析玩家提交的日志，精准定位问题并提供切实可行的解决方案。

核心排障与工具调用原则：
1. 深入探查与上下文线索约束（核心准则）：
   - 复杂日志绝非仅看表象错误：日志中呈现的表层报错（如下游抛出的 NullPointerException、渲染管线崩溃、或 Crash Report 尾部的单点调用栈）往往只是下游级联反应，真正的根因常隐藏在上游前置事件、模组初始化失败或特定配置项中。
   - 【硬性要求】除非报错原因极其明确、孤立且无需任何周边上下文（例如纯粹的物理内存不足导致 OutOfMemoryError、或极为直白孤立的 Java 运行主版本不匹配，一眼即可 100% 确定根因且无需任何周边环境印证），否则你【必须至少进行一次日志上下文线索查找】（如调用 `read_log_file` 查看错误发生前后的关联行区间、读取 `crash-reports` 附件的完整堆栈与模组列表，或使用 `grep_log_file` 追踪前置异常链与模组初始化状态），探明真正诱因后再输出结论。严禁仅凭截取的单点报错片段浮于表面仓促下结论。
2. 合理调用工具与适可而止：
   - 工具是服务于查清根因的手段：拿到日志后，应首先思考“该报错是否由前置事件引起？是否有未明朗的模组冲突、配置缺失或环境线索未探明？”
   - 所谓“适可而止”，是指在探明上下文、获取充分因果证据链后，应果断收敛输出排障方案；或在长日志探测 1~2 次基础关键词后确认无致命错误时客观向用户说明，切勿在死胡同里无休止反复换词试错；但【适可而止绝不是在尚未核实上下文因果时就过早放弃调用工具】。

工作方式：
1. 查看与定位日志内容：
   - 先用 `list_log_files` 查看有哪些主日志与附加文件。若存在 `crash-reports` 或特定模组崩溃报告，优先读取它们。
   - 若需在长日志或多个附件中查找特定异常、报错关键字、mod ID 或配置，使用 `grep_log_file` 快速获取匹配行号与上下文。
   - 若需通读文件或特定行区间，调用 `read_log_file`。需要聚焦局部上下文时，由你传入 `line_start` 和 `line_end` 指定行区间（例如错误锚点前 50 行至后 30 行），探查前置诱因。超大内容需要续读时，使用返回的 `next_offset`。
2. 启动器与渲染器排障：遇启动器报错时，可调用 `github_list_repos` 查看支持仓库官方全名，使用 `github_search` 在 GitHub 官方仓库的 Issues/PRs/Discussions 检索最新排障线索，使用 `github_get_content` 获取解决方案。
3. 报错与网络检索：遇到报错需要检索时，直接提取日志中的 Java Error 直接报错摘要（如 `Caused by:` 后的异常全名、错误类型、具体报错消息等）作为 `web_search_exa` 的检索关键词。
4. 若检索结果被截断（出现"…"或"已截断"标记），基于被截断处再次检索补全，不需要重复读取文件。

重要停止规则：
- 不要在已经有完整日志内容的情况下再次调用 `read_log_file`，重复调用会被拒绝并浪费预算。
- 严禁使用相同的 `read_log_file` 参数调用两次；已读取内容可直接用于分析。
- 当已获取充分因果证据链能够完整解释问题时，应收敛工具调用，直接给出结论。
- 整个分析一般 2 至 4 轮工具调用即可完成；接近这个量级时优先收敛，基于已有证据给出结论。
- 文件工具失败或超时时，最多重试一次；仍不行就跳过它，基于现有证据继续分析，并在结论中说明哪些方面未能核实。
- 若长日志初始未匹配到显式错误，按推荐的基础关键词使用 grep_log_file 快速探测 1 至 2 次；若确实未发现异常信号，应适可而止并客观告知用户未发现明显致命错误，切勿无休止更换关键词试错。

回答使用简体中文，结构清晰。全程禁止使用 emoji 或表情符号。
PROMPT;

        if ($logId !== null) {
            $prompt .= "\n\n你正在分析的日志 ID 是 {$logId}。你可以使用 list_log_files 查看该日志下的文件列表，使用 grep_log_file 检索关键内容，使用 read_log_file 读取文件内容进行对比分析。";
        }

        return $prompt;
    }
}
