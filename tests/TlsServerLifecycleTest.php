<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Connection;
use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebsocketTls\TlsServer;
use EzPhp\WebsocketTls\TlsServerException;
use Fiber;
use ReflectionMethod;

/**
 * Collects the handler callbacks a lifecycle test observed.
 */
final class TlsLifecycleLog
{
    /** @var list<string> */
    public array $events = [];
}

/**
 * In-process tests of TlsServer's per-connection lifecycle and its certificate checks.
 *
 * `run()` blocks forever and `acceptConnection()` needs a live TLS peer, so those are covered
 * by `TlsServerEndToEndTest` (child process). Here `handleConnection()` — which is what happens
 * to a connection *after* TLS — runs over a plain socket pair, and the certificate/key
 * validation is exercised directly. Visible to the coverage driver.
 */
final class TlsServerLifecycleTest extends TestCase
{
    private const string KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    private TlsLifecycleLog $log;

    private HandlerInterface $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new TlsLifecycleLog();

        $this->handler = new class ($this->log) implements HandlerInterface {
            public function __construct(private readonly TlsLifecycleLog $log)
            {
            }

            public function onOpen(ConnectionInterface $conn): void
            {
                $this->log->events[] = 'open';
            }

            public function onMessage(ConnectionInterface $conn, Frame $frame): void
            {
                $this->log->events[] = 'message:' . $frame->payload;
                $conn->send('echo:' . $frame->payload);
            }

            public function onClose(ConnectionInterface $conn): void
            {
                $this->log->events[] = 'close';
            }

            public function onError(ConnectionInterface $conn, \Throwable $e): void
            {
                $this->log->events[] = 'error:' . $e::class;
            }
        };
    }

    public function test_connection_lifecycle_after_tls_upgrade_message_ping_close(): void
    {
        [$peer, $fiber] = $this->startConnection();

        fwrite($peer, $this->upgradeRequest());
        $fiber->resume();
        self::assertStringContainsString('101 Switching Protocols', (string) fread($peer, 4096));

        fwrite($peer, $this->maskedFrame(Opcode::TEXT, 'hi'));
        $fiber->resume();
        self::assertSame('echo:hi', $this->readServerFrame($peer)->payload);

        fwrite($peer, $this->maskedFrame(Opcode::PING, 'p'));
        $fiber->resume();
        self::assertSame(Opcode::PONG, $this->readServerFrame($peer)->opcode);

        fwrite($peer, $this->maskedFrame(Opcode::BINARY, 'b'));
        fwrite($peer, $this->maskedFrame(Opcode::PONG, ''));
        fwrite($peer, $this->maskedFrame(Opcode::CLOSE, ''));
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertSame(['open', 'message:hi', 'message:b', 'close'], $this->log->events);
    }

    public function test_invalid_upgrade_request_is_reported_and_never_opens(): void
    {
        [$peer, $fiber] = $this->startConnection();

        fwrite($peer, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertSame(['error:EzPhp\WebSocket\HandshakeException'], $this->log->events);
    }

    public function test_remove_connection_of_an_unknown_resource_is_a_no_op(): void
    {
        $server = new TlsServer('127.0.0.1', 0, '/dev/null');

        (new ReflectionMethod($server, 'removeConnection'))->invoke($server, 12345);

        self::assertSame([], (new \ReflectionProperty($server, 'connections'))->getValue($server));
    }

    public function test_run_rejects_a_certificate_without_a_usable_private_key(): void
    {
        $certificate = new TestCertificate();

        try {
            // The certificate file alone (no key file) contains no private key.
            $certOnly = tempnam(sys_get_temp_dir(), 'ezphp-cert-only-');
            self::assertNotFalse($certOnly);
            $pem = (string) file_get_contents($certificate->certFile);
            file_put_contents($certOnly, (string) preg_replace('/-----BEGIN (?:RSA )?PRIVATE KEY-----.*?-----END (?:RSA )?PRIVATE KEY-----\s*/s', '', $pem));

            $server = new TlsServer('127.0.0.1', 0, $certOnly);

            $this->expectException(TlsServerException::class);
            $this->expectExceptionMessage('private key');

            try {
                $server->run($this->handler);
            } finally {
                unlink($certOnly);
            }
        } finally {
            $certificate->cleanup();
        }
    }

    public function test_run_rejects_an_unreadable_key_file(): void
    {
        $certificate = new TestCertificate();

        try {
            $server = new TlsServer('127.0.0.1', 0, $certificate->certFile, '/nonexistent/key.pem');

            $this->expectException(TlsServerException::class);
            $this->expectExceptionMessage('private key');

            $server->run($this->handler);
        } finally {
            $certificate->cleanup();
        }
    }

    public function test_ssl_options_carry_certificate_key_and_passphrase(): void
    {
        $server = new TlsServer('127.0.0.1', 0, '/c.pem', '/k.pem', 'secret');

        $options = (new ReflectionMethod($server, 'sslOptions'))->invoke($server);

        self::assertIsArray($options);
        self::assertSame('/c.pem', $options['local_cert']);
        self::assertSame('/k.pem', $options['local_pk']);
        self::assertSame('secret', $options['passphrase']);
    }

    public function test_ssl_options_omit_key_and_passphrase_when_not_given(): void
    {
        $server = new TlsServer('127.0.0.1', 0, '/c.pem');

        $options = (new ReflectionMethod($server, 'sslOptions'))->invoke($server);

        self::assertIsArray($options);
        self::assertArrayNotHasKey('local_pk', $options);
        self::assertArrayNotHasKey('passphrase', $options);
    }

    /**
     * @return array{0: resource, 1: Fiber<mixed, mixed, mixed, mixed>}
     */
    private function startConnection(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$peer, $serverSide] = $pair;
        stream_set_blocking($serverSide, false);
        stream_set_blocking($peer, false);

        $server = new TlsServer('127.0.0.1', 0, '/dev/null');
        $conn = new Connection($serverSide, '1');
        $method = new ReflectionMethod($server, 'handleConnection');
        $handler = $this->handler;

        $fiber = new Fiber(static function () use ($method, $server, $conn, $handler): void {
            $method->invoke($server, $conn, $handler);
        });
        $fiber->start();

        return [$peer, $fiber];
    }

    private function upgradeRequest(): string
    {
        return "GET /chat HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . self::KEY . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
    }

    /**
     * @param resource $peer
     */
    private function readServerFrame(mixed $peer): Frame
    {
        $buffer = '';
        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline) {
            $frame = Frame::parse($buffer);

            if ($frame !== null) {
                return $frame;
            }

            $chunk = fread($peer, 4096);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            } else {
                usleep(1000);
            }
        }

        self::fail('No complete frame from the server.');
    }

    private function maskedFrame(Opcode $opcode, string $payload): string
    {
        $mask = "\x0a\x0b\x0c\x0d";
        $masked = '';

        for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return chr((0x80 | $opcode->value) & 0xFF) . chr((0x80 | strlen($payload)) & 0xFF) . $mask . $masked;
    }
}
