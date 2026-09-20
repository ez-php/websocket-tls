<?php

declare(strict_types=1);

namespace EzPhp\WebsocketTls;

use EzPhp\WebSocket\Connection;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\HandshakeException;
use EzPhp\WebSocket\Opcode;
use Fiber;

/**
 * TLS/WSS-terminating counterpart to `ez-php/websocket`'s `Server`.
 *
 * Listens on a plain `tcp://` stream socket whose context carries the certificate,
 * and negotiates the TLS handshake for each accepted connection via {@see CryptoNegotiator},
 * then hands the negotiated stream to the same `Connection` class the
 * plain-TCP server uses — the RFC 6455 handshake, frame codec, and Fiber
 * event loop are unchanged.
 *
 * The listener is deliberately `tcp://`, not `ssl://`: on an `ssl://` server socket PHP
 * completes the TLS handshake inside `stream_socket_accept()` — blocking the event loop
 * on every slow or stalled client — and a second `stream_socket_enable_crypto()` on that
 * already-encrypted socket then fails, which used to drop every connection. With `tcp://`
 * the handshake is driven by the negotiator, non-blocking and bounded by a deadline.
 *
 * Usage:
 *
 *   $server = new TlsServer('0.0.0.0', 8443, '/path/cert.pem', '/path/key.pem');
 *   $server->run(new MyHandler()); // blocks until interrupted
 *
 * @package EzPhp\WebsocketTls
 */
final class TlsServer
{
    /**
     * Maximum time, in seconds, to spend negotiating TLS with one client
     * before giving up on that connection.
     */
    private const HANDSHAKE_TIMEOUT = 5.0;

    /**
     * Connections indexed by resource ID.
     *
     * @var array<int, Connection>
     */
    private array $connections = [];

    /**
     * Fibers indexed by resource ID.
     *
     * @var array<int, Fiber<mixed, mixed, mixed, mixed>>
     */
    private array $fibers = [];

    /**
     * Raw stream sockets indexed by resource ID (for stream_select).
     *
     * @var array<int, resource>
     */
    private array $sockets = [];

    private int $nextId = 1;

    private readonly CryptoNegotiator $cryptoNegotiator;

    /**
     * @param string      $host        Bind address (e.g. '0.0.0.0' or '127.0.0.1')
     * @param int         $port        TCP port to listen on
     * @param string      $certFile    Path to a PEM-encoded certificate (and, unless
     *                                 `$keyFile` is given, its private key)
     * @param string|null $keyFile     Path to a PEM-encoded private key, if kept
     *                                 separate from `$certFile`
     * @param string|null $passphrase  Passphrase protecting the private key, if any
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $certFile,
        private readonly ?string $keyFile = null,
        private readonly ?string $passphrase = null,
        ?CryptoNegotiator $cryptoNegotiator = null,
    ) {
        $this->cryptoNegotiator = $cryptoNegotiator ?? new CryptoNegotiator();
    }

    /**
     * Returns the bind address.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Returns the listen port.
     */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * Starts the WSS server and blocks until an unrecoverable error occurs.
     *
     * @throws TlsServerException when the server socket cannot be created
     */
    public function run(HandlerInterface $handler): void
    {
        $this->assertCertificateReadable();

        $context = stream_context_create(['ssl' => $this->sslOptions()]);

        $errno = 0;
        $errstr = '';

        [$serverSocket, $warning] = $this->callCapturingWarning(
            function () use (&$errno, &$errstr, $context) {
                return stream_socket_server(
                    "tcp://{$this->host}:{$this->port}",
                    $errno,
                    $errstr,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                    $context,
                );
            }
        );

        if ($serverSocket === false) {
            $reason = $errstr !== '' ? $errstr : ($warning ?? 'unknown error');

            throw new TlsServerException(
                "Cannot start WSS server on {$this->host}:{$this->port}: {$reason} ({$errno})"
            );
        }

        stream_set_blocking($serverSocket, false);

        try {
            $this->loop($handler, $serverSocket);
        } finally {
            fclose($serverSocket);
        }
    }

    /**
     * Validates the certificate (and key, if separate) eagerly so a bad path
     * or unparseable PEM fails `run()` immediately. PHP's `ssl://` stream
     * wrapper otherwise only loads the certificate lazily on the first TLS
     * handshake, which would silently fail every incoming connection instead
     * of raising an error the caller can act on.
     *
     * @throws TlsServerException when the certificate or key cannot be read
     */
    private function assertCertificateReadable(): void
    {
        $certPem = is_readable($this->certFile) ? file_get_contents($this->certFile) : false;

        if ($certPem === false || self::quietly(static fn (): mixed => openssl_x509_read($certPem)) === false) {
            throw new TlsServerException(
                "Cannot read a valid TLS certificate from {$this->certFile}: "
                . (openssl_error_string() ?: 'file missing or not a PEM certificate')
            );
        }

        $keyFile = $this->keyFile ?? $this->certFile;
        $keyPem = is_readable($keyFile) ? file_get_contents($keyFile) : false;

        if ($keyPem === false || self::quietly(fn (): mixed => openssl_pkey_get_private($keyPem, $this->passphrase ?? '')) === false) {
            throw new TlsServerException(
                "Cannot read a valid private key from {$keyFile}: "
                . (openssl_error_string() ?: 'file missing or not a PEM private key')
            );
        }
    }

    /**
     * Run an OpenSSL call whose warning on bad input is reported through the
     * exception message instead, keeping `openssl_error_string()` intact.
     *
     * @param callable(): mixed $call
     *
     * @return mixed
     */
    private static function quietly(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sslOptions(): array
    {
        $options = [
            'local_cert' => $this->certFile,
            'allow_self_signed' => true,
            'verify_peer' => false,
        ];

        if ($this->keyFile !== null) {
            $options['local_pk'] = $this->keyFile;
        }

        if ($this->passphrase !== null) {
            $options['passphrase'] = $this->passphrase;
        }

        return $options;
    }

    /**
     * Main event loop: accept connections and dispatch Fiber resumes.
     *
     * @param resource $serverSocket
     */
    private function loop(HandlerInterface $handler, $serverSocket): void
    {
        while (true) {
            $read = [$serverSocket];
            foreach ($this->sockets as $socket) {
                $read[] = $socket;
            }

            $write = null;
            $except = null;

            $count = stream_select($read, $write, $except, 0, 50_000);

            if ($count === false) {
                break;
            }

            foreach ($read as $readable) {
                if (!is_resource($readable)) {
                    continue;
                }

                if ($readable === $serverSocket) {
                    $this->acceptConnection($handler, $serverSocket);
                } else {
                    $rid = get_resource_id($readable);
                    $fiber = $this->fibers[$rid] ?? null;
                    if ($fiber !== null && $fiber->isSuspended()) {
                        $fiber->resume();
                        if ($fiber->isTerminated()) {
                            $this->removeConnection($rid);
                        }
                    }
                }
            }

            foreach ($this->fibers as $rid => $fiber) {
                if ($fiber->isTerminated()) {
                    $this->removeConnection($rid);
                }
            }
        }
    }

    /**
     * Accepts one new TLS connection, completes the crypto handshake, and
     * starts its Fiber. Connections whose TLS handshake fails or times out
     * are closed without ever reaching a `Connection`/`HandlerInterface`.
     *
     * @param resource $serverSocket
     */
    private function acceptConnection(HandlerInterface $handler, $serverSocket): void
    {
        // With a zero timeout "no pending connection" is reported as a warning; that is expected here.
        [$clientSocket] = $this->callCapturingWarning(static fn () => stream_socket_accept($serverSocket, 0));

        if ($clientSocket === false) {
            return;
        }

        stream_set_blocking($clientSocket, false);

        if (!$this->cryptoNegotiator->negotiate($clientSocket, STREAM_CRYPTO_METHOD_TLS_SERVER, self::HANDSHAKE_TIMEOUT)) {
            fclose($clientSocket);
            return;
        }

        $conn = new Connection($clientSocket, (string) $this->nextId++);
        $rid = get_resource_id($clientSocket);

        $this->connections[$rid] = $conn;
        $this->sockets[$rid] = $clientSocket;

        $fiber = new Fiber(function () use ($conn, $handler): void {
            $this->handleConnection($conn, $handler);
        });

        $this->fibers[$rid] = $fiber;
        $fiber->start();

        if ($fiber->isTerminated()) {
            $this->removeConnection($rid);
        }
    }

    /**
     * Connection lifecycle: WebSocket handshake → frame loop → close.
     * Runs inside a Fiber; suspends when no data is available.
     */
    private function handleConnection(Connection $conn, HandlerInterface $handler): void
    {
        try {
            $conn->handshake();
        } catch (HandshakeException $e) {
            $handler->onError($conn, $e);
            return;
        }

        $handler->onOpen($conn);

        try {
            while ($conn->isConnected()) {
                $frame = $conn->readFrame();

                if ($frame === null) {
                    Fiber::suspend();
                    continue;
                }

                match ($frame->opcode) {
                    Opcode::TEXT, Opcode::BINARY => $handler->onMessage($conn, $frame),
                    Opcode::CLOSE => $conn->close(),
                    Opcode::PING => $conn->sendPong($frame->payload),
                    Opcode::PONG, Opcode::CONTINUATION => null,
                };
            }
        } catch (\Throwable $e) {
            $handler->onError($conn, $e);
        } finally {
            $handler->onClose($conn);
        }
    }

    /**
     * Removes all tracking state for the given resource ID.
     */
    private function removeConnection(int $rid): void
    {
        unset($this->connections[$rid], $this->fibers[$rid], $this->sockets[$rid]);
    }

    /**
     * Run a stream/filesystem call with PHP warnings converted into a returned message
     * instead of being emitted (replaces the `@` operator, which hides the reason).
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return array{0: T, 1: string|null} The call's result and the captured warning message, if any.
     */
    private function callCapturingWarning(callable $fn): array
    {
        $warning = null;

        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;

            return true;
        }, E_WARNING);

        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }

        return [$result, $warning];
    }
}
