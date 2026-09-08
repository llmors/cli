<?php

declare(strict_types=1);

namespace Llmor\Cli\Chat;

use Llmor\Cli\Client\ApiResponse;
use Llmor\Cli\Client\PendingRequest;
use Llmor\Cli\Relay\ConversationRelay;
use Llmor\Cli\Relay\RelayEvent;

/**
 * Drives one turn to completion, advancing the HTTP request and the relay together.
 *
 * The interact POST blocks for as long as the model works, while the token deltas travel
 * a different path entirely (Redis → relay service → our socket). Single-threaded PHP
 * has to make progress on both, so the loop alternates: read whatever the relay has,
 * give the transfer a slice, repeat.
 *
 * The HTTP response is always the authority. Relay output is a preview that may be
 * incomplete or absent; nothing here fails a turn because the relay misbehaved.
 */
final class TurnRunner
{
    /** How long to keep reading the relay after the response lands, for stragglers. */
    private const DRAIN_SECONDS = 0.2;

    /** Relay read slice. Short enough to stay responsive to Ctrl+C, long enough to idle cheaply. */
    private const POLL_SECONDS = 0.05;

    private bool $interrupted = false;

    /** Whether the relay has already delivered this turn's terminal event. */
    private bool $finished = false;

    public function __construct(
        private readonly TurnRenderer $renderer,
        private readonly ConversationSession $session,
        private readonly ?ConversationRelay $relay = null,
    ) {
    }

    /**
     * Ask the server to stop, and remember that we did.
     *
     * Safe to call from a signal handler: it only emits on an already-open socket and
     * swallows failures, since the user pressing Ctrl+C wants out either way.
     */
    public function interrupt(): void
    {
        $this->interrupted = true;
        $this->relay?->interrupt();
    }

    public function wasInterrupted(): bool
    {
        return $this->interrupted;
    }

    /**
     * Is anything actually listening for this turn's deltas?
     *
     * Callers pass this straight back as the interact call's `stream`/`relay` flags:
     * asking the server to publish token deltas nobody has joined the channel for is
     * pure waste. The runner owns the answer because it owns the relay.
     */
    public function isLive(): bool
    {
        return null !== $this->relay && $this->relay->isConnected();
    }

    /**
     * Drive one whole turn: run $pending, then render the authoritative result.
     *
     * This is the closing half of the turn as much as the loop is — the streamed text
     * and the final message list are the same text, so advancing the session's watermark
     * and rendering the remainder have to happen together, exactly once. Keeping them
     * here is what stops a second turn-initiating path from re-printing the whole
     * conversation.
     *
     * @throws \Llmor\Cli\Client\Exception\ApiException on a transport or HTTP failure
     */
    public function turn(PendingRequest $pending): ApiResponse
    {
        $response = $this->run($pending);
        $this->renderer->finish($this->session->consumeNewMessages($response->body));

        return $response;
    }

    /**
     * Run $pending to completion, rendering the live stream but not the result.
     *
     * Only a caller that renders nothing at all (`--json`) wants this rather than
     * {@see turn()}.
     *
     * @throws \Llmor\Cli\Client\Exception\ApiException on a transport or HTTP failure
     */
    public function run(PendingRequest $pending): ApiResponse
    {
        $this->interrupted = false;
        $this->finished = false;
        $this->renderer->beginTurn();

        while (!$pending->pump(null === $this->relay ? self::POLL_SECONDS : 0.0)) {
            $this->drainRelay(self::POLL_SECONDS);
            $this->dispatchSignals();
        }

        // the response can overtake the last few relay messages, which took the slower
        // path — so keep reading briefly rather than cutting the stream off mid-sentence.
        // Once the terminal event is in, nothing else is coming and the wait is skipped.
        $this->drainRelay(0.0);
        if (!$this->finished) {
            foreach ($this->relay?->drain(self::DRAIN_SECONDS, RelayEvent::COMPLETION_FINISHED) ?? [] as $event) {
                $this->handle($event);
            }
        }

        return $pending->result();
    }

    private function drainRelay(float $timeout): void
    {
        if (null === $this->relay) {
            return;
        }

        foreach ($this->relay->poll($timeout) as $event) {
            $this->handle($event);
        }
    }

    private function handle(RelayEvent $event): void
    {
        $this->finished = $this->finished || RelayEvent::COMPLETION_FINISHED === $event->type;
        $this->renderer->handle($event);
    }

    /**
     * Let a pending SIGINT reach its handler.
     *
     * `pcntl` is in the static binary's extension set but is not universal — a PHP built
     * without it simply has no interrupt, which is why this is guarded rather than
     * required.
     */
    private function dispatchSignals(): void
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            \pcntl_signal_dispatch();
        }
    }
}
