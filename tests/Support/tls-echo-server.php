<?php

declare(strict_types=1);

/**
 * Child process for TlsServerEndToEndTest: a real TlsServer with an echo handler.
 *
 * Usage: php tls-echo-server.php <port> <cert> <key> <logfile> [<autoload>]
 *
 * <autoload> is the parent test run's Composer autoloader, so the child resolves
 * the same package versions (in the monorepo: the root vendor with the local
 * ez-php/websocket, not this module's published copy).
 *
 * Every lifecycle callback appends one line to <logfile> so the parent test can
 * assert what the server saw ("open", "message:<text>", "close", "error:<class>").
 */

use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebsocketTls\TlsServer;

$args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values($_SERVER['argv']) : [];
[, $port, $cert, $key, $log, $parentAutoload] = $args + [null, null, null, null, null, null];

$candidates = [dirname(__DIR__, 2) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/vendor/autoload.php'];

if (is_string($parentAutoload)) {
    array_unshift($candidates, $parentAutoload);
}

foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

if (!is_string($port) || !is_string($cert) || !is_string($key) || !is_string($log)) {
    fwrite(STDERR, "usage: tls-echo-server.php <port> <cert> <key> <logfile>\n");
    exit(2);
}

$record = static function (string $line) use ($log): void {
    file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
};

$handler = new class ($record) implements HandlerInterface {
    /**
     * @param \Closure(string): void $record
     */
    public function __construct(private readonly \Closure $record)
    {
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        ($this->record)('open');
    }

    public function onMessage(ConnectionInterface $conn, Frame $frame): void
    {
        ($this->record)('message:' . $frame->payload);
        $conn->send('echo:' . $frame->payload);
    }

    public function onClose(ConnectionInterface $conn): void
    {
        ($this->record)('close');
    }

    public function onError(ConnectionInterface $conn, \Throwable $e): void
    {
        ($this->record)('error:' . $e::class);
    }
};

(new TlsServer('127.0.0.1', (int) $port, $cert, $key))->run($handler);
