<?php

declare(strict_types=1);

use App\Data\MetadataEntry;

test('MetadataEntry serializes only visible entries for public output', function () {
    $entries = [
        MetadataEntry::fromArray(['key' => 'public', 'value' => 'v', 'visible' => true]),
        MetadataEntry::fromArray(['key' => 'private', 'value' => 'secret', 'visible' => false]),
    ];

    $serialized = MetadataEntry::serializeVisible($entries);

    expect($serialized)->toHaveCount(1);
    expect($serialized[0]['key'])->toBe('public');
});
