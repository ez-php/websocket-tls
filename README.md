# ez-php/websocket-tls

TLS/WSS termination for `ez-php/websocket` — an `ssl://` listener and Fiber event loop that hands negotiated connections to the plain WebSocket server's `Connection`/`HandlerInterface`.

---

## Installation

```bash
composer require ez-php/websocket-tls
```

---

## Usage

```php
use EzPhp\WebsocketTls\TlsServer;

$server = new TlsServer(
    host: '0.0.0.0',
    port: 8443,
    certFile: '/etc/ssl/certs/example.com.pem',
    keyFile: '/etc/ssl/private/example.com.key',
);

$server->run(new MyHandler()); // implements EzPhp\WebSocket\HandlerInterface, blocks until interrupted
```

`MyHandler` is the same `EzPhp\WebSocket\HandlerInterface` implementation you would
write for the plain-TCP `ez-php/websocket` `Server` — nothing about the RFC 6455
handshake, frame codec, or connection lifecycle changes. `TlsServer` only replaces
how the underlying TCP socket is created and terminates the TLS layer before
handing the plaintext stream to `ez-php/websocket`'s `Connection`.

For most deployments, terminating TLS at a reverse proxy (nginx, Caddy) in front
of a plain `ez-php/websocket` `Server` is simpler and still the recommended path.
Reach for this package when a reverse proxy isn't available in front of the
WebSocket process itself.

---

## License

MIT
