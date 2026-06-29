<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * A local source file destined to become a `VendorFunctionFile`. The path is the
 * function-relative virtual path (POSIX separators) the runtime reads via `file()`.
 */
final class LocalFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $content,
        public readonly string $sha256,
        public readonly int $byteSize,
    ) {
    }
}
