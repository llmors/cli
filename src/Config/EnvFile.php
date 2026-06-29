<?php

declare(strict_types=1);

namespace Llmor\Cli\Config;

use RuntimeException;

/**
 * Minimal reader/writer for the `KEY=value` `.llmor/.env` credential file.
 *
 * Intentionally dependency-free (no symfony/dotenv) to keep the static binary
 * small and the parsing behaviour fully under our control.
 */
final class EnvFile
{
    /**
     * Parse an env file into an associative array. Missing files yield `[]`.
     *
     * @return array<string, string>
     */
    public static function parse(string $path): array
    {
        if (!\is_file($path)) {
            return [];
        }

        $lines = \file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ('' === $line || \str_starts_with($line, '#')) {
                continue;
            }

            $pos = \strpos($line, '=');
            if (false === $pos) {
                continue;
            }

            $key = \trim(\substr($line, 0, $pos));
            if ('' === $key) {
                continue;
            }

            $values[$key] = self::unquote(\trim(\substr($line, $pos + 1)));
        }

        return $values;
    }

    /**
     * Write the given values to an env file (0600), creating the directory if needed.
     *
     * @param array<string, string> $values
     */
    public static function write(string $path, array $values): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o700, true) && !\is_dir($dir)) {
            throw new RuntimeException(\sprintf('Unable to create directory "%s".', $dir));
        }

        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = $key.'='.self::quote($value);
        }

        $content = \implode("\n", $lines)."\n";
        if (false === \file_put_contents($path, $content)) {
            throw new RuntimeException(\sprintf('Unable to write env file "%s".', $path));
        }

        @\chmod($path, 0o600);
    }

    private static function unquote(string $value): string
    {
        if (\strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[\strlen($value) - 1];
            if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                return \substr($value, 1, -1);
            }
        }

        return $value;
    }

    private static function quote(string $value): string
    {
        if ('' === $value || 1 === \preg_match('/[\s#"\']/', $value)) {
            return '"'.\str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }
}
