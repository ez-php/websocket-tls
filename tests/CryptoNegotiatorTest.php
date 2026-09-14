<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebsocketTls\CryptoNegotiator;

/**
 * Class CryptoNegotiatorTest
 *
 * @package Tests
 */
final class CryptoNegotiatorTest extends TestCase
{
    /**
     * @return array{0: resource, 1: resource, 2: resource}
     */
    private function connectedPair(TestCertificate $certificate): array
    {
        $serverContext = stream_context_create(['ssl' => [
            'local_cert' => $certificate->certFile,
            'local_pk' => $certificate->keyFile,
            'allow_self_signed' => true,
            'verify_peer' => false,
        ]]);

        $listener = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $serverContext,
        );

        self::assertNotFalse($listener, "Could not start a test TCP listener: {$errstr} ({$errno})");
        stream_set_blocking($listener, false);

        $address = stream_socket_get_name($listener, false);

        $clientContext = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]);

        $client = stream_socket_client(
            "tcp://{$address}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $clientContext,
        );

        self::assertNotFalse($client, "Could not connect the test TLS client: {$errstr} ({$errno})");
        stream_set_blocking($client, false);

        $server = false;
        $deadline = microtime(true) + 3;

        while ($server === false && microtime(true) < $deadline) {
            $server = @stream_socket_accept($listener, 0);
        }

        self::assertNotFalse($server, 'Test TCP listener never accepted the client connection.');
        stream_set_blocking($server, false);

        fclose($listener);

        return [$server, $client, $listener];
    }

    public function test_negotiate_completes_a_real_tls_handshake(): void
    {
        $certificate = new TestCertificate();

        try {
            [$server, $client] = $this->connectedPair($certificate);

            $negotiator = new CryptoNegotiator();
            $serverDone = false;
            $clientDone = false;
            $deadline = microtime(true) + 5;

            while ((!$serverDone || !$clientDone) && microtime(true) < $deadline) {
                if (!$serverDone) {
                    $serverDone = $negotiator->negotiate($server, STREAM_CRYPTO_METHOD_TLS_SERVER, 0.01);
                }

                if (!$clientDone) {
                    $clientDone = $this->pumpClient($client);
                }
            }

            self::assertTrue($serverDone, 'Server-side TLS negotiation did not complete.');
            self::assertTrue($clientDone, 'Client-side TLS negotiation did not complete.');

            fclose($server);
            fclose($client);
        } finally {
            $certificate->cleanup();
        }
    }

    public function test_negotiate_returns_false_when_the_peer_never_speaks_tls(): void
    {
        $certificate = new TestCertificate();

        try {
            $serverContext = stream_context_create(['ssl' => [
                'local_cert' => $certificate->certFile,
                'local_pk' => $certificate->keyFile,
                'allow_self_signed' => true,
                'verify_peer' => false,
            ]]);

            $listener = stream_socket_server(
                'tcp://127.0.0.1:0',
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $serverContext,
            );

            self::assertNotFalse($listener);
            stream_set_blocking($listener, false);

            $address = stream_socket_get_name($listener, false);
            $plainClient = stream_socket_client("tcp://{$address}", $errno, $errstr, 5);
            self::assertNotFalse($plainClient);

            $server = false;
            $deadline = microtime(true) + 3;

            while ($server === false && microtime(true) < $deadline) {
                $server = @stream_socket_accept($listener, 0);
            }

            self::assertNotFalse($server);
            stream_set_blocking($server, false);

            // The client writes plain bytes instead of a TLS ClientHello, so the
            // server-side handshake must fail rather than hang.
            fwrite($plainClient, "not a tls handshake\n");

            $negotiator = new CryptoNegotiator();
            $result = $negotiator->negotiate($server, STREAM_CRYPTO_METHOD_TLS_SERVER, 1.0);

            self::assertFalse($result);

            fclose($server);
            fclose($plainClient);
            fclose($listener);
        } finally {
            $certificate->cleanup();
        }
    }

    /**
     * Drives the client side of the handshake started by `connectedPair()`,
     * using the raw function directly — the class under test only needs to
     * prove it can complete the server side of a real handshake.
     *
     * @param resource $client
     */
    private function pumpClient($client): bool
    {
        $result = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        return $result === true;
    }
}
