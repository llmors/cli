<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Support;

use Llmor\Cli\Relay\RelayEndpoint;
use Llmor\Cli\Relay\WebSocketConnection;
use Llmor\Cli\Relay\WebSocketFrame;
use Llmor\Cli\Relay\WebSocketHandshake;
use RuntimeException;

/**
 * The server half of a real, in-process WebSocket connection.
 *
 * `stream_socket_pair()` gives two genuinely connected sockets, so the client under test
 * does actual non-blocking reads and writes against actual bytes — the framing, the
 * buffering and the partial-read handling are all exercised for real. Only the TCP
 * connect and TLS are skipped, and neither is ours to get wrong.
 *
 * Both ends live in one process, so nothing here may block: the test drives the exchange
 * a step at a time.
 */
final class FakeRelayServer
{
    /** @var resource */
    private $serverEnd;

    /** Bytes written but not yet accepted by the socket. */
    private string $outbox = '';

    public readonly WebSocketConnection $client;
    public readonly RelayEndpoint $endpoint;

    public function __construct()
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        if (false === $pair) {
            throw new RuntimeException('Could not create a socket pair.');
        }

        [$this->serverEnd, $clientEnd] = $pair;
        \stream_set_blocking($this->serverEnd, false);

        $this->endpoint = RelayEndpoint::fromUrl('http://relay.test:2053', '/stream');
        $this->client = WebSocketConnection::overStream($clientEnd, $this->endpoint, 0.5);
    }

    /**
     * Drive a full, successful handshake and return the client's request.
     *
     * Both ends are in one process, so the exchange runs in explicit order: the client
     * writes its request, we answer it, then the client reads. The accept value is
     * derived from the key the client actually sent, so this checks that derivation
     * rather than assuming it.
     */
    public function handshake(?string $accept = null): string
    {
        $key = $this->client->sendUpgrade();
        $request = $this->acceptUpgrade($accept);
        $this->client->awaitUpgrade($key);

        return $request;
    }

    /**
     * Answer a handshake request the client has already written.
     */
    public function acceptUpgrade(?string $accept = null): string
    {
        $request = $this->readRaw();

        \preg_match('/^Sec-WebSocket-Key:\s*(\S+)/mi', $request, $matches);
        $key = $matches[1] ?? '';

        $this->writeRaw(\implode("\r\n", [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            \sprintf('Sec-WebSocket-Accept: %s', $accept ?? WebSocketHandshake::accept($key)),
            '', '',
        ]));

        return $request;
    }

    /**
     * Refuse the upgrade, the way a misconfigured proxy or a wrong path would.
     */
    public function rejectUpgrade(string $status = 'HTTP/1.1 404 Not Found'): void
    {
        $this->readRaw();
        $this->writeRaw($status."\r\nContent-Length: 0\r\n\r\n");
    }

    /**
     * Send one text frame, unmasked — the direction rule for a server.
     */
    public function send(string $payload): void
    {
        $this->writeRaw(self::serverFrame(WebSocketFrame::OP_TEXT, $payload));
    }

    /**
     * Send a payload split across a data frame and a continuation frame.
     */
    public function sendFragmented(string $first, string $second): void
    {
        $this->writeRaw(self::serverFrame(WebSocketFrame::OP_TEXT, $first, false));
        $this->writeRaw(self::serverFrame(WebSocketFrame::OP_CONTINUATION, $second));
    }

    public function sendPing(string $payload = ''): void
    {
        $this->writeRaw(self::serverFrame(WebSocketFrame::OP_PING, $payload));
    }

    public function sendClose(): void
    {
        $this->writeRaw(self::serverFrame(WebSocketFrame::OP_CLOSE, \pack('n', 1000)));
    }

    /**
     * Send the Engine.IO open packet, then the Socket.IO connect acknowledgement once
     * the client has asked for it — the sequence a real relay produces.
     */
    public function completeSocketIoHandshake(): void
    {
        $this->send('0{"sid":"abc","upgrades":[],"pingInterval":25000,"pingTimeout":20000}');
        $this->send('40{"sid":"xyz"}');
    }

    /**
     * A relay `message` event carrying a ConversationRelayMessage, encoded the way the
     * relay does it: the JSON travels as a *string* argument, not as an object.
     *
     * @param array<string, mixed> $data
     */
    public function sendRelayMessage(string $type, array $data = []): void
    {
        $message = (string) \json_encode(['type' => $type, 'data' => $data]);
        $this->send('42'.(string) \json_encode(['message', $message]));
    }

    /**
     * Every complete text payload the client has sent, unmasked.
     *
     * @return list<string>
     */
    public function received(): array
    {
        $buffer = $this->readRaw();
        $messages = [];

        while (null !== ($decoded = WebSocketFrame::decode($buffer))) {
            [$frame, $consumed] = $decoded;
            $buffer = \substr($buffer, $consumed);

            if (WebSocketFrame::OP_TEXT === $frame->opcode) {
                $messages[] = $frame->payload;
            }
        }

        return $messages;
    }

    /**
     * The opcode of the last control frame the client sent — a pong, normally, which
     * {@see received()} deliberately filters out.
     */
    public function lastControlOpcodeSent(): ?int
    {
        $buffer = $this->readRaw();
        $opcode = null;

        while (null !== ($decoded = WebSocketFrame::decode($buffer))) {
            [$frame, $consumed] = $decoded;
            $buffer = \substr($buffer, $consumed);
            if ($frame->isControl()) {
                $opcode = $frame->opcode;
            }
        }

        return $opcode;
    }

    public function close(): void
    {
        @\fclose($this->serverEnd);
    }

    /**
     * A server-to-client frame: never masked (RFC 6455 §5.1).
     */
    private static function serverFrame(int $opcode, string $payload, bool $fin = true): string
    {
        $length = \strlen($payload);
        $header = \chr(($fin ? 0x80 : 0x00) | $opcode);

        if ($length < 126) {
            $header .= \chr($length);
        } elseif ($length <= 0xFFFF) {
            $header .= \chr(126).\pack('n', $length);
        } else {
            $header .= \chr(127).\pack('J', $length);
        }

        return $header.$payload;
    }

    private function readRaw(): string
    {
        $buffer = '';

        while (true) {
            $chunk = @\fread($this->serverEnd, 65536);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * Push out as much of the outbox as the socket will take right now.
     *
     * A payload larger than the kernel's socket buffer cannot be written in one go, and
     * with both ends in one process nobody is draining the other side — so writes are
     * queued and the test alternates {@see flush()} with client reads. That is not a
     * test artefact: it is exactly the partial-write/partial-read path a long streamed
     * answer takes on a real connection.
     */
    public function flush(): void
    {
        while ('' !== $this->outbox) {
            $written = @\fwrite($this->serverEnd, $this->outbox);
            if (false === $written || 0 === $written) {
                return;
            }
            $this->outbox = \substr($this->outbox, $written);
        }
    }

    private function writeRaw(string $bytes): void
    {
        $this->outbox .= $bytes;
        $this->flush();
    }
}
