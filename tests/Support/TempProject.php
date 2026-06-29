<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Creates and tears down a throwaway project directory for manifest/sync tests,
 * with helpers to write nested files.
 */
trait TempProject
{
    private string $projectDir;

    protected function makeProject(): void
    {
        $this->projectDir = \sys_get_temp_dir().'/llmor-proj-'.\uniqid('', true);
        \mkdir($this->projectDir, 0o700, true);
    }

    protected function writeProjectFile(string $relativePath, string $content): string
    {
        $path = $this->projectDir.'/'.$relativePath;
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o700, true);
        }
        \file_put_contents($path, $content);

        return $path;
    }

    protected function removeProject(): void
    {
        if (!isset($this->projectDir) || !\is_dir($this->projectDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir()) {
                @\rmdir($file->getPathname());
            } else {
                @\unlink($file->getPathname());
            }
        }
        @\rmdir($this->projectDir);
    }
}
