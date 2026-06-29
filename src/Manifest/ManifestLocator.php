<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * Locates the nearest `llmor.scsc` manifest by walking up from a starting
 * directory — the same discovery strategy {@see \Llmor\Cli\Config\ConfigResolver}
 * uses for the `.llmor` directory.
 */
final class ManifestLocator
{
    public const FILE_NAME = 'llmor.scsc';

    public function __construct(private readonly string $startDir)
    {
    }

    /**
     * Return the absolute path to the nearest manifest, or null when none exists
     * between the start directory and the filesystem root.
     */
    public function locate(): ?string
    {
        $dir = $this->startDir;
        while (true) {
            $candidate = $dir.\DIRECTORY_SEPARATOR.self::FILE_NAME;
            if (\is_file($candidate)) {
                return $candidate;
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }
}
