<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * One collected failure from a sync/run, with enough context to render it
 * helpfully: where it happened ({@see $scope}), what kind it is
 * ({@see $category}), a one-line summary, cleaned per-field messages for
 * validation failures, and an optional actionable hint.
 */
final class SyncError
{
    public const SCOPE_MANIFEST = 'manifest';
    public const SCOPE_VENDOR = 'vendor';
    public const SCOPE_FUNCTION = 'function';
    public const SCOPE_APP = 'app';
    public const SCOPE_SUBAGENT = 'subagent';
    public const SCOPE_LOCK = 'lock';
    public const SCOPE_FILE = 'file';
    public const SCOPE_INPUT = 'input';

    public const CATEGORY_CONFIG = 'config';
    public const CATEGORY_VALIDATION = 'validation';
    public const CATEGORY_API = 'api';
    public const CATEGORY_LOCAL = 'local';
    public const CATEGORY_AUTH = 'auth';
    public const CATEGORY_INPUT = 'input';

    /**
     * @param ?string                     $subject the declaration this is about, if any
     * @param array<string, list<string>> $fields  cleaned per-field messages (validation only)
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $category,
        public readonly string $summary,
        public readonly ?string $subject = null,
        public readonly array $fields = [],
        public readonly ?string $hint = null,
        public readonly int $statusCode = 0,
        public readonly ?string $target = null,
    ) {
    }

    /** A short label for who/what this error is about. */
    public function subjectLabel(): string
    {
        return $this->subject ?? $this->scope;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return \array_filter([
            'subject' => $this->subject,
            'scope' => $this->scope,
            'category' => $this->category,
            'summary' => $this->summary,
            'fields' => [] !== $this->fields ? $this->fields : null,
            'hint' => $this->hint,
            'status' => 0 !== $this->statusCode ? $this->statusCode : null,
            'target' => $this->target,
        ], static fn ($value): bool => null !== $value);
    }
}
