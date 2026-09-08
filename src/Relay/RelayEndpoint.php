<?php

declare(strict_types=1);

namespace Llmor\Cli\Relay;

/**
 * Where the conversation relay lives, as the API reports it.
 *
 * A conversation record carries `relay_url` (`https://relay.llmor.com` in production,
 * `http://localhost:2053` otherwise) and `relay_path` (`/stream`). This turns that pair
 * into what a socket needs: a stream transport, a host, a port and the Socket.IO path.
 *
 * The scheme decides the transport — `https`/`wss` become `tls://`, anything else
 * `tcp://` — because the relay is reached with a raw stream socket, not an HTTP client.
 */
final class RelayEndpoint
{
    private function __construct(
        public readonly string $transport,
        public readonly string $host,
        public readonly int $port,
        public readonly string $path,
        public readonly bool $secure,
    ) {
    }

    /**
     * @throws RelayException when the URL has no usable host
     */
    public static function fromUrl(string $url, string $path = '/stream'): self
    {
        $parts = \parse_url(\trim($url));
        if (false === $parts || !isset($parts['host']) || '' === $parts['host']) {
            throw new RelayException(\sprintf('Unusable relay URL "%s".', $url));
        }

        $scheme = \strtolower($parts['scheme'] ?? 'http');
        $secure = \in_array($scheme, ['https', 'wss'], true);

        return new self(
            $secure ? 'tls' : 'tcp',
            $parts['host'],
            $parts['port'] ?? ($secure ? 443 : 80),
            self::normalisePath($path),
            $secure,
        );
    }

    /**
     * Read the endpoint off a conversation record, honouring an explicit override.
     *
     * The override exists for local development: a server that hands out
     * `http://localhost:2053` is describing *its own* host, which is not where the CLI
     * is running when it talks to a remote box.
     *
     * @param array<string, mixed> $conversation
     *
     * @throws RelayException when the record names no relay and none was given
     */
    public static function fromConversation(array $conversation, ?string $override = null): self
    {
        $path = $conversation['relay_path'] ?? '/stream';
        $path = \is_string($path) && '' !== $path ? $path : '/stream';

        if (null !== $override && '' !== $override) {
            return self::fromUrl($override, $path);
        }

        $url = $conversation['relay_url'] ?? null;
        if (!\is_string($url) || '' === $url) {
            throw new RelayException('The conversation record carries no relay_url.');
        }

        return self::fromUrl($url, $path);
    }

    /**
     * The `host:port` pair `stream_socket_client()` connects to.
     */
    public function socketAddress(): string
    {
        return \sprintf('%s://%s:%d', $this->transport, $this->host, $this->port);
    }

    /**
     * The `Host:` header value — the port is omitted when it is the scheme's default,
     * which is what a browser would send.
     */
    public function hostHeader(): string
    {
        $default = $this->secure ? 443 : 80;

        return $this->port === $default ? $this->host : \sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * The Engine.IO request target. Socket.IO always appends a trailing slash to its
     * path before the query string, so `/stream` is requested as `/stream/?EIO=4…`.
     */
    public function requestUri(): string
    {
        return $this->path.'/?EIO=4&transport=websocket';
    }

    public function __toString(): string
    {
        return \sprintf('%s://%s%s', $this->secure ? 'https' : 'http', $this->hostHeader(), $this->path);
    }

    private static function normalisePath(string $path): string
    {
        $path = '/'.\ltrim(\trim($path), '/');

        return '/' === $path ? '/socket.io' : \rtrim($path, '/');
    }
}
