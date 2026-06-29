<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A scriptable llmor API for functional tests.
 *
 * The auth handshake (`/v1/auth/session` + `/v1/auth/signin`) is answered
 * automatically; register handlers for everything else with {@see on()}. Every
 * request is recorded (method, path, decoded JSON body) for assertions.
 */
final class FakeLlmorApi
{
    /** @var list<array{method: string, path: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    /**
     * @param callable(string $method, string $path, array<string, mixed> $body): array{0: int, 1: array<string, mixed>} $handler
     */
    public function on(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];

        return $this;
    }

    public function client(): MockHttpClient
    {
        $factory = function (string $method, string $url, array $options): MockResponse {
            $path = (string) \parse_url($url, \PHP_URL_PATH);
            $body = $this->decodeBody($options);
            $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

            if (\str_ends_with($path, '/v1/auth/session')) {
                return $this->json(['token' => 'tok', 'secret' => 'sec']);
            }
            if (\str_ends_with($path, '/v1/auth/signin')) {
                return $this->json(['data' => ['id' => 1, 'email' => 'admin@test.llmor']]);
            }

            foreach ($this->routes as $route) {
                if ($route['method'] === $method && 1 === \preg_match($route['pattern'], $path)) {
                    [$status, $payload] = ($route['handler'])($method, $path, $body);

                    return $this->json($payload, $status);
                }
            }

            return $this->json(['message' => 'Not found: '.$method.' '.$path], 404);
        };

        return new MockHttpClient($factory, 'https://api.test');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse((string) \json_encode($body), ['http_code' => $status]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function decodeBody(array $options): array
    {
        $body = $options['body'] ?? null;
        if (!\is_string($body) || '' === $body) {
            return [];
        }

        $decoded = \json_decode($body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
