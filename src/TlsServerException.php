<?php

declare(strict_types=1);

namespace EzPhp\WebsocketTls;

use RuntimeException;

/**
 * Thrown when the TLS listener cannot be created or a TLS handshake fails
 * in a way the caller needs to know about.
 *
 * @package EzPhp\WebsocketTls
 */
final class TlsServerException extends RuntimeException
{
}
