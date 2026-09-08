<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Relay;

use Llmor\Cli\Relay\ConversationRelay;
use Llmor\Cli\Relay\RelayEvent;
use Llmor\Cli\Relay\RelayException;
use Llmor\Cli\Relay\SocketIoClient;
use Llmor\Cli\Tests\Support\FakeRelayServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversationRelay::class)]
#[CoversClass(SocketIoClient::class)]
#[CoversClass(RelayEvent::class)]
final class ConversationRelayTest extends TestCase
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

    public function testJoinsTheConversationChannelAfterBothHandshakes(): void
    {
        $relay = $this->joined('17-abc');

        self::assertTrue($relay->isConnected());

        // Socket.IO CONNECT to the default namespace, then the join event carrying the
        // conversation token — the channel name the relay fans out on
        self::assertSame(['40', '42["join","17-abc"]'], $this->server->received());
    }

    public function testWaitsForTheEngineIoOpenPacketBeforeConnecting(): void
    {
        $client = $this->handshakenClient();

        // only the Engine.IO open — no `40` acknowledgement follows
        $this->server->send('0{"sid":"abc"}');

        $this->expectException(RelayException::class);
        $this->expectExceptionMessage('never acknowledged');

        $client->connect();
    }

    public function testDecodesRelayMessagesIntoEvents(): void
    {
        $relay = $this->joined();

        $this->server->sendRelayMessage(RelayEvent::COMPLETION_STARTED, ['iteration' => 1, 'iteration_max' => 4]);
        $this->server->sendRelayMessage(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'Hello ']);
        $this->server->sendRelayMessage(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'world']);

        $events = $relay->poll(0.1);

        self::assertCount(3, $events);
        self::assertSame(RelayEvent::COMPLETION_STARTED, $events[0]->type);
        self::assertSame(1, $events[0]->int('iteration'));
        self::assertSame('Hello ', $events[1]->string('chunk'));
        self::assertSame('world', $events[2]->string('chunk'));
    }

    public function testDecodesAToolCallPair(): void
    {
        $relay = $this->joined();

        $this->server->sendRelayMessage(RelayEvent::FUNCTION_BEGIN, [
            'id' => 'call_1',
            'function_name' => 'weather',
            'arguments' => ['city' => 'Bern'],
        ]);
        $this->server->sendRelayMessage(RelayEvent::FUNCTION_END, [
            'id' => 'call_1',
            'result' => '{"temp":14}',
        ]);

        $events = $relay->poll(0.1);

        self::assertSame(RelayEvent::FUNCTION_BEGIN, $events[0]->type);
        self::assertSame('weather', $events[0]->string('function_name'));
        self::assertSame(['city' => 'Bern'], $events[0]->data['arguments']);
        self::assertSame('{"temp":14}', $events[1]->string('result'));
    }

    public function testReadsTheSuspendedFlagOffTheTerminalEvent(): void
    {
        $relay = $this->joined();

        $this->server->sendRelayMessage(RelayEvent::COMPLETION_FINISHED, ['suspended' => true]);

        $events = $relay->poll(0.1);

        self::assertTrue($events[0]->bool('suspended'));
    }

    public function testAnswersEngineIoPingsSoTheServerKeepsUsAround(): void
    {
        $relay = $this->joined();
        $this->server->received();

        $this->server->send('2');
        $relay->poll(0.1);

        self::assertSame(['3'], $this->server->received());
    }

    public function testIgnoresEventsThatAreNotRelayMessages(): void
    {
        $relay = $this->joined();

        // the relay also emits state acks and errors on the same socket
        $this->server->send('42["state_change",{"state":{}}]');
        $this->server->send('42["interrupt_ack",{"status":"sent"}]');

        self::assertSame([], $relay->poll(0.1));
    }

    public function testSurvivesAMalformedPayload(): void
    {
        $relay = $this->joined();

        // a garbled frame must not end the turn — the real result still arrives by HTTP
        $this->server->send('42["message","{not json"]');
        $this->server->sendRelayMessage(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'still here']);

        $events = $relay->poll(0.1);

        self::assertCount(1, $events);
        self::assertSame('still here', $events[0]->string('chunk'));
    }

    public function testInterruptEmitsOnTheSocket(): void
    {
        $relay = $this->joined();
        $this->server->received();

        $relay->interrupt();

        self::assertSame(['42["interrupt"]'], $this->server->received());
    }

    public function testInterruptIsSilentWhenNothingIsConnected(): void
    {
        $relay = $this->joined();
        $relay->close();

        $relay->interrupt();

        self::assertFalse($relay->isConnected());
    }

    public function testDrainKeepsReadingWhileMessagesStillArrive(): void
    {
        $relay = $this->joined();

        $this->server->sendRelayMessage(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'late']);
        $this->server->sendRelayMessage(RelayEvent::COMPLETION_FINISHED);

        $events = $relay->drain(0.1);

        self::assertCount(2, $events);
        self::assertSame(RelayEvent::COMPLETION_FINISHED, $events[1]->type);
    }

    /**
     * A relay past both handshakes and joined to a conversation.
     */
    private function joined(string $token = '17-abc'): ConversationRelay
    {
        $client = $this->handshakenClient();
        $this->server->completeSocketIoHandshake();

        $relay = new ConversationRelay($client, $token);
        $relay->join();

        return $relay;
    }

    /**
     * A Socket.IO client whose WebSocket transport is up but whose protocol handshake
     * has not run yet.
     */
    private function handshakenClient(): SocketIoClient
    {
        $this->server->handshake();

        return new SocketIoClient($this->server->client, 0.3);
    }
}
