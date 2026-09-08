<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Chat;

use Llmor\Cli\Chat\TurnRenderer;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Relay\RelayEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(TurnRenderer::class)]
final class TurnRendererTest extends TestCase
{
    private BufferedOutput $output;
    private TurnRenderer $renderer;
    private string $display = '';

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->renderer = new TurnRenderer(new OutputStyle(new ArrayInput([]), $this->output));
        $this->renderer->beginTurn();
    }

    public function testStreamsChunksAsTheyArrive(): void
    {
        $this->stream('Hello ', 'from ', 'the model.');

        self::assertStringContainsString('bot ›', $this->display());
        self::assertStringContainsString('Hello from the model.', $this->display());
    }

    public function testDoesNotPrintTheAnswerTwiceWhenItWasStreamed(): void
    {
        $this->stream('It is raining in Bern.');
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_FINISHED));

        $this->renderer->finish([$this->assistant('It is raining in Bern.')]);

        // the single most damaging failure mode: the streamed text repeated verbatim
        self::assertSame(1, \substr_count($this->display(), 'It is raining in Bern.'));
    }

    public function testIgnoresWhitespaceDifferencesWhenDecidingItAlreadyStreamed(): void
    {
        // the persisted message routinely differs from the concatenated chunks by
        // trailing whitespace or a normalised newline; a strict compare would double up
        $this->stream("Line one\nLine two");

        $this->renderer->finish([$this->assistant("Line one\nLine two\n")]);

        self::assertSame(1, \substr_count($this->display(), 'Line two'));
    }

    public function testDoesNotRepeatTheCompletionEventThatFollowsTheChunks(): void
    {
        // the real sequence: the server closes every model call with `completion`
        // carrying the full text, *after* the chunks that already delivered it
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_STARTED, ['iteration' => 1]));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'Hello! ']));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'How can I help?']));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION, ['content' => 'Hello! How can I help?']));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_ENDED));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_FINISHED));

        $this->renderer->finish([$this->assistant('Hello! How can I help?')]);

        self::assertSame(1, \substr_count($this->display(), 'Hello! How can I help?'));
    }

    public function testUsesTheCompletionEventWhenTheModelDidNotStream(): void
    {
        // same event, opposite role: with no chunks before it, it is the only copy
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_STARTED, ['iteration' => 1]));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION, ['content' => 'Whole answer at once.']));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_ENDED));

        self::assertStringContainsString('Whole answer at once.', $this->display());
    }

    public function testEachIterationOfAToolUsingTurnGetsItsOwnCompletion(): void
    {
        // two model calls in one turn, each closed by its own `completion` — the second
        // must not be suppressed by the first segment's text
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_STARTED, ['iteration' => 1]));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'Let me check.']));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION, ['content' => 'Let me check.']));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_ENDED));

        $this->renderer->handle(new RelayEvent(RelayEvent::FUNCTION_BEGIN, ['id' => 'c1', 'function_name' => 'weather', 'arguments' => []]));
        $this->renderer->handle(new RelayEvent(RelayEvent::FUNCTION_END, ['id' => 'c1', 'result' => '{"temp":14}']));

        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_STARTED, ['iteration' => 2]));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_CHUNK, ['chunk' => 'It is 14°C.']));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION, ['content' => 'It is 14°C.']));
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_FINISHED));

        $display = $this->display();

        self::assertSame(1, \substr_count($display, 'Let me check.'));
        self::assertSame(1, \substr_count($display, 'It is 14°C.'));
    }

    public function testPrintsTheAnswerWhenNothingWasStreamed(): void
    {
        // the relay never connected, or the model does not support streaming
        $this->renderer->finish([$this->assistant('Buffered answer.')]);

        self::assertStringContainsString('bot ›', $this->display());
        self::assertStringContainsString('Buffered answer.', $this->display());
    }

    public function testRendersToolCallsLive(): void
    {
        $this->renderer->handle(new RelayEvent(RelayEvent::FUNCTION_BEGIN, [
            'id' => 'call_1',
            'function_name' => 'weather',
            'arguments' => ['city' => 'Bern'],
        ]));
        $this->renderer->handle(new RelayEvent(RelayEvent::FUNCTION_END, [
            'id' => 'call_1',
            'result' => '{"temp":14}',
        ]));

        $display = $this->display();

        self::assertStringContainsString('⚙ weather', $display);
        self::assertStringContainsString('{"city":"Bern"}', $display);
        self::assertStringContainsString('✓ weather', $display);
        self::assertStringContainsString('{"temp":14}', $display);
    }

    public function testRendersToolCallsFromTheResponseWhenTheRelayWasSilent(): void
    {
        $this->renderer->finish([
            [
                'role' => 'assistant',
                'message' => '',
                'function_calls' => [['id' => 'call_1', 'name' => 'weather', 'arguments' => ['city' => 'Bern']]],
            ],
            ['role' => 'tool', 'message' => '{"temp":14}', 'function_response' => 'call_1'],
            $this->assistant('It is 14°C.'),
        ]);

        $display = $this->display();

        self::assertStringContainsString('⚙ weather', $display);
        self::assertStringContainsString('✓ weather', $display);
        self::assertStringContainsString('It is 14°C.', $display);
    }

    public function testDoesNotRepeatAToolCallItAlreadyShowedLive(): void
    {
        $this->renderer->handle(new RelayEvent(RelayEvent::FUNCTION_BEGIN, [
            'id' => 'call_1', 'function_name' => 'weather', 'arguments' => [],
        ]));
        $this->renderer->handle(new RelayEvent(RelayEvent::FUNCTION_END, [
            'id' => 'call_1', 'result' => '{"temp":14}',
        ]));
        $this->stream('It is 14°C.');

        $this->renderer->finish([
            [
                'role' => 'assistant',
                'message' => '',
                'function_calls' => [['id' => 'call_1', 'name' => 'weather', 'arguments' => []]],
            ],
            ['role' => 'tool', 'message' => '{"temp":14}', 'function_response' => 'call_1'],
            $this->assistant('It is 14°C.'),
        ]);

        $display = $this->display();

        self::assertSame(1, \substr_count($display, '⚙ weather'));
        self::assertSame(1, \substr_count($display, '✓ weather'));
        self::assertSame(1, \substr_count($display, 'It is 14°C.'));
    }

    public function testRendersTheUsageFooter(): void
    {
        $this->renderer->finish([[
            'role' => 'assistant',
            'message' => 'Done.',
            'meta' => ['took' => 1800, 'usage' => ['model' => ['input' => 412, 'output' => 96, 'total' => 508]]],
        ]]);

        self::assertStringContainsString('1.8s', $this->display());
        self::assertStringContainsString('412 in / 96 out', $this->display());
    }

    public function testTreatsModelTextAsDataNotAsConsoleMarkup(): void
    {
        // a model answering with `<error>` must not be able to recolour the terminal,
        // and a tag split across two chunks must not reassemble into a live one either
        $this->stream('use <in', 'fo>tags</info> carefully');

        self::assertStringContainsString('use <info>tags</info> carefully', $this->display());
    }

    public function testRendersAHistoryTranscript(): void
    {
        $this->renderer->renderHistory([
            ['role' => 'user', 'message' => 'hi'],
            $this->assistant('hello'),
        ]);

        $display = $this->display();

        self::assertStringContainsString('you ›', $display);
        self::assertStringContainsString('hi', $display);
        self::assertStringContainsString('bot ›', $display);
        self::assertStringContainsString('hello', $display);
    }

    public function testSurfacesRuntimeLogMessages(): void
    {
        $this->renderer->finish([[
            'role' => 'log',
            'creator' => 'system',
            'message' => 'Maximum function call iterations reached.',
        ]]);

        self::assertStringContainsString('Maximum function call iterations reached.', $this->display());
    }

    private function stream(string ...$chunks): void
    {
        foreach ($chunks as $chunk) {
            $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_CHUNK, ['chunk' => $chunk]));
        }
        $this->renderer->handle(new RelayEvent(RelayEvent::COMPLETION_ENDED));
    }

    /**
     * @return array<string, mixed>
     */
    private function assistant(string $message): array
    {
        return ['role' => 'assistant', 'message' => $message, 'function_calls' => []];
    }

    /**
     * BufferedOutput::fetch() drains what it returns, so accumulate — several
     * assertions per test read the same rendered turn.
     */
    private function display(): string
    {
        return $this->display .= $this->output->fetch();
    }
}
