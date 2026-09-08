<?php

declare(strict_types=1);

namespace Llmor\Cli\Command;

use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Manifest\Manifest;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\ManifestLocator;
use Llmor\Cli\Manifest\ManifestParser;
use Llmor\Cli\Sync\AppLockFile;
use Llmor\Cli\Sync\AppResolver;
use Llmor\Cli\Sync\AppSynchronizer;
use Llmor\Cli\Sync\FunctionIdResolver;
use Llmor\Cli\Sync\FunctionSynchronizer;
use Llmor\Cli\Sync\ModelResolver;
use Llmor\Cli\Sync\RemoteAppIndex;
use Llmor\Cli\Sync\SyncError;
use Llmor\Cli\Sync\SyncOutcome;
use Llmor\Cli\Sync\SyncReport;
use Llmor\Cli\Sync\ValidationErrorFormatter;

/**
 * Shared wiring for the manifest-driven commands (`sync`, `run`): manifest discovery
 * + parsing and synchronizer construction, on top of the vendor resolution every
 * vendor-scoped command shares. The working directory is injected so tests can point
 * at a temp project.
 */
abstract class AbstractManifestCommand extends AbstractVendorCommand
{
    public function __construct(
        LlmorClient $client,
        ?string $vendorKey,
        protected readonly string $workingDir,
    ) {
        parent::__construct($client, $vendorKey);
    }

    /** The nearest manifest, or null when this project has none yet. */
    protected function locateManifestPath(): ?string
    {
        return (new ManifestLocator($this->workingDir))->locate();
    }

    /**
     * @throws ManifestException when no manifest exists or it cannot be parsed
     */
    protected function loadManifest(): Manifest
    {
        $path = $this->locateManifestPath();
        if (null === $path) {
            throw new ManifestException(\sprintf('No %s manifest found in %s or any parent directory.', ManifestLocator::FILE_NAME, $this->workingDir));
        }

        return (new ManifestParser())->parseFile($path);
    }

    /**
     * The manifest, or an empty one at the path a new manifest would take — for the
     * commands that may legitimately run before a project has one.
     *
     * A manifest that exists but is *broken* still throws, deliberately: a command that
     * writes into a file whose declarations it cannot read has no way to tell whether it
     * is about to create a duplicate.
     *
     * @throws ManifestException when a manifest exists but cannot be parsed
     */
    protected function loadManifestOrEmpty(): Manifest
    {
        $path = $this->locateManifestPath();

        return null === $path
            ? new Manifest($this->workingDir.\DIRECTORY_SEPARATOR.ManifestLocator::FILE_NAME, [])
            : (new ManifestParser())->parseFile($path);
    }

    protected function synchronizer(int $vendorId): FunctionSynchronizer
    {
        return new FunctionSynchronizer($this->client, $vendorId);
    }

    /**
     * The `llmor.lock` beside a manifest — the app-id bindings this project owns.
     *
     * A dry run gets a read-only lock, so "writes nothing at all" is a property of the
     * file itself rather than a rule every caller has to remember.
     */
    protected function lockFile(Manifest $manifest, bool $dryRun = false): AppLockFile
    {
        return AppLockFile::besideManifest($manifest->path, $dryRun);
    }

    protected function appResolver(AppLockFile $lock, int $vendorId): AppResolver
    {
        return new AppResolver($this->vendorKey(), $lock, RemoteAppIndex::fetch($this->client, $vendorId));
    }

    protected function appSynchronizer(AppLockFile $lock, int $vendorId, FunctionIdResolver $functions): AppSynchronizer
    {
        return new AppSynchronizer(
            $this->client,
            $vendorId,
            $this->vendorKey(),
            $lock,
            new ModelResolver($this->client, $vendorId),
            $functions,
        );
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
            [$glyph, $tag] = match ($result->action()) {
                SyncOutcome::CREATED => ['<ok>✓</ok>', 'ok'],
                SyncOutcome::UPDATED => ['<warn>●</warn>', 'warn'],
                default => ['<muted>·</muted>', 'muted'],
            };

            $io->writeln(\sprintf(
                '%s %s  <%s>%s</%s>  <muted>%s</muted>',
                $glyph,
                $result->subject(),
                $tag,
                $result->action(),
                $tag,
                $result->detail(),
            ));

            foreach ($result->detailLines() as $line) {
                $io->writeln(\sprintf('  <muted>↳</muted> %s', $line));
            }
        }

        $warnings = $report->warnings();
        if ([] !== $warnings) {
            $io->newLine();
            foreach ($warnings as $warning) {
                $io->warning(\sprintf('%s: %s', $warning['subject'], $warning['message']));
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
        $io->writeln(\sprintf('<bad>✗ %s</bad> — %s', $error->subjectLabel(), $error->summary));

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
            '%s %s: %d created, %d updated, %d unchanged',
            $verb,
            self::describeSubjects($report),
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

    /**
     * "2 function(s), 1 app(s)" — one clause per kind that actually appears, so a
     * functions-only project reads exactly as it always has.
     */
    private static function describeSubjects(SyncReport $report): string
    {
        $kinds = $report->kindCounts();
        if ([] === $kinds) {
            // Reachable only when every declaration failed, so don't claim a kind.
            return 'nothing';
        }

        $parts = [];
        foreach ($kinds as $kind => $count) {
            $parts[] = \sprintf('%d %s(s)', $count, $kind);
        }

        return \implode(', ', $parts);
    }
}
