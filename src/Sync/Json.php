<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * Narrowing helpers for decoded API payloads.
 *
 * API responses are `mixed` all the way down, and static analysis rightly refuses to
 * let us index into them. This is the codebase's existing defensive-cast idiom —
 * `(int) ($item['id'] ?? 0)` — factored into one place so nothing else has to guess.
 */
final class Json
{
    /**
     * @return array<string, mixed>
     */
    public static function mapOf(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    public static function listOf(mixed $value): array
    {
        return \is_array($value) ? \array_values($value) : [];
    }

    public static function stringOf(mixed $value, string $default = ''): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? 'true' : 'false',
            default => $default,
        };
    }

    public static function intOf(mixed $value, int $default = 0): int
    {
        return match (true) {
            \is_int($value) => $value,
            \is_float($value) => (int) $value,
            \is_string($value) && 1 === \preg_match('/^-?[0-9]+$/', $value) => (int) $value,
            default => $default,
        };
    }

    /** A positive id, or null when the payload carries none. */
    public static function idOf(mixed $value): ?int
    {
        $id = self::intOf($value);

        return $id > 0 ? $id : null;
    }
}
