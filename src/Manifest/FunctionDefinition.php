<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * A single `: Function` declaration resolved from an `llmor.scsc` manifest.
 *
 * The declaration name becomes the remote `function_key`. The entry file's contents
 * become the function's `code`; every other file under `srcdir` — plus any files
 * pulled in by a `[copy]` directive — is synced as an auxiliary function file
 * (see {@see \Llmor\Cli\Sync\FunctionSynchronizer}).
 */
final class FunctionDefinition
{
    /**
     * @param list<CopyInstruction> $copies
     */
    public function __construct(
        public readonly string $functionKey,
        public readonly string $name,
        public readonly string $description,
        public readonly string $runtime,
        public readonly string $srcdir,
        public readonly string $entry,
        public readonly string $srcdirPath,
        public readonly string $entryPath,
        public readonly array $copies = [],
    ) {
    }

    /**
     * Read the entry file's contents — the function's `code`.
     *
     * @throws ManifestException when the entry file cannot be read
     */
    public function readCode(): string
    {
        $code = @\file_get_contents($this->entryPath);
        if (false === $code) {
            throw new ManifestException(\sprintf('Cannot read entry file "%s" for function "%s".', $this->entryPath, $this->functionKey));
        }

        return $code;
    }
}
