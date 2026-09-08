<?php

declare(strict_types=1);

namespace Llmor\Cli\Relay;

/**
 * A WebSocket client over a plain PHP stream.
 *
 * Deliberately dependency-free: `stream_socket_client()` plus `ext-openssl` for `tls://`
 * covers it, and both are already in the static binary's extension set. Pulling in a
 * socket library for one long-lived connection is not worth the build surface.
 *
 * The read side never blocks indefinitely. Every read is bounded by a timeout, because a
 * framing bug or a stalled relay would otherwise hang the CLI mid-turn with no output —
 * far worse than losing the live stream and falling back to the buffered result.
 */
final class WebSocketConnection
{
    /** @var resource|null */
    private $socket;

    /** Bytes read but not yet consumed by a complete frame. */
    private string $buffer = '';

    /** Payload accumulated across a fragmented message, with its original opcode. */
    private string $fragment = '';
    private ?int $fragmentOpcode = null;

    public function __construct(
        private readonly RelayEndpoint $endpoint,
        private readonly float $connectTimeout = 5.0,
    ) {
    }

    /**
     * Adopt a transport that is already connected, and upgrade it.
     *
     * The relay is always reached with {@see open()}; this exists so the framing and
     * handshake can be exercised over a `stream_socket_pair()` with no network, which is
     * the only way to test them at all.
     *
     * @param resource $stream
     */
    public static function overStream($stream, RelayEndpoint $endpoint, float $timeout = 5.0): self
    {
        $connection = new self($endpoint, $timeout);
        $connection->socket = $stream;
        \stream_set_blocking($stream, false);

        return $connection;
    }

    /**
     * Connect and perform the opening handshake.
     *
     * @throws RelayException when the socket cannot be opened or the server declines to upgrade
     */
    public function open(): void
    {
        $errorCode = 0;
        $errorMessage = '';

        $socket = @\stream_socket_client(
            $this->endpoint->socketAddress(),
            $errorCode,
            $errorMessage,
            $this->connectTimeout,
            \STREAM_CLIENT_CONNECT,
            \stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $this->endpoint->host]]),
        );

        if (false === $socket) {
            throw new RelayException(\sprintf('Could not reach the relay at %s: %s', $this->endpoint, '' !== $errorMessage ? $errorMessage : 'connection failed'));
        }

        $this->socket = $socket;
        \stream_set_blocking($socket, false);

        $this->upgrade();
    }

    /**
     * Send the upgrade request over an open transport and verify the answer.
     *
     * @throws RelayException when the server does not switch protocols
     */
    public function upgrade(): void
    {
        $this->awaitUpgrade($this->sendUpgrade());
    }

    /**
     * Write the upgrade request, returning the nonce its answer must be derived from.
     *
     * An upgrade is a request and a response, and they are separable: this lets a caller
     * that drives both ends of the connection — a test over a socket pair — get the
     * request out before it starts waiting for the reply.
     */
    public function sendUpgrade(): string
    {
        $key = WebSocketHandshake::key();
        $this->write(WebSocketHandshake::request($this->endpoint, $key));

        return $key;
    }

    public function isOpen(): bool
    {
        return null !== $this->socket && !\feof($this->socket);
    }

    public function sendText(string $payload): void
    {
        $this->write(WebSocketFrame::encode(WebSocketFrame::OP_TEXT, $payload));
    }

    /**
     * Read whatever complete text messages have arrived, waiting at most $timeout seconds
     * for the first byte.
     *
     * Control frames are handled here and never surface: a ping is ponged, a close ends
     * the connection. Fragmented messages are reassembled, so a caller only ever sees
     * whole payloads.
     *
     * @return list<string>
     *
     * @throws RelayException on a protocol violation
     */
    public function receive(float $timeout = 0.0): array
    {
        $messages = [];

        if (!$this->isOpen()) {
            return $messages;
        }

        $this->fill($timeout);

        // decoded frames are walked with an offset and the buffer compacted once at the
        // end: a single read can hold hundreds of small relay frames, and re-slicing the
        // remainder after each one would copy the whole buffer that many times
        $consumedTotal = 0;

        while (null !== ($decoded = WebSocketFrame::decode($this->buffer, $consumedTotal))) {
            [$frame, $consumed] = $decoded;
            $consumedTotal += $consumed;

            // control frames may be injected between the fragments of a data message,
            // so they are handled without touching the fragment buffer
            if ($frame->isControl()) {
                $this->handleControlFrame($frame);

                if (!$this->isOpen()) {
                    break;
                }

                continue;
            }

            if (WebSocketFrame::OP_CONTINUATION === $frame->opcode) {
                if (null === $this->fragmentOpcode) {
                    throw new RelayException('The relay sent a continuation frame with nothing to continue.');
                }
                $this->fragment .= $frame->payload;
            } else {
                $this->fragment = $frame->payload;
                $this->fragmentOpcode = $frame->opcode;
            }

            if (!$frame->fin) {
                continue;
            }

            if (WebSocketFrame::OP_TEXT === $this->fragmentOpcode) {
                $messages[] = $this->fragment;
            }

            $this->fragment = '';
            $this->fragmentOpcode = null;
        }

        if ($consumedTotal > 0) {
            $this->buffer = \substr($this->buffer, $consumedTotal);
        }

        return $messages;
    }

    public function close(): void
    {
        if (null === $this->socket) {
            return;
        }

        // a close frame is a courtesy — write it straight to the socket rather than
        // through write(), which would raise on a peer that has already hung up
        if (!\feof($this->socket)) {
            @\fwrite($this->socket, WebSocketFrame::encode(WebSocketFrame::OP_CLOSE, \pack('n', 1000)));
        }

        @\fclose($this->socket);
        $this->socket = null;
    }

    /**
     * Wait for readable data, then drain everything currently buffered by the kernel.
     */
    private function fill(float $timeout): void
    {
        if (null === $this->socket) {
            return;
        }

        $read = [$this->socket];
        $write = null;
        $except = null;

        $seconds = (int) $timeout;
        $microseconds = (int) \round(($timeout - $seconds) * 1_000_000);

        // the count of ready streams, not the by-reference array, is the reliable signal:
        // 0 means the wait timed out with nothing to read
        $ready = @\stream_select($read, $write, $except, $seconds, $microseconds);
        if (false === $ready || 0 === $ready) {
            return;
        }

        while (true) {
            $chunk = @\fread($this->socket, 65536);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $this->buffer .= $chunk;
            if (\strlen($chunk) < 65536) {
                break;
            }
        }
    }

    private function handleControlFrame(WebSocketFrame $frame): void
    {
        match ($frame->opcode) {
            WebSocketFrame::OP_PING => $this->write(WebSocketFrame::encode(WebSocketFrame::OP_PONG, $frame->payload)),
            WebSocketFrame::OP_CLOSE => $this->close(),
            default => null,
        };
    }

    /**
     * Read the HTTP response to the upgrade request and verify it.
     *
     * Public because an upgrade is separable: {@see sendUpgrade()} gets the request out,
     * this waits for the reply.
     *
     * @throws RelayException when the server does not switch protocols
     */
    public function awaitUpgrade(string $key): void
    {
        $deadline = \microtime(true) + $this->connectTimeout;
        $response = '';

        while (!\str_contains($response, "\r\n\r\n")) {
            if (\microtime(true) > $deadline) {
                throw new RelayException(\sprintf('The relay at %s did not answer the WebSocket handshake in time.', $this->endpoint));
            }

            $this->fill(0.1);
            $response .= $this->buffer;
            $this->buffer = '';

            if (null === $this->socket || (\feof($this->socket) && !\str_contains($response, "\r\n\r\n"))) {
                throw new RelayException(\sprintf('The relay at %s closed the connection during the handshake.', $this->endpoint));
            }
        }

        // anything past the blank line is already WebSocket traffic — Socket.IO's open
        // packet usually arrives in the same read as the 101, so it must not be dropped
        $split = \strpos($response, "\r\n\r\n");
        \assert(false !== $split);
        $this->buffer = \substr($response, $split + 4).$this->buffer;

        WebSocketHandshake::verify(\substr($response, 0, $split), $key, $this->endpoint);
    }

    /**
     * @throws RelayException when the socket is gone or refuses the whole payload
     */
    private function write(string $bytes): void
    {
        if (null === $this->socket) {
            throw new RelayException('The relay connection is not open.');
        }

        $deadline = \microtime(true) + $this->connectTimeout;

        while ('' !== $bytes) {
            $written = @\fwrite($this->socket, $bytes);

            if (false === $written) {
                throw new RelayException('Lost the relay connection while writing.');
            }

            if (0 === $written) {
                if (\microtime(true) > $deadline) {
                    throw new RelayException('Timed out writing to the relay.');
                }
                // a non-blocking socket with a full send buffer — wait for room
                $read = null;
                $write = [$this->socket];
                $except = null;
                @\stream_select($read, $write, $except, 0, 50_000);

                continue;
            }

            $bytes = \substr($bytes, $written);
        }
    }
}
