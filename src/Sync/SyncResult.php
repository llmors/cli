<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * Accumulates what {@see FunctionSynchronizer} did (or, in dry-run, would do) for a
 * single function, for the command layer to render.
 */
final class SyncResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    public string $functionAction = self::UNCHANGED;

    public ?int $functionId = null;

    /** @var list<string> */
    public array $filesCreated = [];

    /** @var list<string> */
    public array $filesUpdated = [];

    /** @var list<string> */
    public array $filesDeleted = [];

    public int $filesUnchanged = 0;

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(public readonly string $functionKey)
    {
    }

    public function fileChangeCount(): int
    {
        return \count($this->filesCreated) + \count($this->filesUpdated) + \count($this->filesDeleted);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'function_key' => $this->functionKey,
            'action' => $this->functionAction,
            'function_id' => $this->functionId,
            'files_created' => $this->filesCreated,
            'files_updated' => $this->filesUpdated,
            'files_deleted' => $this->filesDeleted,
            'files_unchanged' => $this->filesUnchanged,
            'warnings' => $this->warnings,
        ];
    }
}
