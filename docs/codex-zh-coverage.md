# Codex 中文本地化覆盖

本文记录 LogShare 对 `codex-minecraft` 输入本地化与输出翻译的覆盖范围，避免只适配 Fabric 示例而漏掉其它变体。

## 结论

`codex-minecraft` 的输入 pattern 绝大多数针对 Java 异常类名和英文技术日志，不会随客户端语言变化。会本地化并进入分析输入的主要是 **Fabric Loader / Quilt Loader 的 Mod Resolution 报告**；Quilt 复用了 Fabric 的 `Messages_zh_CN.properties`。

因此：

- Fabric / Quilt 的中文 resolution 文本由应用层 Insight 直接解析，不修改 `vendor/`。
- 其它分析器的英文技术 pattern 保持原样，输出统一由 `Translator` 翻译为中文。
- Codex 输出翻译增加应用层 overlay，补齐上游缺失的 `datapack-parsing-problem`。

## 输入覆盖矩阵

| 分析器/日志类型 | 中文输入 | 处理方式 |
| --- | --- | --- |
| Fabric Server / Client | 是 | `App\Codex\Analyser\FabricAnalyser` + 三个 Fabric 问题子类 |
| Fabric Crash Report | 是 | `App\Codex\Analyser\FabricCrashReportAnalyser` |
| Quilt Server / Client | 是 | `App\Codex\Analyser\QuiltAnalyser` |
| Quilt Crash Report | 是 | `App\Codex\Analyser\QuiltCrashReportAnalyser` |
| Vanilla Server / Client / Crash Report / Network Protocol | 否 | 英文异常/技术日志，使用默认 analyser |
| Bukkit / CraftBukkit / Spigot / Paper / Purpur / Folia / Glowstone | 否 | 英文插件与服务器日志 |
| Forge / NeoForge / Mohist / Arclight / Magma | 否 | 英文 FML 技术日志 |
| BungeeCord / Waterfall / Velocity / Geyser | 否 | 英文代理与网关日志 |
| Pocketmine / Bedrock / Bedrock Content | 否 | 英文服务端日志 |
| Prism / Minecraft Launcher / CustomSkinLoader | 否 | 英文启动器日志 |
| Hytale | 不适用 | `codex-hytale` 没有中文语言包，不在 codex-minecraft 范围 |

## Fabric / Quilt resolution 变体

| Loader 文本 | 结果 |
| --- | --- |
| `但没有安装它` | 缺失依赖问题 + 安装指定版本范围的 solution |
| `但已经安装了的版本 ... 不对` | 版本不匹配问题 |
| `但它在此环境中被停用` | 当前环境停用问题 |
| `但由于其他限制条件无法载入` | 约束冲突问题 |
| `与 ... 不兼容` / `与 ... 冲突` | 模组互斥问题 + 移除建议 |
| Minecraft 版本不匹配 | `FabricModIncompatibleMinecraftVersionProblem` |
| `任意版本`、`A 版本`、`A 及以上版本`、`A 以上任意版本`、`A 及以下版本`、`A 以下任意版本`、`从 A（含）到 B（不含）的任意版本` | 转换为 Codex 安装版本约束 |
| `recommends` | 非阻塞建议，不产出 problem |
| `Duplicate versions for mod ID` / entrypoint / mixin | Loader 技术英文文本，沿用上游 pattern |

## 输出翻译覆盖

`tests/Unit/CodexTranslationCoverageTest.php` 扫描 `vendor/aternos/codex-minecraft/src` 中的字面量 `getTranslation()` key，并断言简体中文均可解析。

应用层补充：

- `datapack-parsing-problem`：上游 `zh-CN.json` 缺失，overlay 补简体、繁体与英文。
- Fabric dependency mismatch / env-disabled / invalid：overlay 补充中英文文案。

## 独立报告识别

启动器可能只保存 `A potential solution has been determined` / `确定了一种可能的解决方法` 这类 resolution 文本，没有日志头。`App\Codex\FabricResolutionReport` 会在 Codex 判为未知日志时，按 Fabric 或 Quilt 服务器日志重新解析；普通未知文本不会命中。

## 回归测试

- `tests/Unit/CodexChineseAnalysisTest.php`
  - 完整 Fabric 中文日志
  - 无日志头的独立中文报告
  - Quilt 中文日志
  - Minecraft 版本不匹配与模组冲突
  - 8 种版本范围
  - 英文 `between` 空依赖回归
  - 英文 env-disabled / invalid / mismatch
  - 普通文本不误判
- `tests/Unit/CodexTranslationCoverageTest.php`
  - 全量字面量翻译 key
  - `datapack-parsing-problem` overlay

## 维护约定

升级 `aternos/codex-minecraft` 后：

1. 运行上述两组测试。
2. 比较 `vendor/aternos/codex-minecraft/lang/en.json` 与 `zh-CN.json`，确认是否有新增缺失 key。
3. 检查 Fabric/Quilt Loader 是否新增 resolution 文案；新增变体时在对应自定义 Insight 中补 pattern。
4. 不修改 `vendor/`；应用层补丁放在 `app/Codex/`。
