<?php

declare(strict_types=1);

namespace Llmor\Cli\Auth;

use InvalidArgumentException;

/**
 * Immutable representation of an llmor session: a `token`/`secret` pair plus
 * whether it has been upgraded to a signed-in (user) session.
 */
final class Session
{
    public function __construct(
        public readonly string $token,
        public readonly string $secret,
        public readonly bool $signedIn = false,
    ) {
    }

    /**
     * Build a session from a decoded API/JSON payload.
     *
     * Accepts both the flat shape (`{token, secret}`) and the API envelope
     * shape (`{data: {token, secret}}`) returned by `POST /v1/auth/session`.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = isset($data['data']) && \is_array($data['data']) ? $data['data'] : $data;

        $token = $payload['token'] ?? null;
        $secret = $payload['secret'] ?? null;

        if (!\is_string($token) || '' === $token || !\is_string($secret) || '' === $secret) {
            throw new InvalidArgumentException('Session data must contain a non-empty token and secret.');
        }

        return new self($token, $secret, (bool) ($data['signedIn'] ?? false));
    }

    public function withSignedIn(bool $signedIn): self
    {
        return new self($this->token, $this->secret, $signedIn);
    }

    /**
     * @return array{token: string, secret: string, signedIn: bool}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'secret' => $this->secret,
            'signedIn' => $this->signedIn,
        ];
    }
}
