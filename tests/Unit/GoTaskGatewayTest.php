<?php

declare(strict_types=1);

use App\Client\Mongo\GoTaskGateway;
use App\Client\Mongo\MongoException;
use App\Client\Mongo\MongoUnavailableException;
use Hyperf\GoTask\GoTask;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use Tests\Support\RecordingGoTask;

function gatewayWith(RecordingGoTask $task): GoTaskGateway
{
    return new GoTaskGateway($task, 'logshare');
}

test('findOne uses Find with limit 1 and returns the first document', function () {
    $task = new RecordingGoTask(function (string $method, array $payload): array {
        expect($method)->toBe('MongoProxy.Find');
        expect($payload['Database'])->toBe('logshare');
        expect($payload['Collection'])->toBe('logs');
        expect($payload['Opts']['limit'])->toBe(1);

        return [['Database' => 'ignored', '_id' => 'first']];
    });

    $result = gatewayWith($task)->findOne('logs', ['_id' => 'first']);

    expect($result)->toBe(['Database' => 'ignored', '_id' => 'first']);
});

test('findOne returns null when the result set is empty', function () {
    $task = new RecordingGoTask(fn (): array => []);

    expect(gatewayWith($task)->findOne('logs', ['_id' => 'missing']))->toBeNull();
});

test('empty filter and options are encoded as BSON documents', function () {
    $task = new RecordingGoTask(fn (): array => []);
    gatewayWith($task)->find('logs', [], []);

    $payload = Document::fromBSON($task->calls[0]['raw']);
    expect($payload->get('Filter'))->toBeInstanceOf(Document::class);
    expect($payload->get('Opts'))->toBeInstanceOf(Document::class);
});

test('update and delete result counts are normalized from BSON Int64', function () {
    $task = new RecordingGoTask(function (string $method): array {
        return $method === 'MongoProxy.UpdateOne'
            ? ['matchedcount' => new Int64(2), 'modifiedcount' => new Int64(2)]
            : ['n' => new Int64(3)];
    });

    $gateway = gatewayWith($task);
    expect($gateway->updateOne('logs', ['_id' => 'x'], ['$set' => ['a' => 1]]))->toBe(2);
    expect($gateway->deleteOne('logs', ['_id' => 'x']))->toBe(3);
});

test('insertMany sends the document records', function () {
    $task = new RecordingGoTask(function (string $method, array $payload): ?array {
        expect($method)->toBe('MongoProxy.InsertMany');
        expect($payload['Records'])->toHaveCount(2);

        return null;
    });

    gatewayWith($task)->insertMany('logs', [
        ['_id' => 'a', 'value' => 1],
        ['_id' => 'b', 'value' => 2],
    ]);

    expect($task->calls)->toHaveCount(1);
});

test('ping reports ok only for a successful command', function () {
    $ok = new RecordingGoTask(fn (): array => ['ok' => 1]);
    $failed = new RecordingGoTask(fn (): array => ['ok' => 0]);

    expect(gatewayWith($ok)->ping())->toBeTrue();
    expect(gatewayWith($failed)->ping())->toBeFalse();
});

test('transport failures are classified as unavailable', function () {
    $task = new RecordingGoTask(function (): never {
        throw new RuntimeException('unable to establish connection');
    });

    expect(fn () => gatewayWith($task)->find('logs', ['_id' => 'x']))
        ->toThrow(MongoUnavailableException::class);
});

test('remote command failures stay as MongoException', function () {
    $task = new RecordingGoTask(function (): never {
        throw new RuntimeException('E11000 duplicate key error');
    });

    expect(fn () => gatewayWith($task)->insertMany('logs', [['_id' => 'x']]))
        ->toThrow(MongoException::class);
});

test('GoTask payload flag is raw binary', function () {
    $task = new RecordingGoTask(fn (): array => []);
    gatewayWith($task)->find('logs', ['_id' => 'x']);

    expect($task->calls[0]['flags'])->toBe(GoTask::PAYLOAD_RAW);
});
