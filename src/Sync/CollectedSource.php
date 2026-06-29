<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * The outcome of walking a function's `srcdir`: the files that will be synced and
 * any per-file warnings (e.g. a binary file that cannot be stored as text).
 */
final class CollectedSource
{
    /**
     * @param list<LocalFile> $files
     * @param list<string>    $warnings
     */
    public function __construct(
        public readonly array $files,
        public readonly array $warnings,
    ) {
    }
}
