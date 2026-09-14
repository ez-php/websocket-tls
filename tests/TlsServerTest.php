<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebsocketTls\TlsServer;
use EzPhp\WebsocketTls\TlsServerException;

/**
 * Unit-level tests for the TlsServer class.
 *
 * Full end-to-end tests (connect a real WSS client, send messages, verify the
 * handler fires) require running the server in a separate process and are out
 * of scope for this suite — see `ServerTest` in `ez-php/websocket` for the same
 * boundary, and `CryptoNegotiatorTest` for the real-handshake coverage this
 * module adds.
 *
 * @covers \EzPhp\WebsocketTls\TlsServer
 */
final class TlsServerTest extends TestCase
{
    private static function noopHandler(): HandlerInterface
    {
        return new class () implements HandlerInterface {
            public function onOpen(ConnectionInterface $conn): void
            {
            }

            public function onMessage(ConnectionInterface $conn, Frame $frame): void
            {
            }

            public function onClose(ConnectionInterface $conn): void
            {
            }

            public function onError(ConnectionInterface $conn, \Throwable $e): void
            {
            }
        };
    }

    public function test_constructor_and_accessors(): void
    {
        $server = new TlsServer('127.0.0.1', 9443, '/dev/null');

        self::assertSame('127.0.0.1', $server->host());
        self::assertSame(9443, $server->port());
    }

    public function test_run_throws_when_the_certificate_is_invalid(): void
    {
        $server = new TlsServer('127.0.0.1', 19877, '/dev/null');

        $this->expectException(TlsServerException::class);

        $server->run(self::noopHandler());
    }

    public function test_run_throws_when_port_already_in_use(): void
    {
        $blocker = @stream_socket_server('tcp://127.0.0.1:19878');

        if ($blocker === false) {
            $this->markTestSkipped('Cannot bind test port 19878');
        }

        $certificate = new TestCertificate();

        try {
            $server = new TlsServer('127.0.0.1', 19878, $certificate->certFile, $certificate->keyFile);

            $this->expectException(TlsServerException::class);

            $server->run(self::noopHandler());
        } finally {
            fclose($blocker);
            $certificate->cleanup();
        }
    }
}
