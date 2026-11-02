<?php

declare(strict_types=1);

namespace App\Codex;

use Aternos\Codex\Minecraft\Log\Minecraft\MinecraftLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\Fabric\FabricServerLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\Quilt\QuiltServerLog;

/**
 * 识别启动器直接展示的 Fabric / Quilt Loader resolution 报告。
 *
 * 这类文本可能没有时间戳和 `Loading Minecraft ...` 头，Codex 的 Detective
 * 会把它判成未知日志；只要命中 Loader 的 resolution 标记，就按 Fabric /
 * Quilt 服务器日志交给对应 analyser。
 */
final class FabricResolutionReport
{
    public static function matches(string $content): bool
    {
        if (str_contains($content, 'net.fabricmc.loader.impl.discovery.ModResolutionException')
            || str_contains($content, 'org.quiltmc.loader')) {
            return true;
        }

        $hasHeader = str_contains($content, '确定了一种可能的解决方法，这样做可能会解决你的问题：')
            || str_contains($content, 'A potential solution has been determined');
        $hasDetails = str_contains($content, '更多信息：')
            || str_contains($content, 'More details:');
        $hasDependencyLine = preg_match(
            '/\s*-\s*(?:模组|Mod) [^\n]+ (?:需要|requires) [^\n]+(?:没有安装|missing)/u',
            $content
        ) === 1;

        return ($hasHeader && $hasDetails) || $hasDependencyLine;
    }

    public static function createLog(string $content): MinecraftLog
    {
        $class = (str_contains($content, 'org.quiltmc.loader') || str_contains($content, 'Quilt Loader'))
            ? QuiltServerLog::class
            : FabricServerLog::class;

        /** @var MinecraftLog $log */
        $log = new $class();

        return $log;
    }
}
