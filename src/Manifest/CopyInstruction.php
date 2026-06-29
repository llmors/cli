<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * A single `[copy]` directive: pull a local file (outside `srcdir`) into the
 * function's file set at a chosen path.
 *
 * Sources resolve relative to the manifest directory (the same base as `srcdir`);
 * the destination is the function-relative POSIX path the file lands at — the
 * `@path('dir/')` annotation's directory joined with the source's basename.
 */
final class CopyInstruction
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $destination,
    ) {
    }
}
