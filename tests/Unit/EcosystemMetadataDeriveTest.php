<?php

use App\Data\MetadataEntry;
use App\Log;

/**
 * 复刻 put() 的调用顺序：先 analyse() 再 deriveEcosystemMetadata()，但不落库，
 * 避免测试依赖 MariaDB / 内存存储后端。
 *
 * 返回 [key, value] 配对列表而非映射，以便断言「同一键不会被追加两次」。
 *
 * @param MetadataEntry[] $clientMetadata
 * @return array<int, array{0: ?string, 1: mixed}>
 */
function ecosystemDerivePairs(string $content, array $clientMetadata = []): array
{
    $log = new Log();
    (new ReflectionProperty(Log::class, 'data'))->setValue($log, $content);
    $log->analyse();

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
    (new ReflectionProperty(Log::class, 'data'))->setValue($log, FABRIC_LOG);
    $log->analyse();

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
