<?php

declare(strict_types=1);

use App\Codex\FabricResolutionReport;
use App\Log;
use Aternos\Codex\Minecraft\Log\Minecraft\MinecraftLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\Fabric\FabricServerLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\Quilt\QuiltServerLog;

function codexFabricLog(array $detailLines, string $loader = 'fabric'): string
{
    $header = $loader === 'quilt'
        ? '[12:00:00] [main/INFO] (QuiltLoader/GameProvider) Loading Minecraft 1.20.1 with Quilt Loader 0.24.0'
        : '[12:00:00] [main/INFO] (FabricLoader/GameProvider) Loading Minecraft 1.20.1 with Fabric Loader 0.15.11';
    $exception = $loader === 'quilt'
        ? 'org.quiltmc.loader.impl.discovery.ModResolutionException: Some mods are incompatible!'
        : 'net.fabricmc.loader.impl.discovery.ModResolutionException: Some mods are incompatible!';

    return $header . "\n[12:00:01] [main/ERROR]: A critical error occurred\n"
        . $exception . "\n" . implode("\n", $detailLines) . "\n";
}

const CODEX_FABRIC_HEADER = "确定了一种可能的解决方法，这样做可能会解决你的问题：\n - 安装 sophisticatedcore，从 1.20.1-1.2.7.7（含）到 1.20.4（不含）的任意版本。\n更多信息：";

test('chinese fabric dependency report produces chinese problems and solutions', function () {
    $content = codexFabricLog([
        CODEX_FABRIC_HEADER,
        " - 模组 'Sophisticated Backpacks' (sophisticatedbackpacks) 1.20.1-3.**.**.**.** 需要 sophisticatedcore 的 从 1.20.1-1.2.7.7（含）到 1.20.4（不含）的任意版本，但没有安装它！",
        " - 模组 'Sophisticated Backpacks' (sophisticatedbackpacks) 1.20.1-3.**.**.**.** 需要 porting_lib_loot 的 2.3.2 及以上版本，但没有安装它！",
    ]);

    $detected = (new Log())->analyseContent($content);
    expect($detected)->toBeInstanceOf(FabricServerLog::class);
    expect($detected->getTypeName())->toBe('服务器日志');

    $problems = $detected->analyse()->getProblems();
    expect($problems)->toHaveCount(2);
    expect($problems[0]->getMessage())->toContain('Sophisticated Backpacks')
        ->toContain('sophisticatedcore');
    expect($problems[0]->getSolutions()[0]->getMessage())
        ->toContain('sophisticatedcore')
        ->toContain('>=1.20.1-1.2.7.7, <1.20.4');
    expect((string) $problems[0]->getEntry())->toContain('模组');
});

test('standalone chinese resolution report is recognized without a log header', function () {
    $content = CODEX_FABRIC_HEADER . "\n"
        . " - 模组 'Sophisticated Backpacks' (sophisticatedbackpacks) 1.20.1-3.**.**.**.** 需要 sophisticatedcore 的 从 1.20.1-1.2.7.7（含）到 1.20.4（不含）的任意版本，但没有安装它！\n";

    expect(FabricResolutionReport::matches($content))->toBeTrue();

    $detected = (new Log())->analyseContent($content);
    expect($detected)->toBeInstanceOf(FabricServerLog::class);
    expect($detected->analyse()->getProblems())->toHaveCount(1);
});

test('quilt chinese report uses the quilt analyser', function () {
    $content = codexFabricLog([
        " - 模组 'Sophisticated Backpacks' (sophisticatedbackpacks) 1.20.1-3.**.**.**.** 需要 sophisticatedcore 的 任意版本，但没有安装它！",
    ], 'quilt');

    $detected = (new Log())->analyseContent($content);
    expect($detected)->toBeInstanceOf(QuiltServerLog::class);
    expect($detected->analyse()->getProblems())->toHaveCount(1);
});

test('chinese minecraft version mismatch and mod conflicts are recognized', function () {
    $content = codexFabricLog([
        " - 模组 'Example' (example) 1.0 需要 'Minecraft' (minecraft) 的 1.20.1 及以上版本，但已经安装了的版本 1.19.4 不对！",
        " - 模组 'A' (a) 1.0 与 'B' (b) 的 任意版本 不兼容，但已经安装了的版本是 1.0！",
    ]);

    $problems = (new Log())->analyseContent($content)->analyse()->getProblems();
    expect($problems)->toHaveCount(2);
    expect($problems[0]->getMessage())->toContain('Minecraft 1.19.4');
    expect($problems[1]->getMessage())->toContain('A')->toContain('B');
});

test('chinese version range variants are converted to install constraints', function () {
    $cases = [
        '任意版本' => ['dep'],
        '1.0 版本' => ['1.0'],
        '1.0 及以上版本' => ['>=1.0'],
        '1.0 以上任意版本' => ['>1.0'],
        '1.0 及以下版本' => ['<=1.0'],
        '1.0 以下任意版本' => ['<1.0'],
        '从 1.0（含）到 2.0（不含）的任意版本' => ['>=1.0, <2.0'],
        '从 1.0（不含）到 2.0（含）的任意版本' => ['>1.0, <=2.0'],
    ];

    foreach ($cases as $range => $expectedParts) {
        $content = codexFabricLog([
            " - 模组 'A' (a) 1.0 需要 dep 的 {$range}，但没有安装它！",
        ]);

        $problems = (new Log())->analyseContent($content)->analyse()->getProblems();
        expect($problems)->toHaveCount(1, "range: {$range}");
        $message = $problems[0]->getSolutions()[0]->getMessage();
        foreach ($expectedParts as $part) {
            expect(str_contains($message, $part))->toBeTrue("range: {$range}, message: {$message}");
        }
    }
});

test('english between range keeps a non-empty dependency', function () {
    $content = codexFabricLog([
        " - Mod 'A' (a) 1.0 requires any version between 1.0 (inclusive) and 2.0 (exclusive) of b, which is missing!",
    ]);

    $problems = (new Log())->analyseContent($content)->analyse()->getProblems();
    expect($problems)->toHaveCount(1);
    expect($problems[0]->getMessage())->toContain("'b'")->not->toContain("''");
    expect($problems[0]->getSolutions()[0]->getMessage())->toContain('>=1.0, <2.0');
});


test('english dependency reasons are recognized with translated messages', function () {
    $content = codexFabricLog([
        " - Mod 'A' (a) 1.0 requires 2.0 or later of dep, which is disabled for this environment (client/server only)!",
        " - Mod 'A' (a) 1.0 requires 2.0 or later of dep, which can't be loaded due to other constraints!",
        " - Mod 'A' (a) 1.0 requires 2.0 or later of dep, but only the wrong version is present: 1.0!",
    ]);

    $problems = (new Log())->analyseContent($content)->analyse()->getProblems();
    expect($problems)->toHaveCount(3);
    expect($problems[0]->getMessage())->toContain('当前环境中被停用');
    expect($problems[1]->getMessage())->toContain('其他限制条件');
    expect($problems[2]->getMessage())->toContain('当前安装的版本不满足');
});


test('chinese dependency mismatch, disabled and invalid constraints are recognized', function () {
    $content = codexFabricLog([
        " - 模组 'Example' (example) 1.0 需要 'Dep' (dep) 的 从 1.0（含）到 2.0（不含）的任意版本，但已经安装了的版本 1.5 不对！",
        " - 模组 'Example' (example) 1.0 需要 dep 的 2.0 及以上版本，但它在此环境中被停用（仅适用于用户端／服务器）！",
        " - 模组 'Example' (example) 1.0 需要 dep 的 任意版本，但由于其他限制条件无法载入！",
    ]);

    $problems = (new Log())->analyseContent($content)->analyse()->getProblems();
    expect($problems)->toHaveCount(3);
    expect($problems[0]->getMessage())->toContain('当前安装的版本不满足');
    expect($problems[1]->getMessage())->toContain('当前环境中被停用');
    expect($problems[2]->getMessage())->toContain('任意版本')->toContain('其他限制条件');
});

test('standalone english resolution report is recognized', function () {
    $content = "A potential solution has been determined, this may resolve your problem:\n"
        . " - Install dep, any version between 1.0 (inclusive) and 2.0 (exclusive).\n"
        . "More details:\n"
        . " - Mod 'A' (a) 1.0 requires any version between 1.0 (inclusive) and 2.0 (exclusive) of dep, which is missing!\n";

    expect(FabricResolutionReport::matches($content))->toBeTrue();
    $detected = (new Log())->analyseContent($content);
    expect($detected)->toBeInstanceOf(FabricServerLog::class);
    expect($detected->analyse()->getProblems())->toHaveCount(1);
});

test('ordinary unknown text is not misclassified as fabric', function () {
    $detected = (new Log())->analyseContent("hello world\nthis is not a minecraft log\n");

    expect($detected)->toBeInstanceOf(MinecraftLog::class);
    expect(FabricResolutionReport::matches("hello world\n"))->toBeFalse();
});
