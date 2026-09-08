<?php

declare(strict_types=1);

namespace Llmor\Cli\Chat;

use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Relay\RelayEvent;
use Llmor\Cli\Sync\Json;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Renders one chat turn: the live relay events as they arrive, then the authoritative
 * result once the HTTP request returns.
 *
 * Three sources carry the same assistant text: the `completion_stream` chunks, the
 * `completion` event the server closes every model call with, and the message list in
 * the HTTP response. Exactly one of them may reach the screen, so the renderer tracks
 * what it has already shown and each later source prints only the remainder. That covers
 * a normal streamed turn (footer only), a model that does not stream (the `completion`
 * event is the answer), and a relay that never connected (the whole turn, tool calls
 * included) with one rule.
 */
final class TurnRenderer
{
    private const ROLE_ASSISTANT = 'assistant';
    private const ROLE_TOOL = 'tool';

    /** Everything the live stream has written this turn. */
    private string $streamed = '';

    /** {@see $streamed} whitespace-normalised, rebuilt lazily after each chunk. */
    private ?string $streamedNormalised = null;

    /**
     * What the *current* completion segment has written.
     *
     * A turn is one segment per model call — several of them when tool calls make it
     * loop — and each segment ends with a `completion` event carrying that call's whole
     * text again. Tracking the segment on its own is what tells "the stream already
     * showed this" apart from "this model does not stream at all".
     */
    private string $segment = '';

    /** True while the cursor sits at the end of a streamed line. */
    private bool $lineOpen = false;

    /**
     * Tool-call ids seen live, so finish() does not repeat them.
     *
     * @var array<string, string> id => function name
     */
    private array $toolCalls = [];

    public function __construct(
        private readonly OutputStyle $io,
        private readonly int $resultWidth = 160,
    ) {
    }

    public function beginTurn(): void
    {
        $this->streamed = '';
        $this->streamedNormalised = null;
        $this->segment = '';
        $this->lineOpen = false;
        $this->toolCalls = [];
    }

    public function handle(RelayEvent $event): void
    {
        match ($event->type) {
            RelayEvent::COMPLETION_STARTED => $this->beginSegment(),
            RelayEvent::COMPLETION_CHUNK => $this->writeChunk($event->string('chunk')),
            RelayEvent::COMPLETION => $this->writeCompletion($event->string('content')),
            RelayEvent::COMPLETION_ENDED, RelayEvent::COMPLETION_FINISHED => $this->endSegment(),
            RelayEvent::FUNCTION_BEGIN => $this->renderCallBegin($event),
            RelayEvent::FUNCTION_END => $this->renderCallEnd($event),
            default => null,
        };
    }

    /**
     * Render whatever the live stream did not, then the usage footer.
     *
     * @param list<array<string, mixed>> $messages the messages this turn added
     */
    public function finish(array $messages): void
    {
        $this->closeLine();

        foreach ($messages as $message) {
            $role = Json::stringOf($message['role'] ?? null);

            if (self::ROLE_TOOL === $role) {
                $this->renderToolResultMessage($message);

                continue;
            }

            if (self::ROLE_ASSISTANT !== $role) {
                // `log` messages carry runtime notices (a max-iteration warning, say)
                $this->renderLogMessage($message);

                continue;
            }

            $this->renderPendingCalls($message);
            $this->renderAssistantText(Json::stringOf($message['message'] ?? null));
        }

        $this->renderFooter($messages);
    }

    /**
     * Print a whole conversation, oldest first — used when resuming.
     *
     * @param list<array<string, mixed>> $messages
     */
    public function renderHistory(array $messages): void
    {
        foreach ($messages as $message) {
            $role = Json::stringOf($message['role'] ?? null);
            $text = Json::stringOf($message['message'] ?? null);

            match ($role) {
                'user' => $this->io->writeln(\sprintf("\n<accent>you ›</accent> %s", OutputFormatter::escape($text))),
                self::ROLE_ASSISTANT => $this->renderHistoryAssistant($message, $text),
                self::ROLE_TOOL => $this->renderToolResultMessage($message),
                default => $this->renderLogMessage($message),
            };
        }
    }

    public function writePrompt(): void
    {
        $this->io->newLine();
        $this->io->write('<ok>bot ›</ok> ');
    }

    // -- live stream ------------------------------------------------------------

    private function writeChunk(string $chunk): void
    {
        if ('' === $chunk) {
            return;
        }

        if (!$this->lineOpen) {
            $this->writePrompt();
            $this->lineOpen = true;
        }

        $this->streamed .= $chunk;
        $this->segment .= $chunk;
        $this->streamedNormalised = null;

        // escaping per chunk is safe even when a `<tag>` straddles two of them: every
        // `<` is neutralised on its own, so no partial tag can survive to be reassembled
        $this->io->write(OutputFormatter::escape($chunk));
    }

    /**
     * Handle the `completion` event that closes every model call.
     *
     * The server sends it whether or not the call streamed, carrying that call's full
     * text — so it is a verbatim repeat of the chunks whenever any arrived, and the only
     * copy of the answer when the model does not stream. Printing it unconditionally is
     * what put every answer on screen twice.
     *
     * If the stream dropped chunks the segment won't match, and this stays silent:
     * {@see finish()} then prints the complete answer from the HTTP response, which is
     * authoritative, rather than us stitching a guess out of two partial copies.
     */
    private function writeCompletion(string $content): void
    {
        if ('' === $content || '' !== $this->segment) {
            return;
        }

        $this->writeChunk($content);
    }

    private function beginSegment(): void
    {
        $this->segment = '';
    }

    private function endSegment(): void
    {
        $this->closeLine();
        $this->segment = '';
    }

    private function closeLine(): void
    {
        if (!$this->lineOpen) {
            return;
        }

        $this->io->newLine();
        $this->lineOpen = false;
    }

    private function renderCallBegin(RelayEvent $event): void
    {
        $this->closeLine();

        $name = $event->string('function_name');
        $id = $event->string('id');
        if ('' !== $id) {
            $this->toolCalls[$id] = $name;
        }

        $this->writeCall($name, $event->data['arguments'] ?? null);
    }

    private function renderCallEnd(RelayEvent $event): void
    {
        $id = $event->string('id');
        $name = $this->toolCalls[$id] ?? '';

        $this->writeCallResult('' !== $name ? $name : $id, $event->data['result'] ?? null);
    }

    /**
     * The one spelling of a tool call in the gutter — shared by the live relay path and
     * the relay-less one, which must not be able to render the same turn differently.
     */
    private function writeCall(string $name, mixed $arguments): void
    {
        $this->io->writeln(\sprintf(
            '  <warn>⚙</warn> %s  <muted>%s</muted>',
            OutputFormatter::escape($name),
            OutputFormatter::escape($this->compact($arguments)),
        ));
    }

    private function writeCallResult(string $label, mixed $result): void
    {
        $this->io->writeln(\sprintf(
            '  <ok>✓</ok> %s  <muted>%s</muted>',
            OutputFormatter::escape($label),
            OutputFormatter::escape($this->compact($result)),
        ));
    }

    // -- authoritative result ---------------------------------------------------

    /**
     * Tool calls the stream did not already show — the relay-less path.
     *
     * @param array<string, mixed> $message
     */
    private function renderPendingCalls(array $message): void
    {
        $calls = $message['function_calls'] ?? null;
        if (!\is_array($calls)) {
            return;
        }

        foreach ($calls as $call) {
            if (!\is_array($call)) {
                continue;
            }

            $id = Json::stringOf($call['id'] ?? null);
            if ('' !== $id && isset($this->toolCalls[$id])) {
                continue;
            }

            $name = Json::stringOf($call['name'] ?? null);
            if ('' !== $id) {
                $this->toolCalls[$id] = $name;
            }

            $this->writeCall($name, $call['arguments'] ?? null);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function renderToolResultMessage(array $message): void
    {
        // a tool result already shown live as function_call.end is not repeated
        $id = Json::stringOf($message['function_response'] ?? null);
        if ('' !== $id && $this->didStream()) {
            return;
        }

        $this->writeCallResult($this->toolCalls[$id] ?? $id, Json::stringOf($message['message'] ?? null));
    }

    private function renderAssistantText(string $text): void
    {
        $text = \trim($text);
        if ('' === $text) {
            return;
        }

        // the stream already put this on screen; printing it again is the one failure
        // mode that makes the whole feature look broken
        if ($this->alreadyStreamed($text)) {
            return;
        }

        $this->writePrompt();
        $this->io->writeln(OutputFormatter::escape($text));
        $this->lineOpen = false;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function renderHistoryAssistant(array $message, string $text): void
    {
        $this->renderPendingCalls($message);

        if ('' !== \trim($text)) {
            $this->writePrompt();
            $this->io->writeln(OutputFormatter::escape($text));
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function renderLogMessage(array $message): void
    {
        $text = \trim(Json::stringOf($message['message'] ?? null));
        if ('' === $text) {
            return;
        }

        $this->io->writeln(\sprintf('  <warn>!</warn> <muted>%s</muted>', OutputFormatter::escape($text)));
    }

    /**
     * Timing and token usage from the last assistant message of the turn.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function renderFooter(array $messages): void
    {
        $meta = null;
        foreach ($messages as $message) {
            if (self::ROLE_ASSISTANT === Json::stringOf($message['role'] ?? null) && \is_array($message['meta'] ?? null)) {
                /** @var array<string, mixed> $meta */
                $meta = $message['meta'];
            }
        }

        if (null === $meta) {
            return;
        }

        $parts = [];

        $took = $meta['took'] ?? null;
        if (\is_numeric($took) && $took > 0) {
            $parts[] = \sprintf('%.1fs', (float) $took / 1000);
        }

        $usage = $meta['usage'] ?? null;
        $model = \is_array($usage) ? ($usage['model'] ?? null) : null;
        if (\is_array($model)) {
            $in = $model['input'] ?? null;
            $out = $model['output'] ?? null;
            if (\is_numeric($in) || \is_numeric($out)) {
                $parts[] = \sprintf('%d in / %d out', (int) $in, (int) $out);
            }
        }

        if ([] === $parts) {
            return;
        }

        $this->io->writeln(\sprintf('  <muted>%s</muted>', \implode(' · ', $parts)));
    }

    /** True once anything at all has been streamed for this turn. */
    private function didStream(): bool
    {
        return '' !== $this->streamed;
    }

    /**
     * Did the live stream already show this text?
     *
     * Compared on whitespace-normalised text: the stream writes chunks verbatim while
     * the persisted message may differ in trailing whitespace, and a false negative here
     * prints the answer twice. The stream side is memoised — a tool-using turn asks this
     * once per function-call iteration, against a string that only grows on a chunk.
     */
    private function alreadyStreamed(string $text): bool
    {
        $this->streamedNormalised ??= OutputStyle::oneLine($this->streamed);
        $candidate = OutputStyle::oneLine($text);

        return '' !== $candidate
            && '' !== $this->streamedNormalised
            && \str_contains($this->streamedNormalised, $candidate);
    }

    /**
     * A value squeezed onto one line for a tool-call gutter.
     */
    private function compact(mixed $value): string
    {
        $text = \is_string($value)
            ? $value
            : (string) \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return OutputStyle::oneLine($text, $this->resultWidth);
    }
}
