<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use FilesystemIterator;
use Llmor\Cli\Manifest\FunctionDefinition;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Walks a function's `srcdir` and collects the auxiliary files to sync.
 *
 * The entry file is excluded — it becomes the function's `code`, not a file record.
 * Files are keyed by their `srcdir`-relative POSIX path (the virtual path the runtime
 * reads via `file('path')`). `[copy]` directives append files pulled in from outside
 * `srcdir` at their declared destination. Binary (non-UTF-8) files are skipped with a
 * warning, since file content travels as a JSON string; bundle limits raise a
 * {@see SyncException}.
 */
final class LocalSourceCollector
{
    public function collect(FunctionDefinition $function): CollectedSource
    {
        $root = \realpath($function->srcdirPath);
        $entry = \realpath($function->entryPath);
        if (false === $root) {
            throw new SyncException(\sprintf('Source directory "%s" disappeared.', $function->srcdirPath));
        }

        $files = [];
        $warnings = [];
        $totalBytes = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile()) {
                continue;
            }

            $absolute = $info->getPathname();
            if (false !== $entry && \realpath($absolute) === $entry) {
                continue; // the entry file is the function's code, not an aux file
            }

            $relative = $this->relativePath($root, $absolute);
            $content = @\file_get_contents($absolute);
            if (false === $content) {
                $warnings[] = \sprintf('Skipped "%s": cannot read file.', $relative);
                continue;
            }

            $byteSize = \strlen($content);
            if ($byteSize > FunctionLimits::MAX_FILE_BYTES) {
                throw new SyncException(\sprintf('File "%s" is %d bytes, exceeding the %d byte per-file limit.', $relative, $byteSize, FunctionLimits::MAX_FILE_BYTES));
            }

            if (!\mb_check_encoding($content, 'UTF-8')) {
                $warnings[] = \sprintf('Skipped "%s": not valid UTF-8 text.', $relative);
                continue;
            }

            $totalBytes += $byteSize;
            $files[] = new LocalFile($relative, $content, \hash('sha256', $content), $byteSize);
        }

        $srcPaths = [];
        foreach ($files as $file) {
            $srcPaths[$file->path] = true;
        }

        foreach ($function->copies as $copy) {
            if (isset($srcPaths[$copy->destination])) {
                throw new SyncException(\sprintf('Copy destination "%s" collides with a file already under srcdir.', $copy->destination));
            }

            $content = @\file_get_contents($copy->sourcePath);
            if (false === $content) {
                $warnings[] = \sprintf('Skipped copy "%s": cannot read source "%s".', $copy->destination, $copy->sourcePath);
                continue;
            }

            $byteSize = \strlen($content);
            if ($byteSize > FunctionLimits::MAX_FILE_BYTES) {
                throw new SyncException(\sprintf('Copy "%s" is %d bytes, exceeding the %d byte per-file limit.', $copy->destination, $byteSize, FunctionLimits::MAX_FILE_BYTES));
            }

            if (!\mb_check_encoding($content, 'UTF-8')) {
                $warnings[] = \sprintf('Skipped copy "%s": not valid UTF-8 text.', $copy->destination);
                continue;
            }

            $totalBytes += $byteSize;
            $files[] = new LocalFile($copy->destination, $content, \hash('sha256', $content), $byteSize);
        }

        if (\count($files) > FunctionLimits::MAX_FILES) {
            throw new SyncException(\sprintf('%d files exceed the %d file limit per function.', \count($files), FunctionLimits::MAX_FILES));
        }
        if ($totalBytes > FunctionLimits::MAX_BUNDLE_BYTES) {
            throw new SyncException(\sprintf('Source bundle is %d bytes, exceeding the %d byte limit.', $totalBytes, FunctionLimits::MAX_BUNDLE_BYTES));
        }

        \usort($files, static fn (LocalFile $a, LocalFile $b): int => \strcmp($a->path, $b->path));

        return new CollectedSource($files, $warnings);
    }

    private function relativePath(string $root, string $absolute): string
    {
        $relative = \substr($absolute, \strlen($root) + 1);

        return \str_replace(\DIRECTORY_SEPARATOR, '/', $relative);
    }
}
