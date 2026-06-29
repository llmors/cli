<?php

declare(strict_types=1);

namespace Llmor\Cli\Auth;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Persists the active {@see Session} to `.llmor/session.json` with `0600`
 * permissions. Reads are defensive: a missing, empty or malformed file simply
 * yields `null` so the caller re-authenticates from scratch.
 */
final class SessionStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function load(): ?Session
    {
        if (!\is_file($this->path)) {
            return null;
        }

        $raw = \file_get_contents($this->path);
        if (false === $raw || '' === $raw) {
            return null;
        }

        try {
            $data = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        try {
            /* @var array<string, mixed> $data */
            return Session::fromArray($data);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function save(Session $session): void
    {
        $dir = \dirname($this->path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o700, true) && !\is_dir($dir)) {
            throw new RuntimeException(\sprintf('Unable to create config directory "%s".', $dir));
        }

        $json = \json_encode($session->toArray(), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        if (false === \file_put_contents($this->path, $json)) {
            throw new RuntimeException(\sprintf('Unable to write session file "%s".', $this->path));
        }

        @\chmod($this->path, 0o600);
    }

    public function clear(): void
    {
        if (\is_file($this->path)) {
            @\unlink($this->path);
        }
    }
}
