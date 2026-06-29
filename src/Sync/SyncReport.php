<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * Aggregates one sync run: the per-function successes, the collected errors, and
 * the warnings raised along the way. The command layer renders it (human or JSON)
 * once at the end instead of printing as it goes.
 */
final class SyncReport
{
    /** @var list<SyncResult> */
    public array $results = [];

    /** @var list<SyncError> */
    public array $errors = [];

    public function addResult(SyncResult $result): void
    {
        $this->results[] = $result;
    }

    public function addError(SyncError $error): void
    {
        $this->errors[] = $error;
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    /**
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function actionCounts(): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach ($this->results as $result) {
            match ($result->functionAction) {
                SyncResult::CREATED => $counts['created']++,
                SyncResult::UPDATED => $counts['updated']++,
                default => $counts['unchanged']++,
            };
        }

        return $counts;
    }

    /**
     * @return list<array{function_key: string, message: string}>
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->results as $result) {
            foreach ($result->warnings as $message) {
                $warnings[] = ['function_key' => $result->functionKey, 'message' => $message];
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $counts = $this->actionCounts();
        $files = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        foreach ($this->results as $result) {
            $files['created'] += \count($result->filesCreated);
            $files['updated'] += \count($result->filesUpdated);
            $files['deleted'] += \count($result->filesDeleted);
        }

        return [
            'ok' => !$this->hasErrors(),
            'summary' => $counts + ['failed' => \count($this->errors), 'files' => $files],
            'results' => \array_map(static fn (SyncResult $r): array => $r->toArray(), $this->results),
            'errors' => \array_map(static fn (SyncError $e): array => $e->toArray(), $this->errors),
            'warnings' => $this->warnings(),
        ];
    }
}
