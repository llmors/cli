<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * Aggregates one sync run: the per-declaration outcomes, the collected errors, and
 * the warnings raised along the way. The command layer renders it (human or JSON)
 * once at the end instead of printing as it goes.
 */
final class SyncReport
{
    /** @var list<SyncOutcome> */
    public array $results = [];

    /** @var list<SyncError> */
    public array $errors = [];

    public function addResult(SyncOutcome $result): void
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
            match ($result->action()) {
                SyncOutcome::CREATED => $counts['created']++,
                SyncOutcome::UPDATED => $counts['updated']++,
                default => $counts['unchanged']++,
            };
        }

        return $counts;
    }

    /**
     * How many outcomes there are of each kind, in first-seen order.
     *
     * @return array<string, int>
     */
    public function kindCounts(): array
    {
        $counts = [];
        foreach ($this->results as $result) {
            $counts[$result->kind()] = ($counts[$result->kind()] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<array{subject: string, message: string}>
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->results as $result) {
            foreach ($result->warnings() as $message) {
                $warnings[] = ['subject' => $result->subject(), 'message' => $message];
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

        return [
            'ok' => !$this->hasErrors(),
            'summary' => $counts + [
                'failed' => \count($this->errors),
                'files' => $this->fileCounts(),
                'by_kind' => $this->kindCounts(),
            ],
            'results' => \array_map(static fn (SyncOutcome $r): array => $r->toArray(), $this->results),
            'errors' => \array_map(static fn (SyncError $e): array => $e->toArray(), $this->errors),
            'warnings' => $this->warnings(),
        ];
    }

    /**
     * The aggregate file counts, kept in `summary.files` for compatibility with
     * existing `--json` consumers. Each outcome reports its own contribution, so a
     * new kind of declaration needs no change here.
     *
     * @return array{created: int, updated: int, deleted: int}
     */
    private function fileCounts(): array
    {
        $files = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        foreach ($this->results as $result) {
            foreach ($result->fileCounts() as $action => $count) {
                $files[$action] += $count;
            }
        }

        return $files;
    }
}
