<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Builder;

use Llmor\Cli\Manifest\ManifestException;

/**
 * Everything a declaration builder needs to know about *where* it is: which kind of
 * declaration it is building, under what name, from which manifest, and the directory
 * relative paths resolve against.
 *
 * It also owns the error vocabulary. SchemaScript's `MetadataEntryNode`s carry no
 * source position, so a message can never point at a line — it has to name the
 * declaration and the offending key well enough to be self-locating.
 */
final class DeclarationContext
{
    /** Mirrors the server-side `function_key` validation; also used for app declaration names. */
    public const KEY_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * @param string $kind    the declared parent type, lowercased ('function', 'app')
     * @param string $key     the declaration name
     * @param string $path    absolute path of the manifest being parsed
     * @param string $baseDir directory that relative paths resolve against
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly string $path,
        public readonly string $baseDir,
    ) {
    }

    /**
     * @throws ManifestException when the declaration name is not a usable key
     */
    public function assertValidKey(): void
    {
        if (1 !== \preg_match(self::KEY_PATTERN, $this->key)) {
            throw $this->invalid('the declaration name must match /^[a-zA-Z_][a-zA-Z0-9_]*$/');
        }
    }

    public function invalid(string $reason): ManifestException
    {
        return new ManifestException(\sprintf('Invalid %s "%s" in manifest "%s": %s.', $this->kind, $this->key, $this->path, $reason));
    }

    /**
     * Require a non-empty string metadata value (an empty string counts as missing).
     *
     * @param array<string, string> $meta
     *
     * @throws ManifestException
     */
    public function require(array $meta, string $field): string
    {
        $value = $meta[$field] ?? '';
        if ('' === $value) {
            throw $this->invalid(\sprintf('[%s] is required and must be a non-empty string', $field));
        }

        return $value;
    }

    /**
     * Resolve a manifest-relative path against the manifest directory, leaving
     * absolute paths (POSIX and Windows-drive) untouched.
     */
    public function resolvePath(string $path): string
    {
        $path = \rtrim($path, '/\\');

        if ('' !== $path && ('/' === $path[0] || 1 === \preg_match('/^[A-Za-z]:[\\\\\/]/', $path))) {
            return $path;
        }

        return \rtrim($this->baseDir, '/\\').\DIRECTORY_SEPARATOR.\ltrim($path, '/\\');
    }
}
