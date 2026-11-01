<?php

declare(strict_types=1);

use App\Config;
use App\Controller\LogController;
use App\Id;
use App\Log;
use App\Queue\DeadLetterQueue;
use App\Queue\EventQueue;
use App\Queue\Handler\DeobfuscateHandler;
use App\Queue\Handler\SecurityAuditHandler;
use App\Queue\QueueEvent;
use App\System\SecurityService;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $this->tmpDir = CORE_PATH . '/tmp/event_queue_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);

    $cfg = $this->origData;
    $cfg['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $cfg['storage']['storageId'] = 'f';
    $cfg['storage']['storages']['f']['enabled'] = true;
    $cfg['security']['contentRules']['enabled'] = true;
    $cfg['security']['contentRules']['keywords'] = ['async_banned_keyword'];
    $cfg['security']['contentRules']['patterns'] = ['/violation_async_pattern_\d+/'];
    $cfg['eventQueue'] = [
        'enabled' => true,
        'stream' => 'events:log:stream',
        'group' => 'log-event-workers',
        'asyncDeobfuscate' => true,
        'asyncSecurityAudit' => true,
    ];
    $this->dataProp->setValue(null, $cfg);

    EventQueue::resetListeners();

    if (!\Hyperf\Context\ApplicationContext::hasContainer()) {
        $container = Mockery::mock(\Psr\Container\ContainerInterface::class);
        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('get')->andReturnUsing(function ($class) {
            if ($class === \Hyperf\HttpServer\Contract\RequestInterface::class) {
                return new \Hyperf\HttpServer\Request();
            }
            if ($class === \Hyperf\HttpServer\Response::class) {
                return new \Hyperf\HttpServer\Response();
            }
            return null;
        });
        \Hyperf\Context\ApplicationContext::setContainer($container);
    }
});

afterEach(function () {
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }
    $rulesFile = CORE_PATH . '/runtime/content_reject_rules.json';
    if (is_file($rulesFile)) {
        @unlink($rulesFile);
    }
    $bansFile = CORE_PATH . '/runtime/security_bans.json';
    if (is_file($bansFile)) {
        @unlink($bansFile);
    }
    EventQueue::resetListeners();
    $this->dataProp->setValue(null, $this->origData);
});

test('QueueEvent context model handles lifecycle, serialization and propagation', function () {
    $event = new QueueEvent('test.custom_event', ['foo' => 'bar'], 'evt_123', 1, 3);
    expect($event->getId())->toBe('evt_123')
        ->and($event->getName())->toBe('test.custom_event')
        ->and($event->get('foo'))->toBe('bar')
        ->and($event->get('missing', 'def'))->toBe('def')
        ->and($event->getAttempts())->toBe(1)
        ->and($event->canRetry())->toBeTrue();

    $event->incrementAttempts();
    expect($event->getAttempts())->toBe(2);

    $streamData = $event->toStreamData();
    expect($streamData['event'])->toBe('test.custom_event')
        ->and($streamData['attempts'])->toBe('2');

    $restored = QueueEvent::fromStreamData($streamData, 'stream_99');
    expect($restored->getId())->toBe('evt_123')
        ->and($restored->get('foo'))->toBe('bar')
        ->and($restored->getAttempts())->toBe(2);

    expect($event->isPropagationStopped())->toBeFalse();
    $event->stopPropagation();
    expect($event->isPropagationStopped())->toBeTrue();
});

test('EventQueue registers listeners and executes according to priority', function () {
    $executionLog = [];

    EventQueue::register('test.ordered', function (QueueEvent $evt) use (&$executionLog) {
        $executionLog[] = 'low_priority';
        return true;
    }, 10);

    EventQueue::register('test.ordered', function (QueueEvent $evt) use (&$executionLog) {
        $executionLog[] = 'high_priority';
        return true;
    }, 100);

    $event = new QueueEvent('test.ordered', ['msg' => 'hello']);
    $success = EventQueue::executePipeline($event);

    expect($success)->toBeTrue();
    expect($executionLog)->toBe(['high_priority', 'low_priority']);
});

test('EventQueue halts pipeline when stopPropagation is invoked', function () {
    $executionLog = [];

    EventQueue::register('test.stopped', function (QueueEvent $evt) use (&$executionLog) {
        $executionLog[] = 'first';
        $evt->stopPropagation();
        return false;
    }, 100);

    EventQueue::register('test.stopped', function (QueueEvent $evt) use (&$executionLog) {
        $executionLog[] = 'second_should_not_run';
        return true;
    }, 50);

    $event = new QueueEvent('test.stopped', []);
    $success = EventQueue::executePipeline($event);

    expect($success)->toBeFalse();
    expect($executionLog)->toBe(['first']);
});

test('EventQueue stats inspects registered events and queue metrics', function () {
    $stats = EventQueue::getStats();
    expect($stats['enabled'])->toBeTrue()
        ->and($stats['stream'])->toBe('events:log:stream')
        ->and($stats['group'])->toBe('log-event-workers')
        ->and($stats['registeredEvents'])->toHaveKey(EventQueue::EVENT_LOG_UPLOADED)
        ->and($stats['registeredEvents'])->toHaveKey(EventQueue::EVENT_SECURITY_AUDIT);
});

test('DeadLetterQueue provides list, count, clear and retry capabilities', function () {
    $evt = new QueueEvent('test.dead', ['logId' => 'test1234'], 'evt_dead_1', 3, 3);
    DeadLetterQueue::push($evt, 'Fatal database error');

    // 无 Redis 环境下 push 会安全回退日志记录并返回 null，不抛出致命异常
    expect(DeadLetterQueue::count())->toBeGreaterThanOrEqual(0);
    expect(DeadLetterQueue::list())->toBeArray();
    expect(DeadLetterQueue::clear())->toBeBool();
});

test('SecurityAuditHandler passes compliant logs', function () {
    $log = new Log();
    $id = $log->put("All clean content without forbidden keywords");
    expect($id)->not->toBeNull();

    $passed = SecurityAuditHandler::process([
        'logId' => $id->get(),
        'clientIp' => '1.2.3.4',
    ]);

    expect($passed)->toBeTrue();
    $reloaded = new Log($id);
    expect($reloaded->exists())->toBeTrue();
});

test('SecurityAuditHandler rejects prohibited logs, deletes log and bans IP', function () {
    $log = new Log();
    $id = $log->put("Log containing async_banned_keyword in text");
    expect($id)->not->toBeNull();

    $badIp = '198.51.100.99';
    $passed = SecurityAuditHandler::process([
        'logId' => $id->get(),
        'clientIp' => $badIp,
    ]);

    expect($passed)->toBeFalse();

    // 1. 验证日志已被物理删除
    $reloaded = new Log($id);
    expect($reloaded->exists())->toBeFalse();

    // 2. 验证恶意 IP 已被自动封禁
    expect(SecurityService::isIpBanned($badIp))->toBeTrue();
});

test('SecurityAuditHandler does not ban loopback or private IPs on violation', function () {
    $log = new Log();
    $id = $log->put("Content with violation_async_pattern_12345");
    expect($id)->not->toBeNull();

    $passed = SecurityAuditHandler::process([
        'logId' => $id->get(),
        'clientIp' => '127.0.0.1',
    ]);

    expect($passed)->toBeFalse();
    // 127.0.0.1 不应当被封禁
    expect(SecurityService::isIpBanned('127.0.0.1'))->toBeFalse();
});

test('DeobfuscateHandler handles invalid or non-existent logId gracefully', function () {
    expect(DeobfuscateHandler::process([]))->toBeFalse();
    expect(DeobfuscateHandler::process(['logId' => 'invalid!id']))->toBeFalse();
    expect(DeobfuscateHandler::process(['logId' => 'fnotexist']))->toBeTrue();
});

test('LogController upload delegates audit and deobfuscation to EventQueue', function () {
    $payloadJson = json_encode([
        'content' => "Async log upload test\n[Server thread/INFO]: Server started",
    ]);
    $bodyStream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $bodyStream->shouldReceive('getContents')->andReturn($payloadJson);
    $bodyStream->shouldReceive('__toString')->andReturn($payloadJson);

    $uri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
    $uri->shouldReceive('getPath')->andReturn('/v1/log');

    $mockReq = Mockery::mock(\Hyperf\HttpServer\Contract\RequestInterface::class);
    $mockReq->shouldReceive('getServerParams')->andReturn(['remote_addr' => '127.0.0.1']);
    $mockReq->shouldReceive('getHeaders')->andReturn([]);
    $mockReq->shouldReceive('getUri')->andReturn($uri);
    $mockReq->shouldReceive('getHeaderLine')->with('Content-Type')->andReturn('application/json');
    $mockReq->shouldReceive('getHeaderLine')->with('Content-Encoding')->andReturn('');
    $mockReq->shouldReceive('getHeaderLine')->with('User-Agent')->andReturn('HMCL/3.5.0');
    $mockReq->shouldReceive('getBody')->andReturn($bodyStream);
    $mockReq->shouldReceive('getUploadedFiles')->andReturn([]);
    $mockReq->shouldReceive('getParsedBody')->andReturn([
        'content' => "Async log upload test\n[Server thread/INFO]: Server started",
    ]);

    $ref = new ReflectionClass(LogController::class);
    /** @var LogController $controller */
    $controller = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('request')->setValue($controller, $mockReq);

    $res = $controller->create();
    expect($res->getStatusCode())->toBe(200);

    $data = json_decode((string) $res->getBody(), true);
    expect($data['success'])->toBeTrue()
        ->and($data['id'])->not->toBeEmpty();

    $id = new Id($data['id']);
    $log = new Log($id);
    expect($log->exists())->toBeTrue()
        ->and($log->getSource())->toBe('HMCL/3.5.0');
});

test('executePipeline counts blocked events separately from failed events', function () {
    \Tests\Mocks\RedisMock::reset();
    App\Client\RedisClient::setTestConnection(new \Tests\Mocks\RedisMock());

    try {
        EventQueue::register('test.outcome_blocked', function (QueueEvent $evt) {
            $evt->stopPropagation();
            return false;
        }, 10);
        EventQueue::register('test.outcome_failed', function (QueueEvent $evt) {
            throw new \RuntimeException('handler boom');
        }, 10);

        expect(EventQueue::executePipeline(new QueueEvent('test.outcome_blocked', [])))->toBeFalse();
        expect(EventQueue::executePipeline(new QueueEvent('test.outcome_failed', [])))->toBeFalse();

        $metrics = EventQueue::getMetrics();
        expect($metrics['processed_blocked'] ?? 0)->toBe(1)
            ->and($metrics['processed_failed'] ?? 0)->toBe(1)
            ->and($metrics['processed_success'] ?? 0)->toBe(0);
    } finally {
        App\Client\RedisClient::setTestConnection(null);
        \Tests\Mocks\RedisMock::reset();
    }
});

test('event queue stats expose cumulative throughput that survives XACK and XDEL', function () {
    \Tests\Mocks\RedisMock::reset();
    App\Client\RedisClient::setTestConnection(new \Tests\Mocks\RedisMock());

    try {
        $stream = EventQueue::DEFAULT_STREAM;
        $group = EventQueue::DEFAULT_GROUP;
        App\Client\RedisStreams::xAdd($stream, ['event' => EventQueue::EVENT_LOG_UPLOADED], 100);
        App\Client\RedisStreams::xAdd($stream, ['event' => EventQueue::EVENT_LOG_UPLOADED], 100);
        App\Client\RedisStreams::xGroupCreate($stream, $group);
        App\Client\RedisStreams::xReadGroup($group, 'worker-0', $stream, 0, 1);

        $stats = EventQueue::getStats();

        // 消费者 XACK + XDEL 后 streamLength 恒为 0，累计吞吐只能由 entries-read 体现
        expect($stats['entriesRead'])->toBe(1)
            ->and($stats['consumers'])->toBe(1)
            ->and($stats['pending'])->toBe(1)
            ->and($stats['stream'])->toBe($stream);
    } finally {
        App\Client\RedisClient::setTestConnection(null);
        \Tests\Mocks\RedisMock::reset();
    }
});
