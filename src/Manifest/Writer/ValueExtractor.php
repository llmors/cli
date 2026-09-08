<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

use Llmor\Cli\Sync\FunctionLimits;

/**
 * Decides which parameter values are better off in a file of their own.
 *
 * System prompts run to tens of kilobytes and `code` / `output_json_schema` want their
 * own syntax highlighting, which is exactly what `@file('./path')` exists for
 * ({@see \Llmor\Cli\Manifest\FileValueLoader}). A declaration that inlined all of that
 * would be unreadable and undiffable, so anything long or multi-line is written out
 * beside the manifest instead.
 *
 * Only top-level `[parameters]` entries are considered. `@file` works at any depth, but
 * a filename derived from a nested path stops being recognisable, and nested values are
 * rarely the long ones.
 */
final class ValueExtractor
{
    /** Long enough that inlining hurts more than an extra file does. */
    private const MIN_LENGTH = 160;

    /**
     * @param string $directory manifest-relative POSIX directory for extracted files
     */
    public function __construct(
        private readonly string $directory = 'prompts',
        private readonly int $minLength = self::MIN_LENGTH,
    ) {
    }

    /**
     * The manifest-relative path this value should live in, or null to keep it inline.
     * POSIX, with no leading `./` — the `@file` argument adds that.
     *
     * $ordinal past the first asks for a distinct name for the same key, because two
     * keys can sanitise to one filename (`top.p` and `top_p`). The discriminator belongs
     * here rather than at the call site: where it goes depends on the extension, which
     * only this method knows how to pick.
     */
    public function pathFor(string $declaration, string $key, mixed $value, int $ordinal = 1): ?string
    {
        if (!\is_string($value) || !$this->isWorthExtracting($value)) {
            return null;
        }

        return \trim($this->directory, '/').'/'
            .$declaration.'_'.self::sanitise($key)
            .($ordinal > 1 ? '_'.$ordinal : '')
            .self::extension($key);
    }

    private function isWorthExtracting(string $value): bool
    {
        // Above the @file limit the annotation would reject its own output, so such a
        // value has to stay inline however unpleasant that is.
        if (\strlen($value) > FunctionLimits::MAX_FILE_BYTES) {
            return false;
        }

        return \str_contains($value, "\n") || \mb_strlen($value) > $this->minLength;
    }

    private static function sanitise(string $key): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9_-]+/', '_', $key) ?? $key;

        return \trim($safe, '_-') ?: 'value';
    }

    /**
     * Give the file the extension its content deserves, so an editor highlights it and
     * a reviewer can tell a prompt from a schema at a glance.
     */
    private static function extension(string $key): string
    {
        $key = \strtolower($key);

        return match (true) {
            'code' === $key || \str_contains($key, 'lua') => '.lua',
            \str_contains($key, 'json') || \str_contains($key, 'schema') => '.json',
            \str_contains($key, 'css') => '.css',
            \str_contains($key, 'html') => '.html',
            default => '.md',
        };
    }
}
