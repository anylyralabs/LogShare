<?php

declare(strict_types=1);

use App\Client\RedisClient;
use App\Client\RedisStreams;
use App\Config;
use App\Log;
use App\Process\EventQueueConsumer;
use App\Queue\EventQueue;
use App\Queue\QueueEvent;
use Tests\Mocks\RedisMock;
use Tests\Support\InMemoryStorage;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $cfg = $this->origData;
    $cfg['storage']['storageId'] = 's';
    $cfg['storage']['storages']['s'] = [
        'name' => 'InMemory',
        'class' => InMemoryStorage::class,
        'enabled' => true,
    ];
    $cfg['cache']['enabled'] = false;
    $cfg['eventQueue'] = [
        'enabled' => true,
        'stream' => 'events:log:stream',
        'group' => 'log-event-workers',
        'maxAttempts' => 3,
        'asyncDeobfuscate' => false,
        'asyncSecurityAudit' => true,
    ];
    $this->dataProp->setValue(null, $cfg);

    InMemoryStorage::reset();
    RedisMock::reset();
    RedisClient::setTestConnection(new RedisMock());
});

afterEach(function () {
    RedisClient::setTestConnection(null);
    RedisMock::reset();
    InMemoryStorage::reset();
    $this->dataProp->setValue(null, $this->origData);
});

function processConsumerEntry(string $stream, string $group, string $id, array $fields): void
{
    $ref = new ReflectionClass(EventQueueConsumer::class);
    $consumer = $ref->newInstanceWithoutConstructor();
    $method = $ref->getMethod('processEntry');
    $method->invoke($consumer, $stream, $group, $id, $fields);
}

test('consumer ACKs and deletes a successfully processed entry', function () {
    $log = new Log();
    $id = $log->put('clean log for consumer');
    expect($id)->not->toBeNull();

    $stream = EventQueue::DEFAULT_STREAM;
    $group = EventQueue::DEFAULT_GROUP;
    $fields = (new QueueEvent(EventQueue::EVENT_LOG_UPLOADED, ['logId' => $id->get()]))->toStreamData();

    RedisStreams::xGroupCreate($stream, $group);
    $entryId = RedisStreams::xAdd($stream, $fields);
    $entries = RedisStreams::xReadGroup($group, 'worker', $stream, 1, 1);
    expect($entries)->toHaveCount(1);

    processConsumerEntry($stream, $group, $entryId, $entries[0][1]);

    $redis = RedisClient::getRedis();
    expect($redis)->not->toBeNull();
    expect($redis->xlen($stream))->toBe(0);
    expect($redis->exists("events:attempts:{$entryId}"))->toBeFalse();
});

test('consumer keeps a failed entry pending and moves it to DLQ at max attempts', function () {
    $stream = EventQueue::DEFAULT_STREAM;
    $group = EventQueue::DEFAULT_GROUP;
    $fields = (new QueueEvent(EventQueue::EVENT_LOG_UPLOADED, ['logId' => 'invalid!id']))->toStreamData();

    RedisStreams::xGroupCreate($stream, $group);
    $entryId = RedisStreams::xAdd($stream, $fields);

    $redis = RedisClient::getRedis();
    expect($redis)->not->toBeNull();

    processConsumerEntry($stream, $group, $entryId, $fields);
    expect($redis->xlen($stream))->toBe(1);
    expect($redis->exists("events:attempts:{$entryId}"))->toBeTrue();

    processConsumerEntry($stream, $group, $entryId, $fields);
    expect($redis->xlen($stream))->toBe(1);

    processConsumerEntry($stream, $group, $entryId, $fields);
    expect($redis->xlen($stream))->toBe(0);
    expect($redis->xlen('events:log:dead'))->toBe(1);
    expect($redis->exists("events:attempts:{$entryId}"))->toBeFalse();
});
