<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Relay;

use Llmor\Cli\Relay\RelayException;
use Llmor\Cli\Relay\WebSocketConnection;
use Llmor\Cli\Relay\WebSocketFrame;
use Llmor\Cli\Relay\WebSocketHandshake;
use Llmor\Cli\Tests\Support\FakeRelayServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSocketConnection::class)]
#[CoversClass(WebSocketHandshake::class)]
final class WebSocketConnectionTest extends TestCase
{
    private FakeRelayServer $server;

    protected function setUp(): void
    {
        $this->server = new FakeRelayServer();
    }

    protected function tearDown(): void
    {
        $this->server->close();
    }

    public function testTheUpgradeRequestAsksForTheEngineIoWebSocketTransport(): void
    {
        $request = $this->server->handshake();

        self::assertStringContainsString('GET /stream/?EIO=4&transport=websocket HTTP/1.1', $request);
        self::assertStringContainsString('Host: relay.test:2053', $request);
        self::assertStringContainsString('Upgrade: websocket', $request);
        self::assertStringContainsString('Connection: Upgrade', $request);
        self::assertStringContainsString('Sec-WebSocket-Version: 13', $request);
        self::assertMatchesRegularExpression('/Sec-WebSocket-Key: \S+/', $request);
    }

    public function testKeepsFramesThatArriveInTheSameReadAsTheHandshake(): void
    {
        // a real relay's Engine.IO open packet routinely lands in the same TCP read as
        // the 101; dropping the rest of that buffer would lose it and stall the connect
        $key = $this->server->client->sendUpgrade();
        $this->server->acceptUpgrade();
        $this->server->send('0{"sid":"abc"}');
        $this->server->client->awaitUpgrade($key);

        self::assertSame(['0{"sid":"abc"}'], $this->server->client->receive(0.1));
    }

    public function testRejectsAResponseThatIsNotAProtocolSwitch(): void
    {
        $key = $this->server->client->sendUpgrade();
        $this->server->rejectUpgrade();

        $this->expectException(RelayException::class);
        $this->expectExceptionMessage('refused the WebSocket upgrade');

        $this->server->client->awaitUpgrade($key);
    }

    public function testRejectsAnAcceptValueNotDerivedFromOurKey(): void
    {
        // a 101 alone is not proof the peer speaks WebSocket — the derived accept is
        $key = $this->server->client->sendUpgrade();
        $this->server->acceptUpgrade('not-derived-from-the-key');

        $this->expectException(RelayException::class);
        $this->expectExceptionMessage('invalid Sec-WebSocket-Accept');

        $this->server->client->awaitUpgrade($key);
    }

    public function testReceivesSeveralFramesFromOneRead(): void
    {
        $client = $this->connected();

        $this->server->send('one');
        $this->server->send('two');
        $this->server->send('three');

        self::assertSame(['one', 'two', 'three'], $client->receive(0.1));
    }

    public function testReassemblesAFragmentedMessage(): void
    {
        $client = $this->connected();

        $this->server->sendFragmented('half a ', 'message');

        self::assertSame(['half a message'], $client->receive(0.1));
    }

    public function testAnswersAPingWithAPongAndDoesNotSurfaceIt(): void
    {
        $client = $this->connected();

        $this->server->sendPing('keepalive');

        self::assertSame([], $client->receive(0.1));
        self::assertSame(WebSocketFrame::OP_PONG, $this->server->lastControlOpcodeSent());
    }

    public function testMasksEveryFrameItSends(): void
    {
        // the server decodes with the generic decoder, which unmasks — an unmasked
        // client frame would be a protocol violation a real server closes on
        $client = $this->connected();
        $client->sendText('hello relay');

        self::assertSame(['hello relay'], $this->server->received());
    }

    public function testReassemblesAPayloadTooLargeToArriveInOneRead(): void
    {
        $client = $this->connected();
        $payload = \str_repeat('token ', 5000);

        // 30 KB does not fit the socket buffer, so it crosses in several pieces and the
        // decoder has to hold a partial frame — the shape a long streamed answer takes
        $this->server->send($payload);

        $received = [];
        $deadline = \microtime(true) + 2.0;
        while ([] === $received && \microtime(true) < $deadline) {
            $this->server->flush();
            $received = $client->receive(0.05);
        }

        self::assertSame([$payload], $received);
    }

    public function testReturnsNothingWhenTheServerIsQuiet(): void
    {
        $client = $this->connected();

        self::assertSame([], $client->receive(0.01));
        self::assertTrue($client->isOpen());
    }

    public function testAServerCloseFrameClosesTheConnection(): void
    {
        $client = $this->connected();

        $this->server->sendClose();
        $client->receive(0.1);

        self::assertFalse($client->isOpen());
    }

    private function connected(): WebSocketConnection
    {
        $this->server->handshake();

        return $this->server->client;
    }
}
