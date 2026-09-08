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
        // apps
        'app_key' => '[app_type]',
        'parameters' => '[parameters]',
        'completion_vendor_model_id' => '[model]',
        'functions' => '[functions]',
        'function_config' => '[functions] config',
        'alias' => '[subagents] alias',
        'target_vendor_app_id' => '[subagents] [app]',
        'expose_as_tool' => '[expose_as_tool]',
        'tool_name' => '[tool_name]',
        'tool_description' => '[tool_description]',
        'input_description' => '[input_description]',
        'assist_feature_key' => 'assist feature key',
        // An app's PUT is validated against the whole merged record, so a field the
        // manifest doesn't own can still fail. Saying where it lives saves a hunt.
        'embed_config' => 'embed config (managed in the console)',
        'allowed_origins' => 'allowed origins (managed in the console)',
        'conversation_expire_after' => 'conversation expiry (managed in the console)',
    ];

    /** How deep to walk a nested errors map before giving up. */
    private const MAX_DEPTH = 4;

    /** Upper bound on rendered fields, so a pathological response can't print 500 lines. */
    private const MAX_FIELDS = 25;

    /**
     * Collapse the raw errors map: drop empty fields, flatten `{rule: message}`
     * objects, and merge the camel/snake duplicates into one canonical entry.
     *
     * Nested maps are flattened to dotted paths, because app validation arrives one
     * level down — `parameters` fails as `{"temperature": ["must be at most 2"]}`, and
     * reading only the top level would leave the user with a bare "rejected by the API"
     * and nothing under it.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, list<string>>
     */
    public static function clean(array $raw): array
    {
        $cleaned = [];
        self::flatten($raw, '', 0, $cleaned);

        return self::dedupeRulesAgainstFields($cleaned);
    }

    /**
     * Walk the errors map, collecting messages against the dotted path they belong to.
     *
     * A map whose values are strings is the server's `{rule: message}` shape, so its
     * messages belong to the *parent* field; a map whose values are themselves
     * structures is a nested field map and each key extends the path.
     *
     * @param array<array-key, mixed>     $value
     * @param array<string, list<string>> &$cleaned
     */
    private static function flatten(array $value, string $prefix, int $depth, array &$cleaned): void
    {
        foreach ($value as $key => $item) {
            if (\count($cleaned) >= self::MAX_FIELDS) {
                return;
            }

            if (\is_string($item)) {
                // {rule: message} — the message describes the field we're already on.
                self::collect($cleaned, $prefix, [$item]);
                continue;
            }

            if (!\is_array($item) || [] === $item) {
                continue;
            }

            $path = self::extend($prefix, (string) $key);

            if (self::isMessageList($item)) {
                self::collect($cleaned, $path, \array_values(\array_filter($item, static fn (mixed $m): bool => \is_string($m) && '' !== $m)));
                continue;
            }

            if ($depth + 1 >= self::MAX_DEPTH) {
                continue;
            }

            self::flatten($item, $path, $depth + 1, $cleaned);
        }
    }

    /**
     * Whether a node is a leaf list of messages rather than a nested field map.
     *
     * @param array<array-key, mixed> $item
     */
    private static function isMessageList(array $item): bool
    {
        foreach ($item as $value) {
            if (!\is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, list<string>> &$cleaned
     * @param list<string>                $messages
     */
    private static function collect(array &$cleaned, string $path, array $messages): void
    {
        if ('' === $path || [] === $messages) {
            return;
        }

        $existing = $cleaned[$path] ?? [];
        foreach ($messages as $message) {
            if ('' !== $message && !\in_array($message, $existing, true)) {
                $existing[] = $message;
            }
        }

        if ([] !== $existing) {
            $cleaned[$path] = $existing;
        }
    }

    /** Canonicalise each segment separately so dotted paths still dedupe camel/snake. */
    private static function extend(string $prefix, string $key): string
    {
        $segment = self::canonical($key);

        return '' === $prefix ? $segment : $prefix.'.'.$segment;
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

    /**
     * A manifest-facing label for a field, or for a dotted path into a nested one:
     * `parameters.temperature` reads as `[parameters] → temperature`.
     */
    public static function label(string $field): string
    {
        $segments = \explode('.', self::canonical($field));
        $head = \array_shift($segments) ?? '';
        $label = self::LABELS[$head] ?? $head;

        return [] === $segments ? $label : $label.' → '.\implode(' → ', $segments);
    }

    /**
     * An actionable suggestion for a field that failed validation, or null.
     *
     * Some rules differ by what is being synced — a function's `[name]` has no minimum
     * length, an app's does — so a hint may be keyed by `scope.field`, which wins over
     * the field-only entry. Stating the wrong bound is worse than stating none.
     */
    public static function hint(string $scope, string $field): ?string
    {
        $field = self::canonical($field);

        return match (\sprintf('%s.%s', $scope, $field)) {
            SyncError::SCOPE_APP.'.name' => 'must be 2 to 144 characters',
            SyncError::SCOPE_FUNCTION.'.name' => 'must be at most 144 characters',
            default => match ($field) {
                'runtime' => "must be 'silicon' or 'graph'",
                'function_key' => 'use letters, digits and underscores; it must start with a letter or underscore',
                'content_type' => 'auxiliary files must be a supported text type (md, json, csv, html, xml, yaml or plain text)',
                'app_key' => 'an app\'s type is fixed when it is created — use a new declaration name, or pin the existing app with [id]',
                'alias' => 'lowercase, starting with a letter, 2 to 32 characters',
                'tool_name' => "must be unique across this app's sub-agents and installed functions; letters, digits, '_' and '-', up to 64 characters",
                'target_vendor_app_id' => 'the target must be another app of this vendor — check the [app] reference',
                default => null,
            },
        };
    }

    private static function canonical(string $field): string
    {
        $snake = \preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $field) ?? $field;

        return \strtolower($snake);
    }
}
