<?php

declare(strict_types=1);

namespace Llmor\Cli\Relay;

/**
 * A subscription to one conversation's live event stream.
 *
 * The server publishes every `ConversationRelayMessage` to Redis under the conversation
 * token; the relay service re-emits them as Socket.IO `message` events into a room named
 * after that same token. So the whole protocol is: connect, `join <token>`, then read
 * `message` events whose single argument is a JSON *string*.
 *
 * Everything here is best-effort. The relay only ever carries a preview of a turn whose
 * authoritative result comes back over HTTP, so a relay that will not connect is a reason
 * to render without live output — never a reason to fail the turn.
 */
final class ConversationRelay
{
    private bool $joined = false;

    public function __construct(
        private readonly SocketIoClient $client,
        private readonly string $conversationToken,
    ) {
    }

    /**
     * Connect and subscribe, or throw if either step fails.
     *
     * @throws RelayException
     */
    public static function connect(RelayEndpoint $endpoint, string $conversationToken, float $timeout = 5.0): self
    {
        $relay = new self(SocketIoClient::forEndpoint($endpoint, $timeout), $conversationToken);
        $relay->join();

        return $relay;
    }

    /**
     * @throws RelayException
     */
    public function join(): void
    {
        if ($this->joined) {
            return;
        }

        if (!$this->client->isConnected()) {
            $this->client->connect();
        }

        $this->client->emit('join', $this->conversationToken);
        $this->joined = true;
    }

    public function isConnected(): bool
    {
        return $this->joined && $this->client->isConnected();
    }

    /**
     * Whatever the server has published since the last call.
     *
     * @return list<RelayEvent>
     */
    public function poll(float $timeout = 0.0): array
    {
        $events = [];

        foreach ($this->client->poll($timeout) as $event) {
            if ('message' !== $event['name']) {
                continue;
            }

            $decoded = $this->decode($event['arguments'][0] ?? null);
            if (null !== $decoded) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * Keep polling until $seconds have passed with nothing new.
     *
     * Used after the HTTP turn returns: the last few relay messages can still be in
     * flight, since they travel a different path (Redis → relay → socket) than the
     * response that overtook them.
     *
     * $stopOn names an event type that ends the wait the moment it arrives, so a caller
     * that knows which event is terminal does not pay the full quiet period for it.
     *
     * @return list<RelayEvent>
     */
    public function drain(float $seconds = 0.25, ?string $stopOn = null): array
    {
        $events = [];
        $deadline = \microtime(true) + $seconds;

        while (\microtime(true) < $deadline && $this->isConnected()) {
            $batch = $this->poll(0.05);
            if ([] === $batch) {
                continue;
            }

            foreach ($batch as $event) {
                $events[] = $event;
                if (null !== $stopOn && $stopOn === $event->type) {
                    return $events;
                }
            }
            $deadline = \microtime(true) + $seconds;
        }

        return $events;
    }

    /**
     * Ask the server to abort the running completion.
     *
     * The relay sets a status flag the runtime checks at the top of each function-call
     * iteration — so this stops the turn between iterations, not mid-token.
     */
    public function interrupt(): void
    {
        if (!$this->isConnected()) {
            return;
        }

        try {
            $this->client->emit('interrupt');
        } catch (RelayException) {
            // the user asked to stop; a dead socket means it is stopping anyway
        }
    }

    public function close(): void
    {
        $this->joined = false;
        $this->client->close();
    }

    private function decode(mixed $payload): ?RelayEvent
    {
        // the relay forwards PHP's JSON verbatim as a string, but tolerate an already
        // decoded object in case a future relay version stops re-encoding it
        if (\is_string($payload)) {
            $payload = \json_decode($payload, true);
        }

        if (!\is_array($payload) || !\is_string($payload['type'] ?? null)) {
            return null;
        }

        $data = $payload['data'] ?? [];

        /** @var array<string, mixed> $data */
        $data = \is_array($data) ? $data : [];

        return new RelayEvent($payload['type'], $data);
    }
}
