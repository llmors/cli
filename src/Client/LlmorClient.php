<?php

declare(strict_types=1);

namespace Llmor\Cli\Client;

use JsonException;
use Llmor\Cli\Auth\AccessTokenSigner;
use Llmor\Cli\Auth\Session;
use Llmor\Cli\Auth\SessionManager;
use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\Exception\AuthenticationException;
use Llmor\Cli\Client\Exception\ValidationException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * High-level, authenticated gateway to the llmor API.
 *
 * Every request is signed per-call with the current session's secret. A 401
 * triggers a single transparent re-authentication (new session + sign-in) and
 * one retry, after which the error is surfaced. Non-2xx responses are mapped to
 * typed exceptions.
 */
final class LlmorClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SessionManager $sessions,
        private readonly AccessTokenSigner $signer,
        private readonly string $host,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param array<string, mixed>       $json
     * @param array<string, scalar|null> $query
     */
    public function post(string $path, array $json = [], array $query = []): ApiResponse
    {
        return $this->request('POST', $path, $query, $json);
    }

    /**
     * @param array<string, mixed>       $json
     * @param array<string, scalar|null> $query
     */
    public function put(string $path, array $json = [], array $query = []): ApiResponse
    {
        return $this->request('PUT', $path, $query, $json);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function delete(string $path, array $query = []): ApiResponse
    {
        return $this->request('DELETE', $path, $query);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $json
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): ApiResponse
    {
        $method = \strtoupper($method);
        $requestUri = $this->buildRequestUri($path, $query);

        $session = $this->sessions->current();
        [$status, $body] = $this->dispatch($method, $requestUri, $session->secret, $session->token, $json);

        // A 401 means the session was rejected — re-authenticate once and retry.
        if (401 === $status) {
            $session = $this->sessions->reset();
            [$status, $body] = $this->dispatch($method, $requestUri, $session->secret, $session->token, $json);
        }

        if ($status >= 200 && $status < 300) {
            return new ApiResponse($status, $body);
        }

        throw $this->mapError($status, $body);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function dispatch(string $method, string $requestUri, string $secret, string $token, ?array $json): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-AccessToken' => $this->signer->sign(
                $method,
                $requestUri,
                new Session($token, $secret, true),
                \time(),
            ),
        ];

        $options = ['headers' => $headers];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $this->host.$requestUri, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new ApiException('Unable to reach the llmor API: '.$e->getMessage(), 0, [], $e);
        }

        return [$status, $this->decode($content, $status)];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $content, int $status): array
    {
        if ('' === $content) {
            return [];
        }

        try {
            $decoded = \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('Malformed JSON response from the API.', $status, [], $e);
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mapError(int $status, array $body): ApiException
    {
        return match (true) {
            401 === $status => new AuthenticationException(
                \is_string($body['message'] ?? null) ? $body['message'] : 'Authentication failed.',
                $status,
                $body,
            ),
            422 === $status => ValidationException::fromResponse($status, $body),
            default => ApiException::fromResponse($status, $body),
        };
    }

    /**
     * Build the path + query string exactly as it will be sent on the wire, so
     * the signed URI matches the server's `getRequestUri()`.
     *
     * @param array<string, scalar|null> $query
     */
    private function buildRequestUri(string $path, array $query): string
    {
        $query = \array_filter($query, static fn ($value): bool => null !== $value);
        if ([] === $query) {
            return $path;
        }

        return $path.'?'.\http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }
}
