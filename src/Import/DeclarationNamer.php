<?php

declare(strict_types=1);

namespace Llmor\Cli\Import;

use Llmor\Cli\Manifest\Builder\DeclarationContext;
use Llmor\Cli\Manifest\Manifest;

/**
 * Turns a remote app's free-text `[name]` into a declaration name the manifest can use.
 *
 * A declaration name is an identifier — `/^[a-zA-Z_][a-zA-Z0-9_]*$/` — and shares one
 * namespace with the function declarations, because a `[functions]` reference would
 * otherwise be ambiguous. "Support Bot" therefore has to become `support_bot`, and a
 * name already in use has to be settled by a human rather than silently suffixed.
 */
final class DeclarationNamer
{
    /** Long enough to stay descriptive, short enough to stay readable. */
    private const MAX_LENGTH = 48;

    /** @var array<string, string> declaration name => kind */
    private array $taken = [];

    public function __construct(Manifest $manifest)
    {
        foreach ($manifest->functions as $function) {
            $this->taken[$function->functionKey] = 'function';
        }

        foreach ($manifest->apps as $app) {
            $this->taken[$app->declaration] = 'app';
        }
    }

    /** The kind of declaration already using this name, or null when it is free. */
    public function takenBy(string $name): ?string
    {
        return $this->taken[$name] ?? null;
    }

    /** "an app" / "a function", for a sentence that reads properly. */
    public function describeTaken(string $name): string
    {
        $kind = $this->takenBy($name) ?? 'declaration';

        return ('app' === $kind ? 'an ' : 'a ').$kind;
    }

    public function isValid(string $name): bool
    {
        return 1 === \preg_match(DeclarationContext::KEY_PATTERN, $name);
    }

    /**
     * A declaration name for a remote app, ignoring whether it is free.
     */
    public function suggest(?string $remoteName, string $appKey, int $appId): string
    {
        foreach ([$remoteName, self::appKeyLabel($appKey)] as $candidate) {
            $slug = self::slug((string) $candidate);
            if ('' !== $slug) {
                return $slug;
            }
        }

        return 'app_'.$appId;
    }

    /** The first free name in the `base`, `base_2`, `base_3`, … series. */
    public function nextFree(string $base): string
    {
        if (null === $this->takenBy($base)) {
            return $base;
        }

        for ($n = 2; $n < 1000; ++$n) {
            $candidate = $base.'_'.$n;
            if (null === $this->takenBy($candidate)) {
                return $candidate;
            }
        }

        return $base.'_'.\uniqid();
    }

    /** `llmor/generic` → `generic_app`, for an app with no name of its own. */
    private static function appKeyLabel(string $appKey): string
    {
        $suffix = \substr($appKey, (int) \strrpos($appKey, '/') + 1);

        return '' === $suffix ? '' : $suffix.'_app';
    }

    private static function slug(string $value): string
    {
        $slug = \strtolower($value);
        $slug = \preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = \trim($slug, '_');

        if ('' === $slug) {
            return '';
        }

        // A leading digit is a valid identifier character but not a valid first one.
        if (1 === \preg_match('/^[0-9]/', $slug)) {
            $slug = 'app_'.$slug;
        }

        return \rtrim(\substr($slug, 0, self::MAX_LENGTH), '_');
    }
}
