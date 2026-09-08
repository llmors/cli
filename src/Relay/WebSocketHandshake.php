<?php

declare(strict_types=1);

namespace Llmor\Cli\Relay;

/**
 * The opening handshake, as pure string work.
 *
 * Kept apart from the socket because this is the part with a *rule* to get right — the
 * `Sec-WebSocket-Accept` derivation proves the peer actually speaks WebSocket rather
 * than being some proxy that echoed a 101 — and a rule is worth testing without a
 * network in the way.
 */
final class WebSocketHandshake
{
    /** RFC 6455 §1.3. A fixed GUID, not a secret. */
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public static function key(): string
    {
        return \base64_encode(\random_bytes(16));
    }

    /**
     * The HTTP/1.1 upgrade request, terminated by the blank line.
     */
    public static function request(RelayEndpoint $endpoint, string $key): string
    {
        return \implode("\r\n", [
            \sprintf('GET %s HTTP/1.1', $endpoint->requestUri()),
            \sprintf('Host: %s', $endpoint->hostHeader()),
            'Upgrade: websocket',
            'Connection: Upgrade',
            \sprintf('Sec-WebSocket-Key: %s', $key),
            'Sec-WebSocket-Version: 13',
            'User-Agent: llmor-cli',
            '', '',
        ]);
    }

    /**
     * The `Sec-WebSocket-Accept` value a conforming server must answer $key with.
     */
    public static function accept(string $key): string
    {
        return \base64_encode(\sha1($key.self::GUID, true));
    }

    /**
     * @throws RelayException when the response is not a valid protocol switch
     */
    public static function verify(string $headers, string $key, RelayEndpoint $endpoint): void
    {
        if (1 !== \preg_match('#^HTTP/1\.[01] 101#i', $headers)) {
            $status = \strtok($headers, "\r\n");

            throw new RelayException(\sprintf('The relay at %s refused the WebSocket upgrade: %s', $endpoint, false === $status ? 'no status line' : $status));
        }

        if (1 !== \preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $headers, $matches)
            || !\hash_equals(self::accept($key), $matches[1])) {
            throw new RelayException(\sprintf('The relay at %s answered with an invalid Sec-WebSocket-Accept.', $endpoint));
        }
    }
}
