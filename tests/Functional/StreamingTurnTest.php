<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Generator;
use Llmor\Cli\Chat\ConversationSession;
use Llmor\Cli\Chat\TurnRenderer;
use Llmor\Cli\Chat\TurnRunner;
use Llmor\Cli\Client\PendingRequest;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Relay\ConversationRelay;
use Llmor\Cli\Relay\RelayEvent;
use Llmor\Cli\Relay\SocketIoClient;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\FakeRelayServer;
use Llmor\Cli\Tests\Support\TempProject;
use Llmor\Cli\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The whole streaming path together: a real relay socket, a real HTTP transfer, and the
 * loop that advances both.
 *
 * This is the one arrangement the rest of the suite cannot cover in pieces: relay events
 * and an HTTP response describing the *same* turn, reaching the same renderer.
 *
 * What it does not cover is wall-clock concurrency. `MockHttpClient` runs a generator
 * body to completion within a single pump, so nothing here would notice if the loop
 * stopped interleaving; that the transfer really does advance a slice at a time while
 * other work happens between pumps was verified against a live HTTP server by hand, and
 * belongs to `PendingRequest::pump()` rather than to this wiring.
 */
#[CoversClass(TurnRunner::class)]
#[CoversClass(PendingRequest::class)]
final class StreamingTurnTest extends TestCase
{
    use TempProject;

    private FakeRelayServer $server;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->makeProject();
        $this->server = new FakeRelayServer();
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->server->close();
        $this->removeProject();
    }

    public function testShowsAStreamedAnswerExactlyOnce(): void
    {
        $relay = $this->joinedRelay();
        $renderer = new TurnRenderer(new OutputStyle(new ArrayInput([]), $this->output));
        $runner = new TurnRunner($renderer, $this->session(), $relay);

        $runner->turn($this->turnRequest(function (): Generator {
            $this->emit(RelayEvent::COMPLETION_STARTED, ['iteration' => 1]);
            $this->emit(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'It is 14°C ']);
            yield '{"conversation":{"token":"17-abc"},"meta":{},';

            $this->emit(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'and raining in Bern.']);
            // the full-text event the server closes every model call with
            $this->emit(RelayEvent::COMPLETION, ['content' => 'It is 14°C and raining in Bern.']);
            $this->emit(RelayEvent::COMPLETION_ENDED);
            $this->emit(RelayEvent::COMPLETION_FINISHED);
            yield '"data":[{"role":"assistant","message":"It is 14°C and raining in Bern.","function_calls":[],'
                .'"meta":{"took":1834,"usage":{"model":{"input":412,"output":96,"total":508}}}}]}';
        }));

        $display = $this->output->fetch();

        // three sources carry this same sentence — the chunks, the `completion` event
        // that follows them, and the message list in the response. Exactly one may reach
        // the screen; printing it twice is what makes the feature look broken.
        self::assertSame(1, \substr_count($display, 'It is 14°C and raining in Bern.'));
        self::assertStringContainsString('412 in / 96 out', $display);
    }

    public function testShowsAToolCallBeforeTheAnswerThatUsesIt(): void
    {
        $relay = $this->joinedRelay();
        $renderer = new TurnRenderer(new OutputStyle(new ArrayInput([]), $this->output));
        $runner = new TurnRunner($renderer, $this->session(), $relay);

        $runner->turn($this->turnRequest(function (): Generator {
            $this->emit(RelayEvent::FUNCTION_BEGIN, ['id' => 'c1', 'function_name' => 'weather', 'arguments' => ['city' => 'Bern']]);
            yield '{"conversation":{"token":"17-abc"},';

            $this->emit(RelayEvent::FUNCTION_END, ['id' => 'c1', 'result' => '{"temp":14}']);
            $this->emit(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'It is 14°C.']);
            $this->emit(RelayEvent::COMPLETION_FINISHED);
            yield '"data":[{"role":"assistant","message":"It is 14°C.","function_calls":[]}]}';
        }));

        $display = $this->output->fetch();

        $call = \strpos($display, '⚙ weather');
        $result = \strpos($display, '✓ weather');
        $answer = \strpos($display, 'It is 14°C.');

        self::assertNotFalse($call);
        self::assertNotFalse($result);
        self::assertNotFalse($answer);
        // the whole point of streaming: you watch the tool run, then read the answer
        self::assertLessThan($result, $call);
        self::assertLessThan($answer, $result);
    }

    public function testAnInterruptReachesTheRelayMidTurn(): void
    {
        $relay = $this->joinedRelay();
        $renderer = new TurnRenderer(new OutputStyle(new ArrayInput([]), $this->output));
        $runner = new TurnRunner($renderer, $this->session(), $relay);
        $this->server->received();

        $runner->turn($this->turnRequest(function () use ($runner): Generator {
            yield '{"conversation":{"token":"17-abc"},';

            // what the SIGINT handler does, at the point it would really fire
            $runner->interrupt();
            $this->emit(RelayEvent::COMPLETION_FINISHED);
            yield '"data":[]}';
        }));

        self::assertTrue($runner->wasInterrupted());
        self::assertContains('42["interrupt"]', $this->server->received());
    }

    public function testATurnStillCompletesWithNoRelayAtAll(): void
    {
        $renderer = new TurnRenderer(new OutputStyle(new ArrayInput([]), $this->output));
        $runner = new TurnRunner($renderer, $this->session(), null);

        $runner->turn($this->turnRequest(static function (): Generator {
            yield '{"conversation":{"token":"17-abc"},"data":[{"role":"assistant","message":"Buffered.","function_calls":[]}]}';
        }));

        // the relay is a preview; the authoritative answer always comes back over HTTP
        self::assertStringContainsString('Buffered.', $this->output->fetch());
    }

    /**
     * A relay past both handshakes and joined to the conversation.
     */
    private function joinedRelay(): ConversationRelay
    {
        $this->server->handshake();
        $client = new SocketIoClient($this->server->client, 0.3);
        $this->server->completeSocketIoHandshake();

        $relay = new ConversationRelay($client, '17-abc');
        $relay->join();

        return $relay;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emit(string $type, array $data = []): void
    {
        $this->server->sendRelayMessage($type, $data);
    }

    /**
     * A session already bound to the conversation the fake relay is joined to.
     *
     * The runner uses it only to advance the message watermark and close the turn — the
     * request itself comes from {@see turnRequest()} — so resuming an empty conversation
     * is all the state it needs.
     */
    private function session(): ConversationSession
    {
        $api = (new FakeLlmorApi())->on('GET', '#/v1/conversations/17-abc$#', static fn (): array => [200, [
            'status' => 'idle',
            'conversation' => ['token' => '17-abc'],
            'data' => [],
        ]]);

        return ConversationSession::resume(TestClient::forApi($api, $this->projectDir), '17-abc');
    }

    /**
     * An in-flight interact request whose body is produced by $body.
     *
     * @param callable(): Generator<int, string> $body
     */
    private function turnRequest(callable $body): PendingRequest
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($body): MockResponse {
            // the client signs every request, so the session handshake has to answer too
            if (\str_contains($url, '/v1/auth/session')) {
                return new MockResponse((string) \json_encode(['token' => 'tok', 'secret' => 'sec']));
            }
            if (\str_contains($url, '/v1/auth/signin')) {
                return new MockResponse((string) \json_encode(['data' => ['id' => 1]]));
            }

            // a generator body is delivered piece by piece as the transfer is pumped,
            // which is what lets the relay messages it emits land mid-request
            return new MockResponse($body(), ['response_headers' => ['content-type' => 'application/json']]);
        }, 'https://api.test');

        return TestClient::forHttp($http, $this->projectDir)
            ->requestAsync('POST', '/v1/conversations/17-abc', [], ['content' => 'hi'])
        ;
    }
}
