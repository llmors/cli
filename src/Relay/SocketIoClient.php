<?php

declare(strict_types=1);

namespace Llmor\Cli\Relay;

use JsonException;

/**
 * The Engine.IO + Socket.IO protocol on top of a raw WebSocket.
 *
 * Two layers share one wire, both encoded as a leading digit on a text frame:
 *
 *   Engine.IO   0 open · 1 close · 2 ping · 3 pong · 4 message
 *   Socket.IO   0 connect · 1 disconnect · 2 event · 4 error   (inside an Engine.IO 4)
 *
 * So a relay event arrives as the text frame `42["message","{\"type\":…}"]`: Engine.IO
 * `4` (message), Socket.IO `2` (event), then a JSON array of `[event, ...args]`.
 *
 * Only the default namespace is used, and binary attachments (Socket.IO types 5/6) are
 * not something the relay emits, so neither is implemented.
 */
final class SocketIoClient
{
    private bool $opened = false;
    private bool $connected = false;

    public function __construct(
        private readonly WebSocketConnection $socket,
        private readonly float $connectTimeout = 5.0,
    ) {
    }

    public static function forEndpoint(RelayEndpoint $endpoint, float $timeout = 5.0): self
    {
        return new self(new WebSocketConnection($endpoint, $timeout), $timeout);
    }

    /**
     * Open the socket and complete both handshakes.
     *
     * @throws RelayException when the server never confirms the namespace connect
     */
    public function connect(): void
    {
        // a connection handed in already upgraded (a reconnect, or a test over a socket
        // pair) skips straight to the protocol handshake
        if (!$this->socket->isOpen()) {
            $this->socket->open();
        }

        // two acknowledgements in sequence: the server's Engine.IO `0{sid,…}` open, then
        // — once we send Socket.IO CONNECT — its `40` for the default namespace. Sending
        // `40` before the open packet is a protocol violation some servers drop silently,
        // which is indistinguishable from a hang, so wait for it.
        $this->await(fn (): bool => $this->opened, 'The relay never sent its Engine.IO open packet.');
        $this->socket->sendText('40');
        $this->await(fn (): bool => $this->connected, 'The relay never acknowledged the Socket.IO connection.');
    }

    /**
     * Pump the socket until $done holds, or give up.
     *
     * @param callable(): bool $done
     *
     * @throws RelayException on timeout or an early close
     */
    private function await(callable $done, string $message): void
    {
        $deadline = \microtime(true) + $this->connectTimeout;

        while (!$done()) {
            if (\microtime(true) > $deadline) {
                throw new RelayException($message);
            }

            // no events can precede the handshake, so anything read here is discarded
            $this->read(0.1);

            if (!$this->socket->isOpen()) {
                throw new RelayException('The relay closed the connection during the handshake.');
            }
        }
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket->isOpen();
    }

    /**
     * Emit an event to the server: `42["name",arg,…]`.
     *
     * @throws RelayException when the payload cannot be encoded or the socket is gone
     */
    public function emit(string $event, mixed ...$arguments): void
    {
        try {
            $payload = \json_encode([$event, ...$arguments], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RelayException(\sprintf('Could not encode the "%s" relay event: %s', $event, $e->getMessage()), 0, $e);
        }

        $this->socket->sendText('42'.$payload);
    }

    /**
     * Whatever events have arrived, waiting at most $timeout seconds for the first.
     *
     * @return list<array{name: string, arguments: list<mixed>}>
     */
    public function poll(float $timeout = 0.0): array
    {
        return $this->read($timeout);
    }

    public function close(): void
    {
        if ($this->socket->isOpen()) {
            // Engine.IO close, so the relay releases its Redis subscription promptly
            // rather than waiting for the socket to time out
            $this->socket->sendText('41');
        }

        $this->connected = false;
        $this->socket->close();
    }

    /**
     * @return list<array{name: string, arguments: list<mixed>}>
     */
    private function read(float $timeout): array
    {
        $events = [];

        foreach ($this->socket->receive($timeout) as $packet) {
            $event = $this->handlePacket($packet);
            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return array{name: string, arguments: list<mixed>}|null
     */
    private function handlePacket(string $packet): ?array
    {
        if ('' === $packet) {
            return null;
        }

        return match ($packet[0]) {
            '0' => $this->markOpened(),
            // a ping must be ponged or the server drops us mid-turn, which would look
            // exactly like the model going quiet
            '2' => $this->pong(),
            '4' => $this->handleMessage(\substr($packet, 1)),
            default => null,
        };
    }

    private function markOpened(): null
    {
        $this->opened = true;

        return null;
    }

    private function pong(): null
    {
        $this->socket->sendText('3');

        return null;
    }

    /**
     * @return array{name: string, arguments: list<mixed>}|null
     */
    private function handleMessage(string $packet): ?array
    {
        if ('' === $packet) {
            return null;
        }

        if ('0' === $packet[0]) {
            $this->connected = true;

            return null;
        }

        if ('4' === $packet[0]) {
            throw new RelayException(\sprintf('The relay rejected the connection: %s', \substr($packet, 1)));
        }

        if ('2' !== $packet[0]) {
            return null;
        }

        try {
            $decoded = \json_decode(\substr($packet, 1), true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // a malformed event is not worth ending the turn over — the authoritative
            // result still arrives over HTTP
            return null;
        }

        if (!\is_array($decoded) || !\array_is_list($decoded) || [] === $decoded || !\is_string($decoded[0])) {
            return null;
        }

        /** @var list<mixed> $decoded */
        $name = $decoded[0];
        \assert(\is_string($name));

        return ['name' => $name, 'arguments' => \array_values(\array_slice($decoded, 1))];
    }
}
