<?php

declare(strict_types=1);

use App\Client\Mongo\MongoGateway;
use App\Data\Token;
use App\Storage\MongoStorage;
use MongoDB\BSON\Binary;
use Tests\Support\RecordingGateway;

test('chunk writes are split into bounded batches', function () {
    $gateway = new RecordingGateway();
    MongoGateway::set($gateway);

    try {
        $files = [];
        for ($i = 0; $i < 12; $i++) {
            $files[] = [
                'name' => "file-{$i}.log",
                'data' => str_repeat(chr(65 + $i), 512 * 1024),
            ];
        }

        $id = MongoStorage::Put($files, new Token(), [], 'batch-test');
        expect($id)->not->toBeNull();

        $batches = array_values(array_filter(
            $gateway->inserts,
            static fn (array $insert): bool => $insert['collection'] === MongoStorage::COLLECTION_CHUNKS
        ));

        expect($batches)->toHaveCount(2);
        foreach ($batches as $batch) {
            $bytes = 0;
            foreach ($batch['documents'] as $document) {
                $data = $document['data'] ?? null;
                if ($data instanceof Binary) {
                    $bytes += strlen($data->getData());
                }
            }
            expect($bytes)->toBeLessThanOrEqual(MongoStorage::CHUNK_BATCH_BYTES);
        }
    } finally {
        MongoGateway::reset();
    }
});

test('a single chunk larger than the batch target is still written', function () {
    $gateway = new RecordingGateway();
    MongoGateway::set($gateway);

    try {
        $id = MongoStorage::Put([
            ['name' => 'large.log', 'data' => str_repeat('x', MongoStorage::CHUNK_BYTES + 1)],
        ], new Token(), [], 'batch-single');

        expect($id)->not->toBeNull();

        $batches = array_values(array_filter(
            $gateway->inserts,
            static fn (array $insert): bool => $insert['collection'] === MongoStorage::COLLECTION_CHUNKS
        ));

        expect($batches)->toHaveCount(2);
    } finally {
        MongoGateway::reset();
    }
});
