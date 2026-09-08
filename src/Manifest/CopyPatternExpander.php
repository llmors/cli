<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * Expands a wildcard `[copy]` source into the concrete files it matches.
 *
 * A pattern keeps the matched file's path **relative to its own fixed prefix** — the
 * leading directory portion before the first wildcard — rather than collapsing to a
 * basename the way a literal source does. That is what lets one recursive pattern
 * mirror a whole documentation tree without same-basename files colliding:
 * `docs/books/silicon/**\/*.md` under `@path('docs/book/')` puts `data/dql.md` at
 * `docs/book/data/dql.md` and the tree's own `index.md` at `docs/book/index.md`.
 *
 * Wildcards follow shell conventions: `*` and `?` stay inside one path segment,
 * `**` crosses segments. Only regular files match, and dot-entries are skipped at
 * every level (so a stray `.DS_Store` or a `.git` directory never joins a bundle).
 */
final class CopyPatternExpander
{
    /** Whether a `[copy]` source is a wildcard pattern rather than a literal path. */
    public static function isPattern(string $source): bool
    {
        return \str_contains($source, '*') || \str_contains($source, '?');
    }

    /**
     * Resolve a pattern against `$baseDir`.
     *
     * @return array<string, string> absolute source path => path relative to the
     *                               pattern's fixed prefix (POSIX, no leading slash),
     *                               ordered by that relative path
     */
    public static function expand(string $baseDir, string $pattern): array
    {
        $normalized = \str_replace('\\', '/', $pattern);
        [$fixedPrefix, $remainder] = self::split($normalized);

        $rootPath = self::absolutize($baseDir, $fixedPrefix);
        if (!\is_dir($rootPath)) {
            return [];
        }

        $regex = '#^'.self::toRegex($remainder).'$#';
        $matches = [];

        foreach (self::walk($rootPath, self::maxDepth($remainder)) as $relative => $absolute) {
            if (1 === \preg_match($regex, $relative)) {
                $matches[$absolute] = $relative;
            }
        }

        \asort($matches);

        return $matches;
    }

    /**
     * Split a pattern into its wildcard-free leading directory and the matchable
     * remainder, e.g. `./res/books/**\/*.md` => ['./res/books', '**\/*.md'].
     *
     * @return array{string, string}
     */
    private static function split(string $pattern): array
    {
        $segments = \explode('/', $pattern);
        $fixed = [];

        while ([] !== $segments && !self::isPattern($segments[0])) {
            $fixed[] = \array_shift($segments);
        }

        return [\implode('/', $fixed), \implode('/', $segments)];
    }

    /**
     * Compile the wildcard remainder into a regular expression. `**` followed by a
     * separator also matches *no* directory at all, so a recursive pattern covers the
     * files sitting directly in its root as well as those nested below it.
     */
    private static function toRegex(string $remainder): string
    {
        $out = '';
        $length = \strlen($remainder);

        for ($i = 0; $i < $length; ++$i) {
            $char = $remainder[$i];

            if ('*' === $char) {
                if ($i + 1 < $length && '*' === $remainder[$i + 1]) {
                    if ($i + 2 < $length && '/' === $remainder[$i + 2]) {
                        $out .= '(?:[^/]+/)*';
                        $i += 2;
                    } else {
                        $out .= '.*';
                        ++$i;
                    }
                } else {
                    $out .= '[^/]*';
                }

                continue;
            }

            $out .= '?' === $char ? '[^/]' : \preg_quote($char, '#');
        }

        return $out;
    }

    /**
     * How many path segments the remainder can ever match. `**` crosses segments, so
     * it lifts the bound entirely; without one, a pattern like `docs/*` can only match
     * at a known depth and everything below that is not worth scanning — which matters
     * when a pattern is rooted next to something like `vendor/`.
     */
    private static function maxDepth(string $remainder): int
    {
        if (\str_contains($remainder, '**')) {
            return \PHP_INT_MAX;
        }

        return \substr_count($remainder, '/') + 1;
    }

    /**
     * Recursively list the regular files under `$rootPath`, skipping dot-entries and
     * never descending deeper than `$maxDepth` segments.
     *
     * @return array<string, string> path relative to `$rootPath` => absolute path
     */
    private static function walk(string $rootPath, int $maxDepth): array
    {
        $found = [];
        /** @var list<array{string, int}> $queue directory relative to the root, its depth */
        $queue = [['', 1]];

        while ([] !== $queue) {
            [$relativeDir, $depth] = \array_shift($queue);
            $absoluteDir = '' === $relativeDir ? $rootPath : $rootPath.'/'.$relativeDir;

            $entries = @\scandir($absoluteDir);
            if (false === $entries) {
                continue;
            }

            foreach ($entries as $entry) {
                if (\str_starts_with($entry, '.')) {
                    continue;
                }

                $relative = '' === $relativeDir ? $entry : $relativeDir.'/'.$entry;
                $absolute = $absoluteDir.'/'.$entry;

                if (\is_dir($absolute)) {
                    if ($depth < $maxDepth) {
                        $queue[] = [$relative, $depth + 1];
                    }
                } elseif (\is_file($absolute)) {
                    $found[$relative] = $absolute;
                }
            }
        }

        return $found;
    }

    private static function absolutize(string $baseDir, string $path): string
    {
        // drop no-op "." segments so the resolved root reads as a plain path
        $path = \preg_replace('#(?:^\./)|(?:/\.(?=/|$))#', '', \rtrim($path, '/')) ?? '';
        $path = \rtrim($path, '/');

        if ('' === $path) {
            return \rtrim($baseDir, '/');
        }

        if ('/' === $path[0] || 1 === \preg_match('#^[A-Za-z]:/#', $path)) {
            return $path;
        }

        return \rtrim($baseDir, '/').'/'.\ltrim($path, '/');
    }
}
