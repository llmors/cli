<?php

declare(strict_types=1);

namespace Llmor\Cli\Config;

/**
 * Immutable, resolved configuration for a single CLI invocation.
 *
 * Holds the API host, the (optional) login credentials and the path to the
 * resolved `.llmor` directory where the session is persisted.
 */
final class Configuration
{
    public function __construct(
        public readonly string $host,
        public readonly ?string $identifier,
        public readonly ?string $secret,
        public readonly ?string $vendor,
        public readonly string $directory,
    ) {
    }

    public function hasCredentials(): bool
    {
        return null !== $this->identifier && '' !== $this->identifier
            && null !== $this->secret && '' !== $this->secret;
    }

    /**
     * Absolute path to the persisted session file inside the `.llmor` directory.
     */
    public function sessionFile(): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.'session.json';
    }

    /**
     * Absolute path to the credentials env file inside the `.llmor` directory.
     */
    public function envFile(): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.'.env';
    }
}
