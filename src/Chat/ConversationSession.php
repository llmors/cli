<?php

declare(strict_types=1);

namespace Llmor\Cli\Chat;

use Llmor\Cli\Client\ApiResponse;
use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Client\PendingRequest;
use Llmor\Cli\Sync\Json;

/**
 * One conversation with an app, and the requests that drive it.
 *
 * The conversation endpoints are flat and not vendor-scoped: identity is the
 * `{vendorAppId}-{token}` string the create call hands back, and holding it is the
 * credential. We sign anyway, since every request goes through {@see LlmorClient}.
 *
 * Every interact response carries the *entire* message list, not a delta — so callers
 * that want "what changed" have to diff against what they had, which is what
 * {@see ConversationSession::$messageCount} is for.
 */
final class ConversationSession
{
    /** How many messages we had already seen before the current turn. */
    private int $messageCount = 0;

    /** @var array<string, mixed> */
    private array $conversation;

    /**
     * @param array<string, mixed> $conversation
     */
    private function __construct(
        private readonly LlmorClient $client,
        array $conversation,
    ) {
        $this->conversation = $conversation;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function create(LlmorClient $client, int $appId, ?string $channel = null, array $parameters = []): self
    {
        $body = ['vendor_app_id' => $appId];
        if (null !== $channel && '' !== $channel) {
            $body['vendor_channel'] = $channel;
        }
        if ([] !== $parameters) {
            $body['parameters'] = $parameters;
        }

        return new self($client, $client->post('/v1/conversations', $body)->data());
    }

    /**
     * Pick up an existing conversation by its `{appId}-{token}` string.
     *
     * The GET doubles as validation — a token that does not resolve, or points at a
     * sub-agent conversation the server will not let a client drive, comes back 404.
     */
    public static function resume(LlmorClient $client, string $token): self
    {
        $response = $client->get(self::pathFor($token));
        $body = $response->body;

        $conversation = $body['conversation'] ?? null;
        /** @var array<string, mixed> $conversation */
        $conversation = \is_array($conversation) ? $conversation : ['token' => $token];
        $conversation['token'] ??= $token;

        $session = new self($client, $conversation);
        $session->messageCount = \count(self::messagesIn($body));

        return $session;
    }

    public function token(): string
    {
        return Json::stringOf($this->conversation['token'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function record(): array
    {
        return $this->conversation;
    }

    public function path(): string
    {
        return self::pathFor($this->token());
    }

    /**
     * Post a message and hand back the in-flight request, so the caller can pump the
     * relay while the model works.
     *
     * `stream`/`relay` are what make the server publish token deltas; with both off the
     * turn is silent until it returns, which is exactly the `--no-stream` behaviour.
     */
    public function send(string $content, bool $stream = true): PendingRequest
    {
        return $this->interact(['content' => $content], $stream);
    }

    /**
     * Answer a pending ask-user batch. The whole batch resolves atomically, so every
     * pending prompt must appear in $answers.
     *
     * @param list<array{id: string, result: mixed}> $answers
     */
    public function answerAskUser(array $answers, bool $stream = true): PendingRequest
    {
        return $this->interact(['ask_user_response' => $answers], $stream);
    }

    public function cancelAskUser(bool $stream = true): PendingRequest
    {
        return $this->interact(['ask_user_cancel' => true], $stream);
    }

    /**
     * The current state without sending anything — used to show history on resume.
     */
    public function fetch(): ApiResponse
    {
        $response = $this->client->get($this->path());
        $this->messageCount = \count(self::messagesIn($response->body));

        return $response;
    }

    /**
     * The messages this turn added, and the running total, taken from an interact
     * response. Calling this advances the watermark, so it is the caller's single
     * "what just happened" accessor.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    public function consumeNewMessages(array $body): array
    {
        $messages = self::messagesIn($body);
        $new = \array_slice($messages, $this->messageCount);
        $this->messageCount = \count($messages);

        if (\is_array($body['conversation'] ?? null)) {
            /** @var array<string, mixed> $conversation */
            $conversation = $body['conversation'];
            $this->conversation = $conversation + $this->conversation;
        }

        return \array_values($new);
    }

    /**
     * Every message in an interact/get response.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    public static function messagesIn(array $body): array
    {
        $data = $body['data'] ?? null;
        if (!\is_array($data)) {
            return [];
        }

        $messages = [];
        foreach ($data as $message) {
            if (\is_array($message)) {
                /* @var array<string, mixed> $message */
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * The structured questions the model is waiting on, if the turn suspended.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingAskUser(array $body): array
    {
        $pending = $body['pending_ask_user'] ?? null;
        if (!\is_array($pending)) {
            return [];
        }

        $prompts = [];
        foreach ($pending as $prompt) {
            if (\is_array($prompt) && \is_string($prompt['id'] ?? null)) {
                /* @var array<string, mixed> $prompt */
                $prompts[] = $prompt;
            }
        }

        return $prompts;
    }

    /**
     * Every interact call, and the one place the publish flags are decided: asking the
     * server to publish deltas nobody is listening for is pure waste.
     *
     * @param array<string, mixed> $body
     */
    private function interact(array $body, bool $stream): PendingRequest
    {
        return $this->client->requestAsync('POST', $this->path(), [], $body + [
            'stream' => $stream,
            'relay' => $stream,
        ]);
    }

    private static function pathFor(string $token): string
    {
        return '/v1/conversations/'.\rawurlencode($token);
    }
}
