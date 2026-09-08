<?php

declare(strict_types=1);

namespace Llmor\Cli\Relay;

use RuntimeException;

/**
 * Anything that goes wrong on the conversation relay: a socket that will not open,
 * a handshake the server rejects, a frame we cannot make sense of.
 *
 * The relay is a *nicety* — it carries the live token stream, but the authoritative
 * result always arrives over HTTP — so callers are expected to catch this and fall
 * back to buffered rendering rather than failing the turn.
 */
final class RelayException extends RuntimeException
{
}
