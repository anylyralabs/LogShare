<?php

declare(strict_types=1);

namespace App\Codex\Translation;

use Aternos\Codex\Minecraft\Translator\Translator;

/**
 * Codex-Minecraft 语言包的应用层补丁。
 *
 * codex-minecraft 的英文语言文件比简体/繁体多一个 datapack 文案；中文
 * Fabric resolution 的 mismatch/envDisabled/invalid 也需要额外 key。不要
 * 修改 vendor/，在 Translator 单例上合并应用层文案，升级 Codex 时只需检查
 * 这里是否仍然需要。
 */
final class TranslatorOverlay
{
    /** @var array<string, array<string, string>> */
    private const EXTRA = [
        'en' => [
            'datapack-parsing-problem' => "The datapack '{{datapack}}' could not be parsed.",
            'fabric-mod-dependency-mismatch-problem' => "Mod '{{mod-name}}' requires {{version-range}} of {{dependency-mod-name}}, but the installed version does not satisfy it.",
            'fabric-mod-dependency-env-disabled-problem' => "Mod '{{mod-name}}' requires {{version-range}} of {{dependency-mod-name}}, but that version is disabled for this environment.",
            'fabric-mod-dependency-invalid-problem' => "Mod '{{mod-name}}' requires {{version-range}} of {{dependency-mod-name}}, but it cannot be loaded due to other constraints.",
        ],
        'zh-CN' => [
            'datapack-parsing-problem' => "数据包 '{{datapack}}' 无法解析。",
            'fabric-mod-dependency-mismatch-problem' => "模组 '{{mod-name}}' 需要 {{dependency-mod-name}} 的 {{version-range}}，但当前安装的版本不满足。",
            'fabric-mod-dependency-env-disabled-problem' => "模组 '{{mod-name}}' 需要 {{dependency-mod-name}} 的 {{version-range}}，但该版本在当前环境中被停用。",
            'fabric-mod-dependency-invalid-problem' => "模组 '{{mod-name}}' 需要 {{dependency-mod-name}} 的 {{version-range}}，但由于其他限制条件无法载入。",
        ],
        'zh-TW' => [
            'datapack-parsing-problem' => "資料包 '{{datapack}}' 無法解析。",
            'fabric-mod-dependency-mismatch-problem' => "模組 '{{mod-name}}' 需要 {{dependency-mod-name}} 的 {{version-range}}，但目前安裝的版本不符合。",
            'fabric-mod-dependency-env-disabled-problem' => "模組 '{{mod-name}}' 需要 {{dependency-mod-name}} 的 {{version-range}}，但該版本在目前環境中被停用。",
            'fabric-mod-dependency-invalid-problem' => "模組 '{{mod-name}}' 需要 {{dependency-mod-name}} 的 {{version-range}}，但由於其他限制條件無法載入。",
        ],
    ];

    public static function install(string $language): void
    {
        self::merge(Translator::getInstance(), $language);
        self::merge(Translator::getInstance(), 'en');
        self::merge(Translator::getFallbackInstance(), 'en');
    }

    private static function merge(Translator $translator, string $language): void
    {
        $extra = self::EXTRA[$language] ?? null;
        if ($extra === null) {
            return;
        }

        $property = new \ReflectionProperty(Translator::class, 'translations');
        $translations = $property->getValue($translator);
        if (!is_array($translations)) {
            $translations = [];
        }

        $existing = $translations[$language] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $baseFile = dirname(__DIR__, 3) . '/vendor/aternos/codex-minecraft/lang/' . $language . '.json';
        $base = [];
        if (is_file($baseFile)) {
            $decoded = json_decode((string) file_get_contents($baseFile), true);
            if (is_array($decoded)) {
                $base = $decoded;
            }
        }

        $translations[$language] = array_replace($base, $existing, $extra);
        $property->setValue($translator, $translations);
    }
}
