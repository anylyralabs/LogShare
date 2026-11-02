<?php

declare(strict_types=1);

namespace App\Codex\Analysis\Problem\Fabric;

/**
 * Fabric / Quilt Loader 解析报告里的公共文本解析。
 *
 * Loader 的 resolution 文本会跟随 JVM Locale 本地化；这里只处理文本到
 * 结构化字段的转换，输出文案仍交给 Codex Translator。
 */
trait ParsesFabricMessages
{
    /**
     * 解析 loader 的 mod token：
     *   'Sophisticated Backpacks' (sophisticatedbackpacks) 1.20.1-3.0.0
     *   sophisticatedbackpacks
     *
     * @return array{name: string, version: ?string}
     */
    protected static function parseModToken(string $token): array
    {
        $token = trim($token);

        if (preg_match("/^'([^']+)' \\(([^)]+)\\)(?: (.+))?$/u", $token, $matches) === 1) {
            return ['name' => $matches[1], 'version' => $matches[3] ?? null];
        }

        if (preg_match('/^([\w.-]+)(?: (.+))?$/u', $token, $matches) === 1) {
            return ['name' => $matches[1], 'version' => $matches[2] ?? null];
        }

        return ['name' => $token, 'version' => null];
    }

    protected static function parseModName(string $token): string
    {
        return self::parseModToken($token)['name'];
    }

    /**
     * 把 Loader 的中文版本范围转成 ModInstallSolution 使用的约束字符串。
     */
    protected static function parseChineseRange(string $range): ?string
    {
        $range = trim(str_replace(['（', '）'], ['(', ')'], $range));

        if (preg_match('/^从\s*(.+?)\((含|不含)\)\s*到\s*(.+?)\((含|不含)\)的任意版本$/u', $range, $matches) === 1) {
            $lower = $matches[2] === '含' ? '>=' : '>';
            $upper = $matches[4] === '含' ? '<=' : '<';

            return $lower . trim($matches[1]) . ', ' . $upper . trim($matches[3]);
        }

        if (preg_match('/^(.+?) 及以上版本$/u', $range, $matches) === 1) {
            return '>=' . trim($matches[1]);
        }
        if (preg_match('/^(.+?) 以上任意版本$/u', $range, $matches) === 1) {
            return '>' . trim($matches[1]);
        }
        if (preg_match('/^(.+?) 及以下版本$/u', $range, $matches) === 1) {
            return '<=' . trim($matches[1]);
        }
        if (preg_match('/^(.+?) 以下任意版本$/u', $range, $matches) === 1) {
            return '<' . trim($matches[1]);
        }
        if (preg_match('/^(.+?) 版本$/u', $range, $matches) === 1) {
            return trim($matches[1]);
        }
        if ($range === '任意版本' || $range === '无法满足的版本范围') {
            return null;
        }

        return null;
    }
}
