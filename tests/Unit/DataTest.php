<?php

use App\Data\MetadataEntry;
use App\Data\Token;

test('Token generates a strong random value', function () {
    $a = new Token();
    $b = new Token();

    expect($a->get())->toBeString();
    expect(strlen($a->get()))->toBe(64);
    expect($a->get())->not->toBe($b->get());
});

test('Token matches with constant-time comparison', function () {
    $token = new Token();
    expect($token->matches($token->get()))->toBeTrue();
    expect($token->matches('wrong'))->toBeFalse();
});

test('Token json serializes to its value', function () {
    $token = new Token();
    expect(json_encode($token))->toBe(json_encode($token->get()));
});

test('Token accepts a fixed value', function () {
    $token = new Token('abc123');
    expect($token->get())->toBe('abc123');
    expect($token->matches('abc123'))->toBeTrue();
    expect($token->isHashed())->toBeFalse();
});

test('MetadataEntry parses from array', function () {
    $entry = MetadataEntry::fromArray([
        'key' => 'version',
        'value' => '1.20.1',
        'label' => 'Version',
        'visible' => false,
    ]);

    expect($entry)->toBeInstanceOf(MetadataEntry::class);
    expect($entry->getKey())->toBe('version');
    expect($entry->getValue())->toBe('1.20.1');
    expect($entry->getDisplayLabel())->toBe('Version');
    expect($entry->getLabel())->toBe('Version');
    expect($entry->isVisible())->toBeFalse();
    expect($entry->getDisplayValue())->toBe('1.20.1');
});

test('MetadataEntry fromArray returns null when invalid', function () {
    expect(MetadataEntry::fromArray(['key' => 'x']))->toBeNull();
    expect(MetadataEntry::fromArray(['value' => 'x']))->toBeNull();
    expect(MetadataEntry::fromArray([]))->toBeNull();
});

test('MetadataEntry parses from object', function () {
    $obj = (object) ['key' => 'k', 'value' => 'v', 'label' => 'L', 'visible' => true];
    $entry = MetadataEntry::fromObject($obj);

    expect($entry)->toBeInstanceOf(MetadataEntry::class);
    expect($entry->getKey())->toBe('k');
    expect($entry->getValue())->toBe('v');
});

test('MetadataEntry allFromArray caps at max entries', function () {
    $data = [];
    for ($i = 0; $i < 200; $i++) {
        $data[] = ['key' => 'k' . $i, 'value' => 'v'];
    }
    $entries = MetadataEntry::allFromArray($data);

    // 同 key 会被去重（存储层只保留首次出现的 key），
    // 所以这里用 200 个不同 key 验证条数上限。
    expect(count($entries))->toBe(MetadataEntry::MAX_ENTRIES);
});

test('MetadataEntry allFromArray skips invalid entries', function () {
    $entries = MetadataEntry::allFromArray([
        ['key' => 'a', 'value' => '1'],
        ['not-an-array-value' => true],
        ['key' => 'b', 'value' => '2'],
    ]);

    expect($entries)->toHaveCount(2);
});

test('MetadataEntry truncates oversized values', function () {
    $entry = MetadataEntry::fromArray([
        'key' => 'long',
        'value' => str_repeat('x', 2000),
    ]);

    expect(strlen((string) $entry->getValue()))->toBeLessThanOrEqual(1024);
});

test('MetadataEntry jsonSerialize matches expected shape', function () {
    $entry = MetadataEntry::fromArray(['key' => 'k', 'value' => 'v', 'label' => 'L', 'visible' => true]);
    expect($entry->jsonSerialize())->toMatchArray([
        'key' => 'k',
        'value' => 'v',
        'label' => 'L',
        'visible' => true,
    ]);
});

test('MetadataEntry getDisplayLabel falls back to key', function () {
    $entry = MetadataEntry::fromArray(['key' => 'fallback', 'value' => 'v']);
    expect($entry->getDisplayLabel())->toBe('fallback');
});
test('Token never accepts the stored hash as a credential', function () {
    $plain = 'a1b2c3d4e5f6';
    $stored = App\Data\Token::hashValue($plain);
    $holder = Token::fromHash($stored);

    // 原文可以通过
    expect($holder->matches($plain))->toBeTrue();
    // 存储值本身绝不可以通过（历史缺陷：明文回退分支让哈希变成凭证）
    expect($holder->matches($stored))->toBeFalse();
    expect($holder->matches(substr($stored, strlen(Token::HASH_PREFIX))))->toBeFalse();
});

test('Token upgrades legacy unprefixed hashes by prefixing', function () {
    $plain = 'legacy-token-value';
    $legacy = hash('sha256', $plain);
    $token = Token::fromHash($legacy);

    expect($token)->not->toBeNull();
    expect($token->isHashed())->toBeTrue();
    expect($token->hasPrefix())->toBeFalse();
    expect($token->matches($plain))->toBeTrue();
    expect($token->matches($legacy))->toBeFalse();
    expect($token->normalizedValue())->toBe(Token::HASH_PREFIX . $legacy);
});

test('Token hash value is prefixed and stable', function () {
    expect(Token::hashValue('x'))->toStartWith(Token::HASH_PREFIX);
    expect(Token::hashValue('x'))->toBe(Token::hashValue('x'));
    expect(Token::hashValue('x'))->not->toBe(Token::hashValue('y'));
});

test('MetadataEntry rejects empty or whitespace-only keys', function () {
    expect(MetadataEntry::fromArray(['key' => '', 'value' => 'v']))->toBeNull();
    expect(MetadataEntry::fromArray(['key' => '   ', 'value' => 'v']))->toBeNull();
    expect(MetadataEntry::allFromArray([
        ['key' => '', 'value' => 'v'],
        ['key' => 'version', 'value' => '1.20.1'],
    ]))->toHaveCount(1);
});

test('MetadataEntry limits key, label and value by characters', function () {
    $entry = MetadataEntry::fromArray([
        'key' => str_repeat('键', 100),
        'value' => str_repeat('值', 2000),
        'label' => str_repeat('标', 200),
    ]);

    expect($entry)->not->toBeNull();
    expect(mb_strlen((string) $entry->getKey(), 'UTF-8'))->toBe(64);
    expect(mb_strlen((string) $entry->getLabel(), 'UTF-8'))->toBe(128);
    expect(mb_strlen((string) $entry->getValue(), 'UTF-8'))->toBe(1024);
});

test('MetadataEntry allFromArray keeps the first entry for duplicate keys', function () {
    $entries = MetadataEntry::allFromArray([
        ['key' => 'version', 'value' => '1.20.1'],
        ['key' => 'version', 'value' => '1.21.1'],
        ['key' => 'loader', 'value' => 'fabric'],
    ]);

    expect($entries)->toHaveCount(2);
    expect($entries[0]->getValue())->toBe('1.20.1');
    expect($entries[1]->getKey())->toBe('loader');
});
