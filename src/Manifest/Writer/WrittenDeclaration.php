<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

/**
 * One rendered declaration, in both of the forms the importer needs.
 *
 * `$scsc` is what gets appended to the manifest and references its extracted values
 * through `@file`. `$inline` is the same declaration with those values written out
 * literally, which is what makes it possible to verify the emission *before* creating
 * any files — parsing `$scsc` would fail purely because the sources are not there yet.
 */
final class WrittenDeclaration
{
    /**
     * @param array<string, string> $files manifest-relative POSIX path => contents
     * @param list<string>          $notes one per compromise made while emitting
     */
    public function __construct(
        public readonly string $declaration,
        public readonly string $scsc,
        public readonly string $inline,
        public readonly array $files = [],
        public readonly array $notes = [],
    ) {
    }

    public function lineCount(): int
    {
        return \count(\explode("\n", \rtrim($this->scsc, "\n")));
    }
}
