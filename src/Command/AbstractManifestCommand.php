<?php

declare(strict_types=1);

namespace Llmor\Cli\Command;

use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Manifest\FunctionManifest;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\ManifestLocator;
use Llmor\Cli\Manifest\ManifestParser;
use Llmor\Cli\Sync\FunctionSynchronizer;
use Llmor\Cli\Sync\SyncError;
use Llmor\Cli\Sync\SyncReport;
use Llmor\Cli\Sync\ValidationErrorFormatter;
use Llmor\Cli\Sync\VendorResolver;

/**
 * Shared wiring for the manifest-driven commands (`sync`, `run`): manifest discovery
 * + parsing, vendor-id resolution, and synchronizer construction. The working
 * directory is injected so tests can point at a temp project.
 */
abstract class AbstractManifestCommand extends AbstractCommand
{
    public function __construct(
        protected readonly LlmorClient $client,
        protected readonly ?string $vendorKey,
        protected readonly string $workingDir,
    ) {
        parent::__construct();
    }

    /**
     * @throws ManifestException when no manifest exists or it cannot be parsed
     */
    protected function loadManifest(): FunctionManifest
    {
        $path = (new ManifestLocator($this->workingDir))->locate();
        if (null === $path) {
            throw new ManifestException(\sprintf('No %s manifest found in %s or any parent directory.', ManifestLocator::FILE_NAME, $this->workingDir));
        }

        return (new ManifestParser())->parseFile($path);
    }

    protected function resolveVendorId(): int
    {
        return (new VendorResolver($this->client))->resolveId($this->vendorKey);
    }

    protected function synchronizer(int $vendorId): FunctionSynchronizer
    {
        return new FunctionSynchronizer($this->client, $vendorId);
    }

    /**
     * Render a full sync report: the per-function outcomes, warnings, collected
     * errors (grouped, with hints) and a final one-line summary.
     */
    protected function renderReport(OutputStyle $io, SyncReport $report, bool $dryRun = false): void
    {
        if ($dryRun) {
            $io->note('Dry run — no changes were applied.');
            $io->newLine();
        }

        foreach ($report->results as $result) {
            $files = \sprintf(
                '+%d ~%d -%d =%d',
                \count($result->filesCreated),
                \count($result->filesUpdated),
                \count($result->filesDeleted),
                $result->filesUnchanged,
            );

            [$glyph, $tag] = match ($result->functionAction) {
                'created' => ['<ok>✓</ok>', 'ok'],
                'updated' => ['<warn>●</warn>', 'warn'],
                default => ['<muted>·</muted>', 'muted'],
            };

            $io->writeln(\sprintf(
                '%s %s  <%s>%s</%s>  <muted>%s</muted>',
                $glyph,
                $result->functionKey,
                $tag,
                $result->functionAction,
                $tag,
                $files,
            ));
        }

        $warnings = $report->warnings();
        if ([] !== $warnings) {
            $io->newLine();
            foreach ($warnings as $warning) {
                $io->warning(\sprintf('%s: %s', $warning['function_key'], $warning['message']));
            }
        }

        if ([] !== $report->results || [] !== $warnings) {
            $io->newLine();
        }

        foreach ($report->errors as $error) {
            $this->renderSyncError($io, $error);
        }

        $this->renderSummary($io, $report, $dryRun);
    }

    /**
     * Render a single error: a headline, any cleaned per-field messages, and a hint.
     */
    protected function renderSyncError(OutputStyle $io, SyncError $error): void
    {
        $io->writeln(\sprintf('<bad>✗ %s</bad> — %s', $error->subject(), $error->summary));

        foreach ($error->fields as $field => $messages) {
            $io->writeln(\sprintf('  <accent>%s</accent>  %s', ValidationErrorFormatter::label((string) $field), \implode('; ', $messages)));
        }

        if (null !== $error->hint) {
            $io->hint($error->hint);
        }
        $io->newLine();
    }

    private function renderSummary(OutputStyle $io, SyncReport $report, bool $dryRun): void
    {
        $counts = $report->actionCounts();
        $verb = $dryRun ? 'Would sync' : 'Synced';
        $ok = \sprintf(
            '%s %d function(s): %d created, %d updated, %d unchanged',
            $verb,
            \count($report->results),
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
        );

        if (!$report->hasErrors()) {
            $io->success($ok);

            return;
        }

        $io->writeln(\sprintf('<ok>✓ %s</ok> · <bad>%d failed</bad>', $ok, \count($report->errors)));
    }
}
