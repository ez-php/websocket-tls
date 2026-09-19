<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\Opcode;
use RuntimeException;

/**
 * Runs a real TlsServer in a child process and talks to it with a raw WSS client:
 * TLS handshake, WebSocket upgrade, text/ping/close frames, and the failure paths
 * (non-TLS client, invalid upgrade request). Loopback only; no internet access needed.
 *
 * The server runs in a child process, so its lines are not visible to the parent's coverage driver;
 * this suite guards behaviour, and `TlsServerTest` keeps the in-process coverage.
 */
final class TlsServerEndToEndTest extends TestCase
{
    private const string GUID_KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    /** @var resource|null */
    private static mixed $server = null;

    private static int $port = 0;

    private static string $log = '';

    private static ?TestCertificate $certificate = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$certificate = new TestCertificate();
        self::$log = sys_get_temp_dir() . '/ez-php-tls-e2e-' . bin2hex(random_bytes(4)) . '.log';
        file_put_contents(self::$log, '');

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($probe === false) {
            throw new RuntimeException('Could not reserve a port: ' . $errstr);
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $server = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Support/tls-echo-server.php',
                (string) self::$port,
                self::$certificate->certFile,
                self::$certificate->keyFile,
                self::$log,
            ],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!is_resource($server)) {
            throw new RuntimeException('Could not start the WSS server process.');
        }

        self::$server = $server;

        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            // Connection-refused warnings are expected until the server is listening.
            set_error_handler(static fn (): bool => true, E_WARNING);

            try {
                $tcp = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 0.2);
            } finally {
                restore_error_handler();
            }

            if ($tcp !== false) {
                fclose($tcp);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The WSS server did not accept connections within 5 seconds.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;

        if (self::$log !== '' && is_file(self::$log)) {
            unlink(self::$log);
        }

        self::$certificate?->cleanup();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        file_put_contents(self::$log, '');
    }

    public function test_it_upgrades_a_wss_connection_and_echoes_text_frames(): void
    {
        $client = $this->openClient();
        $this->assertStringContainsString('101 Switching Protocols', $this->upgrade($client));

        fwrite($client, $this->maskedFrame(Opcode::TEXT, 'hello'));

        $frame = $this->readFrame($client);

        self::assertSame(Opcode::TEXT, $frame->opcode);
        self::assertSame('echo:hello', $frame->payload);
        self::assertContains('open', $this->logLines());
        self::assertContains('message:hello', $this->logLines());

        fclose($client);
    }

    public function test_it_answers_ping_with_pong_carrying_the_same_payload(): void
    {
        $client = $this->openClient();
        $this->upgrade($client);

        fwrite($client, $this->maskedFrame(Opcode::PING, 'are-you-there'));

        $frame = $this->readFrame($client);

        self::assertSame(Opcode::PONG, $frame->opcode);
        self::assertSame('are-you-there', $frame->payload);

        fclose($client);
    }

    public function test_a_close_frame_ends_the_connection_and_fires_on_close(): void
    {
        $client = $this->openClient();
        $this->upgrade($client);

        fwrite($client, $this->maskedFrame(Opcode::CLOSE, ''));

        $frame = $this->readFrame($client);
        self::assertSame(Opcode::CLOSE, $frame->opcode);

        self::assertTrue($this->waitForLog('close'), 'onClose was not called');

        fclose($client);
    }

    public function test_an_invalid_upgrade_request_reports_a_handshake_error(): void
    {
        $client = $this->openClient();

        fwrite($client, "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

        self::assertTrue(
            $this->waitForLog('error:EzPhp\WebSocket\HandshakeException'),
            'HandshakeException was not reported: ' . implode(',', $this->logLines()),
        );
        self::assertNotContains('open', $this->logLines());

        fclose($client);
    }

    public function test_a_client_that_does_not_speak_tls_is_dropped_without_disturbing_the_server(): void
    {
        $tcp = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 2);
        self::assertNotFalse($tcp, (string) $errstr);
        fwrite($tcp, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        fclose($tcp);

        // The server must still serve a proper WSS client afterwards.
        $client = $this->openClient();
        $this->assertStringContainsString('101 Switching Protocols', $this->upgrade($client));

        fwrite($client, $this->maskedFrame(Opcode::TEXT, 'still-alive'));
        self::assertSame('echo:still-alive', $this->readFrame($client)->payload);

        self::assertSame([], array_filter($this->logLines(), static fn (string $l): bool => str_starts_with($l, 'error:')));

        fclose($client);
    }

    public function test_it_serves_several_clients_concurrently(): void
    {
        $a = $this->openClient();
        $b = $this->openClient();
        $this->upgrade($a);
        $this->upgrade($b);

        fwrite($b, $this->maskedFrame(Opcode::TEXT, 'from-b'));
        fwrite($a, $this->maskedFrame(Opcode::TEXT, 'from-a'));

        self::assertSame('echo:from-a', $this->readFrame($a)->payload);
        self::assertSame('echo:from-b', $this->readFrame($b)->payload);

        fclose($a);
        fclose($b);
    }

    /**
     * @return resource
     */
    private function openClient(): mixed
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);

        $client = stream_socket_client(
            'ssl://127.0.0.1:' . self::$port,
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        self::assertNotFalse($client, 'WSS connect failed: ' . (string) $errstr . " ({$errno})");
        stream_set_timeout($client, 5);

        return $client;
    }

    /**
     * Send the HTTP upgrade request and return the raw response head.
     *
     * @param resource $client
     */
    private function upgrade(mixed $client): string
    {
        fwrite($client, "GET /chat HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . self::GUID_KEY . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n");

        $head = '';

        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($client, 1024);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $head .= $chunk;
        }

        return $head;
    }

    /**
     * Read exactly one server frame.
     *
     * @param resource $client
     */
    private function readFrame(mixed $client): Frame
    {
        $buffer = '';
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $frame = Frame::parse($buffer);

            if ($frame !== null) {
                return $frame;
            }

            $chunk = fread($client, 4096);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            }
        }

        self::fail('No complete frame received from the server.');
    }

    /**
     * Build a client-to-server frame (RFC 6455 requires clients to mask).
     */
    private function maskedFrame(Opcode $opcode, string $payload): string
    {
        $mask = "\x11\x22\x33\x44";
        $masked = '';

        for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return chr((0x80 | $opcode->value) & 0xFF) . chr((0x80 | strlen($payload)) & 0xFF) . $mask . $masked;
    }

    /**
     * @return list<string>
     */
    private function logLines(): array
    {
        $lines = file(self::$log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : $lines;
    }

    private function waitForLog(string $line): bool
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            if (in_array($line, $this->logLines(), true)) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }
}
