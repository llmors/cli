<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Relay;

use Llmor\Cli\Relay\RelayException;
use Llmor\Cli\Relay\WebSocketFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketFrame::class)]
final class WebSocketFrameTest extends TestCase
{
    public function testEncodesAShortTextFrameWithTheMaskBitSet(): void
    {
        $frame = WebSocketFrame::encode(WebSocketFrame::OP_TEXT, 'hi', true, 'MASK');

        // FIN + opcode 1, then mask bit + length 2, then the mask, then masked payload
        self::assertSame("\x81", $frame[0]);
        self::assertSame(0x82, \ord($frame[1]));
        self::assertSame('MASK', \substr($frame, 2, 4));
        self::assertSame('hi' ^ 'MA', \substr($frame, 6));
    }

    public function testUsesTheTwoByteLengthFormForMediumPayloads(): void
    {
        $frame = WebSocketFrame::encode(WebSocketFrame::OP_TEXT, \str_repeat('x', 200), true, 'MASK');

        self::assertSame(0x80 | 126, \ord($frame[1]));
        self::assertSame(200, self::readLength('n', $frame));
        self::assertSame(2 + 2 + 4 + 200, \strlen($frame));
    }

    public function testUsesTheEightByteLengthFormForLargePayloads(): void
    {
        $frame = WebSocketFrame::encode(WebSocketFrame::OP_TEXT, \str_repeat('x', 70000), true, 'MASK');

        self::assertSame(0x80 | 127, \ord($frame[1]));
        self::assertSame(70000, self::readLength('J', $frame));
        self::assertSame(2 + 8 + 4 + 70000, \strlen($frame));
    }

    public function testEncodesAnEmptyPayloadWithoutTrailingBytes(): void
    {
        $frame = WebSocketFrame::encode(WebSocketFrame::OP_PONG, '', true, 'MASK');

        self::assertSame(6, \strlen($frame));
        self::assertSame(0x80, \ord($frame[1]));
    }

    public function testDecodesAnUnmaskedServerFrame(): void
    {
        // servers never mask: FIN + text, length 5, payload
        $decoded = WebSocketFrame::decode("\x81\x05hello");

        self::assertNotNull($decoded);
        [$frame, $consumed] = $decoded;

        self::assertSame(WebSocketFrame::OP_TEXT, $frame->opcode);
        self::assertSame('hello', $frame->payload);
        self::assertTrue($frame->fin);
        self::assertSame(7, $consumed);
    }

    public function testDecodeIsTheInverseOfEncodeIncludingUnmasking(): void
    {
        $payload = \str_repeat('token delta ', 500);
        $decoded = WebSocketFrame::decode(WebSocketFrame::encode(WebSocketFrame::OP_TEXT, $payload));

        self::assertNotNull($decoded);
        self::assertSame($payload, $decoded[0]->payload);
    }

    public function testReturnsNullUntilTheWholeFrameHasArrived(): void
    {
        $complete = "\x81\x05hello";

        // a stream hands over as many bytes as it feels like; every prefix must be
        // reported as "not yet" rather than decoded into a truncated payload
        for ($length = 0; $length < \strlen($complete); ++$length) {
            self::assertNull(WebSocketFrame::decode(\substr($complete, 0, $length)), \sprintf('prefix of %d bytes should be incomplete', $length));
        }

        self::assertNotNull(WebSocketFrame::decode($complete));
    }

    public function testReportsHowManyBytesItConsumedSoTheNextFrameCanFollow(): void
    {
        $buffer = "\x81\x03one\x81\x03two";

        $first = WebSocketFrame::decode($buffer);
        self::assertNotNull($first);
        self::assertSame('one', $first[0]->payload);

        $second = WebSocketFrame::decode(\substr($buffer, $first[1]));
        self::assertNotNull($second);
        self::assertSame('two', $second[0]->payload);
    }

    public function testDecodesAFragmentedMessageAsSeparateFrames(): void
    {
        // first frame: no FIN, opcode text; second: FIN, continuation
        $start = WebSocketFrame::decode("\x01\x03abc");
        $end = WebSocketFrame::decode("\x80\x03def");

        self::assertNotNull($start);
        self::assertNotNull($end);
        self::assertFalse($start[0]->fin);
        self::assertSame(WebSocketFrame::OP_TEXT, $start[0]->opcode);
        self::assertTrue($end[0]->fin);
        self::assertSame(WebSocketFrame::OP_CONTINUATION, $end[0]->opcode);
    }

    public function testRecognisesControlFrames(): void
    {
        self::assertTrue((new WebSocketFrame(WebSocketFrame::OP_PING, ''))->isControl());
        self::assertTrue((new WebSocketFrame(WebSocketFrame::OP_CLOSE, ''))->isControl());
        self::assertFalse((new WebSocketFrame(WebSocketFrame::OP_TEXT, ''))->isControl());
    }

    public function testReadsTheStatusCodeOffACloseFrame(): void
    {
        self::assertSame(1000, (new WebSocketFrame(WebSocketFrame::OP_CLOSE, \pack('n', 1000)))->closeCode());
        self::assertNull((new WebSocketFrame(WebSocketFrame::OP_CLOSE, ''))->closeCode());
        self::assertNull((new WebSocketFrame(WebSocketFrame::OP_TEXT, \pack('n', 1000)))->closeCode());
    }

    public function testRejectsAFrameUsingAnExtensionWeNeverNegotiated(): void
    {
        $this->expectException(RelayException::class);

        // RSV1 set — permessage-deflate, which the handshake does not ask for
        WebSocketFrame::decode("\xC1\x05hello");
    }

    /**
     * The extended payload length, read from just past the two-byte header.
     */
    private static function readLength(string $format, string $frame): int
    {
        $unpacked = \unpack($format, $frame, 2);
        self::assertIsArray($unpacked);

        return (int) $unpacked[1];
    }
}
