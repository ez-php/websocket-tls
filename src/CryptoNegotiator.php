<?php

declare(strict_types=1);

namespace EzPhp\WebsocketTls;

/**
 * Drives `stream_socket_enable_crypto()` to completion.
 *
 * The function is non-blocking-aware: on a socket in non-blocking mode it
 * returns `0` (not `false`) while it needs more bytes from the peer before
 * the handshake can proceed. This class retries it, waiting for the socket
 * to become readable between attempts, until it settles on `true`/`false`
 * or a timeout elapses.
 *
 * @package EzPhp\WebsocketTls
 */
final class CryptoNegotiator
{
    /**
     * Longest single wait between retries, in seconds. Keeps the caller's
     * event loop responsive even while a handshake is pending.
     */
    private const MAX_POLL_INTERVAL = 0.05;

    /**
     * Repeatedly calls `stream_socket_enable_crypto()` on `$socket` until the
     * handshake completes, fails, or `$timeoutSeconds` elapses.
     *
     * @param resource $socket          Stream socket, in non-blocking mode
     * @param int      $cryptoMethod    One of the `STREAM_CRYPTO_METHOD_*` constants
     * @param float    $timeoutSeconds  Maximum time to spend negotiating
     */
    public function negotiate($socket, int $cryptoMethod, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            // A failed handshake is reported as a warning as well as `false`; the return value is what matters.
            [$result] = $this->callCapturingWarning(
                static fn () => stream_socket_enable_crypto($socket, true, $cryptoMethod)
            );

            if ($result === true || $result === false) {
                return $result;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0.0) {
                return false;
            }

            $this->waitForData($socket, min($remaining, self::MAX_POLL_INTERVAL));
        }
    }

    /**
     * Blocks until `$socket` is readable or `$waitSeconds` elapses, whichever
     * comes first — a bounded pause between negotiation retries.
     *
     * @param resource $socket
     */
    private function waitForData($socket, float $waitSeconds): void
    {
        $read = [$socket];
        $write = null;
        $except = null;

        stream_select($read, $write, $except, 0, (int) ($waitSeconds * 1_000_000));
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
