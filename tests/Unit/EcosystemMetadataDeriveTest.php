<?php

use App\Data\MetadataEntry;
use App\Log;

/**
 * 复刻 put() 的元数据补全顺序：先由反混淆阶段识别日志，再合并客户端元数据。
 *
 * 返回 [key, value] 配对列表而非映射，以便断言「同一键不会被追加两次」。
 *
 * @param MetadataEntry[] $clientMetadata
 * @return array<int, array{0: ?string, 1: mixed}>
 */
function ecosystemDerivePairs(string $content, array $clientMetadata = []): array
{
    $log = new Log();
    $detected = $log->analyseContent($content);
    if ($detected instanceof \Aternos\Codex\Minecraft\Log\Minecraft\MinecraftLog) {
        (new ReflectionProperty(Log::class, 'detectedLog'))->setValue($log, $detected);
    }

    $entries = (new ReflectionMethod(Log::class, 'deriveEcosystemMetadata'))->invoke($log, $clientMetadata);

    return array_map(static fn (MetadataEntry $e): array => [$e->getKey(), $e->getValue()], $entries);
}

const FABRIC_LOG = "[12:00:01] [main/INFO] (FabricLoader/GameProvider) Loading Minecraft 1.20.4 with Fabric Loader 0.15.6\n[12:00:02] [Server thread/INFO]: Done (3.251s)!\n";
const NEOFORGE_LOG = "[14:22:31] [main/INFO] [ne.ne.co.se.ServerSetup/]: NeoForge mod loading, version 21.1.72, mcversion 1.21.1\n";
const PLAIN_LOG = "hello world\nthis line is not a minecraft log at all\n";

test('upload derives version and loader metadata from Codex analysis', function () {
    expect(ecosystemDerivePairs(FABRIC_LOG))->toBe([['version', '1.20.4'], ['loader', 'fabric']]);
});

test('derived loader is reported even when the version is unrecognized', function () {
    expect(ecosystemDerivePairs(NEOFORGE_LOG))->toBe([['loader', 'neoforge']]);
});

test('client submitted metadata keys are never overwritten or duplicated', function () {
    $client = [(new MetadataEntry())->setKey('version')->setValue('1.20.1')->setLabel('游戏版本')];

    expect(ecosystemDerivePairs(FABRIC_LOG, $client))
        ->toBe([['version', '1.20.1'], ['loader', 'fabric']]);
});

test('non minecraft logs gain no ecosystem metadata', function () {
    expect(ecosystemDerivePairs(PLAIN_LOG))->toBe([]);
});

test('derived entries carry display labels the clients render directly', function () {
    $log = new Log();
    $detected = $log->analyseContent(FABRIC_LOG);
    (new ReflectionProperty(Log::class, 'detectedLog'))->setValue($log, $detected);

    /** @var MetadataEntry[] $entries */
    $entries = (new ReflectionMethod(Log::class, 'deriveEcosystemMetadata'))->invoke($log, []);

    $labels = [];
    foreach ($entries as $entry) {
        $labels[(string) $entry->getKey()] = $entry->getLabel();
    }

    expect($labels)->toBe([
        'version' => 'Minecraft 版本',
        'loader' => '模组加载器',
    ]);
});

test('codex minecraft analysis is localized to simplified chinese', function () {
    $log = new Log();
    $detected = $log->analyseContent(FABRIC_LOG);

    expect(\Aternos\Codex\Minecraft\Translator\Translator::getInstance()->getLanguage())->toBe('zh-CN');
    expect($detected->getTypeName())->toBe('服务器日志');
});
