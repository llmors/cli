<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

use Llmor\Cli\Manifest\ManifestException;

/**
 * Adds a declaration to the end of an `llmor.scsc`, without disturbing what is
 * already there.
 *
 * A manifest is hand-written and hand-maintained: its comments, alignment and blank
 * lines are the author's, and a tool that reformatted them on the way past would be
 * unusable. So this deliberately does not rewrite the file — it concatenates. The only
 * thing normalised is the run of newlines at the very end, collapsed to exactly one
 * blank line before the appended block, which is invisible in a diff.
 *
 * The write itself mirrors {@see \Llmor\Cli\Sync\AppLockFile}: a temp file in the same
 * directory followed by `rename()`, so an interrupted run cannot leave a half-written
 * manifest. The bytes read at construction are re-checked immediately before the
 * rename, so a concurrent edit is refused rather than clobbered.
 */
final class ManifestAppender
{
    private function __construct(
        public readonly string $path,
        private readonly string $original,
    ) {
    }

    /**
     * @throws ManifestException when the file exists but cannot be read
     */
    public static function at(string $path): self
    {
        if (!\is_file($path)) {
            return new self($path, '');
        }

        $original = @\file_get_contents($path);
        if (false === $original) {
            throw new ManifestException(\sprintf('Cannot read manifest "%s".', $path));
        }

        return new self($path, $original);
    }

    public function exists(): bool
    {
        return '' !== $this->original;
    }

    public function directory(): string
    {
        return \dirname($this->path);
    }

    /** The full text the manifest would have once the declaration is appended. */
    public function compose(string $declaration): string
    {
        $eol = \str_contains($this->original, "\r\n") ? "\r\n" : "\n";
        $body = \rtrim($this->original, "\r\n");

        return '' === $body
            ? $declaration.$eol
            : $body.$eol.$eol.$declaration.$eol;
    }

    /**
     * @throws ManifestException
     */
    public function append(string $declaration): void
    {
        $text = $this->compose($declaration);

        // Whatever we verified is what we must be appending to. Anything else means
        // someone edited the manifest while we were talking to the API.
        $current = \is_file($this->path) ? @\file_get_contents($this->path) : '';
        if (($current ?: '') !== $this->original) {
            throw new ManifestException(\sprintf('"%s" changed while the app was being imported — nothing was written. Run the command again.', $this->path));
        }

        $temp = $this->path.'.tmp';
        if (false === @\file_put_contents($temp, $text)) {
            throw new ManifestException(\sprintf('Cannot write "%s".', $temp));
        }

        if (!@\rename($temp, $this->path)) {
            @\unlink($temp);

            throw new ManifestException(\sprintf('Cannot write "%s".', $this->path));
        }
    }
}
