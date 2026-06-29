<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * Turns the API's raw per-field `errors` map into something a human can act on.
 *
 * The server's map is hostile to read directly: valid fields appear as empty
 * arrays, failing fields as `{"rule": "message"}` objects, and every field shows
 * up twice (camelCase *and* snake_case). This collapses that to one entry per
 * field with plain messages, and maps fields back to their `llmor.scsc` origin.
 */
final class ValidationErrorFormatter
{
    /** Field (canonical snake_case) → manifest-facing label. */
    private const LABELS = [
        'name' => '[name]',
        'description' => '[description]',
        'function_key' => 'the declaration name (function key)',
        'runtime' => '[runtime]',
        'code' => 'the [entry] file',
        'is_library' => 'library flag',
        'specific_app_id' => 'specific app id',
        'argument_schema' => 'argument schema',
        'config_schema' => 'config schema',
        'path' => 'auxiliary file path',
        'content' => 'auxiliary file content',
        'content_type' => 'auxiliary file content type',
    ];

    /**
     * Collapse the raw errors map: drop empty fields, flatten `{rule: message}`
     * objects, and merge the camel/snake duplicates into one canonical entry.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, list<string>>
     */
    public static function clean(array $raw): array
    {
        $cleaned = [];
        foreach ($raw as $field => $value) {
            $messages = self::messages($value);
            if ([] === $messages) {
                continue;
            }

            $key = self::canonical((string) $field);
            $existing = $cleaned[$key] ?? [];
            foreach ($messages as $message) {
                if (!\in_array($message, $existing, true)) {
                    $existing[] = $message;
                }
            }
            $cleaned[$key] = $existing;
        }

        return self::dedupeRulesAgainstFields($cleaned);
    }

    /**
     * The server's catch-all `rules` bucket often echoes a field-specific message.
     * Drop those duplicates so the same error isn't shown twice, but keep genuine
     * cross-field rule messages.
     *
     * @param array<string, list<string>> $cleaned
     *
     * @return array<string, list<string>>
     */
    private static function dedupeRulesAgainstFields(array $cleaned): array
    {
        if (!isset($cleaned['rules'])) {
            return $cleaned;
        }

        $fromFields = [];
        foreach ($cleaned as $field => $messages) {
            if ('rules' !== $field) {
                $fromFields = \array_merge($fromFields, $messages);
            }
        }

        $unique = \array_values(\array_filter(
            $cleaned['rules'],
            static fn (string $message): bool => !\in_array($message, $fromFields, true),
        ));

        if ([] === $unique) {
            unset($cleaned['rules']);
        } else {
            $cleaned['rules'] = $unique;
        }

        return $cleaned;
    }

    public static function label(string $field): string
    {
        $key = self::canonical($field);

        return self::LABELS[$key] ?? $key;
    }

    /**
     * An actionable suggestion for known fields/rules, or null.
     *
     * @param list<string> $messages
     */
    public static function hint(string $field, array $messages): ?string
    {
        return match (self::canonical($field)) {
            'runtime' => "must be 'silicon' or 'graph'",
            'function_key' => 'use letters, digits and underscores; it must start with a letter or underscore',
            'content_type' => 'auxiliary files must be a supported text type (md, json, csv, html, xml, yaml or plain text)',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function messages(mixed $value): array
    {
        if (\is_string($value)) {
            return '' === $value ? [] : [$value];
        }
        if (!\is_array($value)) {
            return [];
        }

        $messages = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== $item) {
                $messages[] = $item;
            }
        }

        return $messages;
    }

    private static function canonical(string $field): string
    {
        $snake = \preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $field) ?? $field;

        return \strtolower($snake);
    }
}
