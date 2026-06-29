<?php

declare(strict_types=1);

namespace Llmor\Cli\Auth;

use InvalidArgumentException;
use JsonException;
use Llmor\Cli\Client\Exception\AuthenticationException;
use Llmor\Cli\Config\Configuration;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Owns the session lifecycle against the llmor auth endpoints.
 *
 * Flow:
 *   - {@see current()} returns a persisted signed-in session, creating one if
 *     absent.
 *   - {@see authenticate()} performs the two-step handshake: create an
 *     anonymous session (`POST /v1/auth/session`), then upgrade it to a
 *     signed-in session (`POST /v1/auth/signin`, signed with that session).
 *   - {@see reset()} discards the stored session and authenticates afresh — the
 *     client calls this once when a request comes back 401.
 */
final class SessionManager
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly AccessTokenSigner $signer,
        private readonly SessionStore $store,
        private readonly Configuration $config,
    ) {
    }

    /**
     * Return a usable signed-in session, authenticating if none is stored.
     */
    public function current(): Session
    {
        $session = $this->store->load();
        if (null !== $session && $session->signedIn) {
            return $session;
        }

        return $this->authenticate();
    }

    /**
     * Discard any stored session and authenticate from scratch.
     */
    public function reset(): Session
    {
        $this->store->clear();

        return $this->authenticate();
    }

    private function authenticate(): Session
    {
        if (!$this->config->hasCredentials()) {
            throw AuthenticationException::missingCredentials();
        }

        $session = $this->createAnonymousSession();
        $this->signIn($session);

        $session = $session->withSignedIn(true);
        $this->store->save($session);

        return $session;
    }

    private function createAnonymousSession(): Session
    {
        $body = $this->send('POST', '/v1/auth/session', null, null);

        try {
            return Session::fromArray($body);
        } catch (InvalidArgumentException $e) {
            throw new AuthenticationException('Unexpected session response from the API.', 0, $body, $e);
        }
    }

    private function signIn(Session $session): void
    {
        $status = 0;
        $body = $this->send(
            'POST',
            '/v1/auth/signin',
            $session,
            [
                'identifier' => (string) $this->config->identifier,
                'secret' => (string) $this->config->secret,
            ],
            $status,
        );

        if ($status >= 400) {
            $message = \is_string($body['message'] ?? null) && '' !== $body['message']
                ? $body['message']
                : 'Sign-in failed.';

            throw new AuthenticationException($message, $status, $body);
        }
    }

    /**
     * Perform a JSON request against an auth endpoint, optionally signed.
     *
     * @param array<string, mixed>|null $json
     *
     * @param-out int                   $status
     *
     * @return array<string, mixed> decoded response body
     */
    private function send(string $method, string $path, ?Session $session, ?array $json, int &$status = 0): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (null !== $session) {
            $headers['X-AccessToken'] = $this->signer->sign($method, $path, $session, \time());
        }

        $options = ['headers' => $headers];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $this->config->host.$path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new AuthenticationException('Unable to reach the llmor API: '.$e->getMessage(), 0, [], $e);
        }

        if ('' === $content) {
            return [];
        }

        try {
            $decoded = \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AuthenticationException('Malformed JSON response from the auth API.', $status, [], $e);
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
