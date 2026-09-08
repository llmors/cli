<?php

declare(strict_types=1);

namespace Llmor\Cli\Relay;

/**
 * One `ConversationRelayMessage` as the server published it: a type and a data bag.
 *
 * The constants are the types the chat runtime emits. Note that the chunk event is
 * `completion_stream` with an underscore while the lifecycle events are dotted — that
 * inconsistency is on the server, and mirroring it here is safer than "fixing" it.
 */
final class RelayEvent
{
    /** A completion turn began; `iteration`, `iteration_max`. */
    public const COMPLETION_STARTED = 'completion.started';

    /** A token delta; `chunk`. */
    public const COMPLETION_CHUNK = 'completion_stream';

    /**
     * One model call's full text, `content`.
     *
     * Sent at the end of **every** call, streamed or not — it is not the alternative to
     * {@see COMPLETION_CHUNK}, it follows them. So it repeats what the chunks already
     * carried, and is the only copy only when the model does not stream.
     */
    public const COMPLETION = 'completion';

    /** The model finished one iteration; `iteration`, `iteration_max`. */
    public const COMPLETION_ENDED = 'completion.ended';

    /** The turn is over — the terminal event; `suspended` when waiting on the user. */
    public const COMPLETION_FINISHED = 'completion.finished';

    /** A tool call started; `id`, `function_name`, `arguments`. */
    public const FUNCTION_BEGIN = 'function_call.begin';

    /** A tool call returned; `id`, `result` (a JSON string). */
    public const FUNCTION_END = 'function_call.end';

    /** A round of tool calls completed and the model is looping; `iteration_count`. */
    public const FUNCTION_ITERATION = 'function_call_iteration';

    /** The model asked the user something; `prompts`. */
    public const ASK_USER_REQUEST = 'ask_user.request';

    /** A pending ask-user batch was answered or cancelled; `ids`, `cancelled`. */
    public const ASK_USER_RESOLVED = 'ask_user.resolved';

    /** The user's own message, echoed back to every client on the channel; `content`. */
    public const USER_INPUT = 'user_input';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        return \is_numeric($value) ? (int) $value : 0;
    }

    public function bool(string $key): bool
    {
        return true === ($this->data[$key] ?? null);
    }
}
