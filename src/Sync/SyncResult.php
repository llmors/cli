<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * Accumulates what {@see FunctionSynchronizer} did (or, in dry-run, would do) for a
 * single function, for the command layer to render.
 */
final class SyncResult implements SyncOutcome
{
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

    public function kind(): string
    {
        return 'function';
    }

    public function subject(): string
    {
        return $this->functionKey;
    }

    public function action(): string
    {
        return $this->functionAction;
    }

    public function detail(): string
    {
        return \sprintf(
            '+%d ~%d -%d =%d',
            \count($this->filesCreated),
            \count($this->filesUpdated),
            \count($this->filesDeleted),
            $this->filesUnchanged,
        );
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<string>
     */
    public function detailLines(): array
    {
        return [];
    }

    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    public function fileCounts(): array
    {
        return [
            'created' => \count($this->filesCreated),
            'updated' => \count($this->filesUpdated),
            'deleted' => \count($this->filesDeleted),
        ];
    }

    public function fileChangeCount(): int
    {
        return \array_sum($this->fileCounts());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind(),
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
