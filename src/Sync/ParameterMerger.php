<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use stdClass;

/**
 * Merges declared app parameters over the remote bag, and decides whether that
 * actually changed anything.
 *
 * `[parameters]` are **overrides**, not the whole truth. The server seeds an app
 * type's defaults (and its model's runtime knobs) at creation, and the console can
 * set fields no manifest mentions, so a manifest declares only what it cares about.
 * The flip side, which is documented: removing a key from the manifest does not
 * reset it.
 *
 * Two rules make that predictable:
 *
 * - **Maps merge, lists and scalars replace.** `extra_body` is a map and merging it
 *   is what you want; `examples` and `context_datastores` are lists, where merging
 *   by index means nothing and appending would grow the list on every run.
 * - **Comparison is structural, never `json_encode`.** Key order and float
 *   formatting differ across a JSON round trip, and a text-column bag can hand back
 *   `"0.2"` where we sent `0.2`. Comparing encoded strings would produce a PUT on
 *   every single run.
 */
final class ParameterMerger
{
    /** Relative tolerance for float comparison, so 0.2 doesn't differ from itself. */
    private const EPSILON = 1.0e-9;

    /**
     * A parameter bag as an object, whatever the API handed back.
     *
     * `parameters` is a JSON object by contract, but an empty one decodes to `[]` in
     * PHP. Both sides of a comparison have to agree on that or an app with no declared
     * parameters would look changed on every single run.
     */
    public static function bag(mixed $value): stdClass
    {
        $normalized = self::normalize($value);

        return $normalized instanceof stdClass ? $normalized : new stdClass();
    }

    /**
     * Deep-merge declared values over the remote bag.
     */
    public static function merge(mixed $remote, stdClass $declared): stdClass
    {
        return self::mergeInto(self::bag($remote), $declared);
    }

    private static function mergeInto(stdClass $base, stdClass $declared): stdClass
    {
        $merged = clone $base;

        foreach (\get_object_vars($declared) as $key => $value) {
            $existing = $merged->{$key} ?? null;

            $merged->{$key} = $existing instanceof stdClass && $value instanceof stdClass
                ? self::mergeInto($existing, $value)
                : $value;
        }

        return $merged;
    }

    /**
     * Recast a decoded payload so maps are objects and lists are lists — the same
     * shape {@see \Llmor\Cli\Manifest\ParameterTree} produces, so the two can be
     * compared and merged without special cases.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = \get_object_vars($value);
            $map = new stdClass();
            foreach ($value as $key => $item) {
                $map->{$key} = self::normalize($item);
            }

            return $map;
        }

        if (!\is_array($value)) {
            return $value;
        }

        if (\array_is_list($value)) {
            return \array_map(static fn (mixed $item): mixed => self::normalize($item), $value);
        }

        $map = new stdClass();
        foreach ($value as $key => $item) {
            $map->{(string) $key} = self::normalize($item);
        }

        return $map;
    }

    /**
     * Structural equality, lenient about how JSON round trips numbers.
     */
    public static function equals(mixed $a, mixed $b): bool
    {
        if ($a instanceof stdClass && $b instanceof stdClass) {
            $left = \get_object_vars($a);
            $right = \get_object_vars($b);

            if (\count($left) !== \count($right)) {
                return false;
            }

            foreach ($left as $key => $value) {
                if (!\array_key_exists($key, $right) || !self::equals($value, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }

            foreach ($a as $index => $value) {
                if (!\array_key_exists($index, $b) || !self::equals($value, $b[$index])) {
                    return false;
                }
            }

            return true;
        }

        if (\is_bool($a) || \is_bool($b) || null === $a || null === $b) {
            return $a === $b;
        }

        if (self::isNumeric($a) && self::isNumeric($b)) {
            return self::numbersEqual((float) $a, (float) $b);
        }

        return \is_string($a) && \is_string($b) && $a === $b;
    }

    /**
     * The dotted paths whose values differ, for the report line. Because the merged
     * bag is the remote bag plus declared overrides, every difference found here is
     * something the manifest asked for.
     *
     * @return list<string>
     */
    public static function changedPaths(mixed $remote, mixed $merged, string $prefix = ''): array
    {
        if (!$merged instanceof stdClass) {
            return self::equals($remote, $merged) ? [] : [$prefix];
        }

        $before = $remote instanceof stdClass ? \get_object_vars($remote) : [];
        $paths = [];

        foreach (\get_object_vars($merged) as $key => $value) {
            $path = '' === $prefix ? $key : $prefix.'.'.$key;

            if (!\array_key_exists($key, $before)) {
                $paths[] = $path;
                continue;
            }

            foreach (self::changedPaths($before[$key], $value, $path) as $nested) {
                $paths[] = $nested;
            }
        }

        return $paths;
    }

    private static function isNumeric(mixed $value): bool
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && \is_numeric($value));
    }

    private static function numbersEqual(float $a, float $b): bool
    {
        $scale = \max(1.0, \abs($a), \abs($b));

        return \abs($a - $b) <= self::EPSILON * $scale;
    }
}
