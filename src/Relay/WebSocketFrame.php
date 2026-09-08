<?php

declare(strict_types=1);

namespace Llmor\Cli\Relay;

/**
 * RFC 6455 frame codec — the byte-level half of the WebSocket transport, kept apart
 * from the socket so it can be tested against fixed byte strings.
 *
 * Only what the relay actually needs: text and binary data frames, the three control
 * opcodes, and the 7 / 7+16 / 7+64 payload-length forms. Extensions (RSV bits,
 * permessage-deflate) are not negotiated in {@see WebSocketConnection::open()}, so a
 * frame that sets them is a protocol error rather than something to decompress.
 */
final class WebSocketFrame
{
    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    public function __construct(
        public readonly int $opcode,
        public readonly string $payload,
        public readonly bool $fin = true,
    ) {
    }

    public function isControl(): bool
    {
        return 0 !== ($this->opcode & 0x8);
    }

    /**
     * Encode a frame as a client sends it.
     *
     * Client-to-server frames MUST be masked (RFC 6455 §5.1) — a server is required to
     * close the connection on an unmasked one, so the mask is not optional here. It is
     * injectable purely so tests can assert exact bytes.
     */
    public static function encode(int $opcode, string $payload, bool $fin = true, ?string $mask = null): string
    {
        $mask ??= \random_bytes(4);
        $length = \strlen($payload);

        $header = \chr(($fin ? 0x80 : 0x00) | ($opcode & 0x0F));

        // the mask bit is always set; the length form depends on the payload size
        if ($length < 126) {
            $header .= \chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $header .= \chr(0x80 | 126).\pack('n', $length);
        } else {
            $header .= \chr(0x80 | 127).\pack('J', $length);
        }

        return $header.$mask.(0 === $length ? '' : ($payload ^ \str_pad('', $length, $mask)));
    }

    /**
     * Decode one frame from $buffer, starting at $from.
     *
     * Returns the frame and how many bytes it consumed, or null when the buffer does not
     * yet hold a whole frame — the caller reads more and tries again. This is the only
     * sane contract for a stream, where a frame arrives in as many pieces as the network
     * feels like.
     *
     * $from lets a reader walk several frames out of one buffer without re-slicing it
     * after each one, which is what keeps a burst of small frames linear instead of
     * quadratic. The consumed count stays relative to $from, so callers that pass no
     * offset are unaffected.
     *
     * @return array{0: self, 1: int}|null
     *
     * @throws RelayException on a frame we did not negotiate support for
     */
    public static function decode(string $buffer, int $from = 0): ?array
    {
        $end = \strlen($buffer);
        if ($end - $from < 2) {
            return null;
        }

        $first = \ord($buffer[$from]);
        $second = \ord($buffer[$from + 1]);

        if (0 !== ($first & 0x70)) {
            throw new RelayException('The relay sent a frame using an extension we did not negotiate.');
        }

        $fin = 0 !== ($first & 0x80);
        $opcode = $first & 0x0F;
        $masked = 0 !== ($second & 0x80);
        $length = $second & 0x7F;
        $offset = $from + 2;

        if (126 === $length) {
            if ($end < $offset + 2) {
                return null;
            }
            /** @var array{1: int} $unpacked */
            $unpacked = \unpack('n', $buffer, $offset);
            $length = $unpacked[1];
            $offset += 2;
        } elseif (127 === $length) {
            if ($end < $offset + 8) {
                return null;
            }
            /** @var array{1: int} $unpacked */
            $unpacked = \unpack('J', $buffer, $offset);
            $length = $unpacked[1];
            $offset += 8;
            // the high bit must be clear per §5.2, and a negative value here would mean
            // a length that overflowed PHP's signed int — either way we cannot honour it
            if ($length < 0) {
                throw new RelayException('The relay announced a frame larger than this build can address.');
            }
        }

        $mask = '';
        if ($masked) {
            if ($end < $offset + 4) {
                return null;
            }
            $mask = \substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($end < $offset + $length) {
            return null;
        }

        $payload = \substr($buffer, $offset, $length);
        if ($masked && '' !== $payload) {
            $payload ^= \str_pad('', $length, $mask);
        }

        return [new self($opcode, $payload, $fin), $offset + $length - $from];
    }

    /**
     * The two-byte big-endian status code a close frame opens with, when it carries one.
     */
    public function closeCode(): ?int
    {
        if (self::OP_CLOSE !== $this->opcode || \strlen($this->payload) < 2) {
            return null;
        }

        /** @var array{1: int} $unpacked */
        $unpacked = \unpack('n', $this->payload);

        return $unpacked[1];
    }
}
