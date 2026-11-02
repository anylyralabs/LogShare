<?php

declare(strict_types=1);

namespace App\Client\GoTask;

use Spiral\Goridge\Exceptions\PrefixException;
use Spiral\Goridge\Exceptions\RelayException;
use Spiral\Goridge\Exceptions\TransportException;
use Spiral\Goridge\RelayInterface;
use Swoole\Coroutine\Socket;

/**
 * 可靠版 Goridge Unix/TCP 中继。
 *
 * hyperf/gotask 自带的 SocketTransporter 对 send() 只调用一次且忽略返回值；
 * Swoole 协程 socket 在发送较大 payload（几百 KB 以上）时可能只写入一部分，
 * 于是 Go 边车一直等待剩余字节，PHP 侧直到超时才报错。这里循环写满整个
 * frame，并对接收端做定长读取，保证大文件分块也能完整传输。
 */
final class ReliableCoroutineSocketRelay implements RelayInterface
{
    public const SOCK_TCP = 0;

    public const SOCK_UNIX = 1;

    private const WRITE_CHUNK = 65536;

    private ?Socket $socket = null;

    public function __construct(
        private readonly string $address,
        private readonly int $port = 0,
        private readonly int $type = self::SOCK_TCP,
    ) {
    }

    public function __destruct()
    {
        if ($this->isConnected()) {
            $this->close();
        }
    }

    public function __toString(): string
    {
        return $this->type === self::SOCK_TCP
            ? "tcp://{$this->address}:{$this->port}"
            : "unix://{$this->address}";
    }

    public function connect(): bool
    {
        if ($this->isConnected()) {
            return true;
        }

        $lastError = 'unknown error';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $socket = $this->type === self::SOCK_UNIX
                ? new Socket(AF_UNIX, SOCK_STREAM, 0)
                : new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);

            $socket->setOption(SOL_SOCKET, SO_RCVTIMEO, ['sec' => 120, 'usec' => 0]);
            $socket->setOption(SOL_SOCKET, SO_SNDTIMEO, ['sec' => 120, 'usec' => 0]);

            if ($socket->connect($this->address, $this->port) !== false) {
                $this->socket = $socket;
                return true;
            }

            $lastError = $socket->errMsg . ' (' . $socket->errCode . ')';
            $socket->close();
            usleep(100000);
        }

        throw new RelayException("unable to establish connection (20x) {$this}: {$lastError}");
    }

    public function send(string $payload, ?int $flags = null): void
    {
        $this->connect();
        $size = strlen($payload);
        $frame = pack('CPJ', $flags ?? 0, $size, $size) . $payload;

        $offset = 0;
        $length = strlen($frame);
        while ($offset < $length) {
            $written = $this->socket()->send(substr($frame, $offset, self::WRITE_CHUNK));
            if ($written === false) {
                throw new TransportException('unable to write to GoTask socket: ' . $this->socket()->errMsg);
            }
            if ($written === 0) {
                usleep(1000);
                continue;
            }
            $offset += $written;
        }
    }

    /**
     * @param-out int $flags
     */
    public function receiveSync(?int &$flags = null): ?string
    {
        $this->connect();
        $prefix = $this->fetchPrefix();
        $flags = $prefix['flags'];

        if ($prefix['size'] === 0) {
            return null;
        }

        return $this->readExact($prefix['size'], TransportException::class);
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }
    }

    /**
     * @return array{flags: int, size: int}
     */
    private function fetchPrefix(): array
    {
        $prefixBody = $this->readExact(17, PrefixException::class);
        $result = unpack('Cflags/Psize/Jrevs', $prefixBody);
        if (!is_array($result)) {
            throw new PrefixException('invalid GoTask prefix');
        }
        if ($result['size'] !== $result['revs']) {
            throw new PrefixException('invalid GoTask prefix checksum');
        }

        return ['flags' => (int) $result['flags'], 'size' => (int) $result['size']];
    }

    /**
     * @param class-string<PrefixException|TransportException> $exceptionClass
     */
    private function readExact(int $length, string $exceptionClass): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = $this->socket()->recv(min(self::WRITE_CHUNK, $length - strlen($buffer)));
            if ($chunk === false || $chunk === '') {
                throw new $exceptionClass('unable to read from GoTask socket: ' . $this->socket()->errMsg);
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function socket(): Socket
    {
        if ($this->socket === null) {
            throw new RelayException('GoTask socket is not connected');
        }

        return $this->socket;
    }
}
