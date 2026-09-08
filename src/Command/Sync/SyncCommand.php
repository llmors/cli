<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Sync;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractManifestCommand;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\FunctionDefinition;
use Llmor\Cli\Manifest\Manifest;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Sync\AppLockFile;
use Llmor\Cli\Sync\AppSyncResult;
use Llmor\Cli\Sync\FunctionIdResolver;
use Llmor\Cli\Sync\ResolvedApp;
use Llmor\Cli\Sync\SyncError;
use Llmor\Cli\Sync\SyncErrorFactory;
use Llmor\Cli\Sync\SyncException;
use Llmor\Cli\Sync\SyncReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconcile everything declared in `llmor.scsc` with llmor.com: create or update each
 * function record and mirror its source files, then create or update each app.
 * Failures for one declaration don't stop the rest — they're collected into a
 * {@see SyncReport} and rendered together at the end.
 *
 * Functions are synced before apps, because an app can install a function and
 * therefore needs its id to exist.
 */
#[AsCommand(
    name: 'sync',
    description: 'Create/update the functions and apps declared in llmor.scsc.',
)]
final class SyncCommand extends AbstractManifestCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('function', null, InputOption::VALUE_REQUIRED, 'Sync only the function with this key.')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Sync only the app with this declaration name.')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete remote files that no longer exist locally.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without applying anything.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $prune = (bool) $input->getOption('prune');
        $dryRun = (bool) $input->getOption('dry-run');
        $report = new SyncReport();

        $onlyFunction = $input->getOption('function');
        $onlyApp = $input->getOption('app');

        try {
            $manifest = $this->loadManifest();
            // Naming one kind excludes the other: `--function x` is not "and every app
            // too". An empty option value is no filter at all.
            $functions = $onlyApp ? [] : $this->select($manifest->functions, $onlyFunction, 'Function', static fn (FunctionDefinition $f): string => $f->functionKey);
            $apps = $onlyFunction ? [] : $this->select($manifest->apps, $onlyApp, 'App', static fn (AppDefinition $a): string => $a->declaration);
        } catch (ManifestException $e) {
            return $this->finish($io, $report, $json, $dryRun, null, SyncErrorFactory::fromThrowable($e, null, SyncError::SCOPE_MANIFEST));
        }

        if ([] === $functions && [] === $apps) {
            if (!$json) {
                $io->info('Nothing to sync.');
            } else {
                $output->writeln($this->encodeJson($report->toArray()));
            }

            return Command::SUCCESS;
        }

        try {
            $vendorId = $this->resolveVendorId();
        } catch (SyncException|ApiException $e) {
            return $this->finish($io, $report, $json, $dryRun, null, SyncErrorFactory::fromThrowable($e, null, SyncError::SCOPE_VENDOR));
        }

        $functionIds = $this->syncFunctions($report, $functions, $vendorId, $prune, $dryRun);

        $lock = null;
        if ([] !== $apps) {
            $lock = $this->lockFile($manifest, $dryRun);
            $this->syncApps($report, $manifest, $apps, $lock, $vendorId, $dryRun, $functionIds);
        }

        return $this->finish($io, $report, $json, $dryRun, $lock);
    }

    /**
     * Sync the functions, and hand back a resolver seeded with the ids they now have,
     * so an app installing one of them doesn't need to look it up again.
     *
     * @param list<FunctionDefinition> $functions
     */
    private function syncFunctions(SyncReport $report, array $functions, int $vendorId, bool $prune, bool $dryRun): FunctionIdResolver
    {
        $synchronizer = $this->synchronizer($vendorId);
        $ids = new FunctionIdResolver($synchronizer);

        foreach ($functions as $function) {
            try {
                $result = $synchronizer->sync($function, $prune, $dryRun);
                $ids->remember($function->functionKey, $result->functionId);
                $report->addResult($result);
            } catch (ManifestException|SyncException|ApiException $e) {
                $report->addError(SyncErrorFactory::fromThrowable($e, $function->functionKey, SyncError::SCOPE_FUNCTION));
            }
        }

        return $ids;
    }

    /**
     * Resolve every declared app to an id first, then write only the selected ones.
     *
     * Resolution covers apps `--app` excludes on purpose: it is read-only, and the
     * ids of unselected apps are what a selected app's sub-agent targets point at.
     *
     * @param list<AppDefinition> $selected
     */
    private function syncApps(SyncReport $report, Manifest $manifest, array $selected, AppLockFile $lock, int $vendorId, bool $dryRun, FunctionIdResolver $functionIds): void
    {
        try {
            $resolver = $this->appResolver($lock, $vendorId);
        } catch (SyncException|ApiException $e) {
            $report->addError(SyncErrorFactory::fromThrowable($e, null, SyncError::SCOPE_APP));

            return;
        }

        $wanted = [];
        foreach ($selected as $app) {
            $wanted[$app->declaration] = true;
        }

        /** @var array<string, ResolvedApp> $resolved */
        $resolved = [];

        foreach ($manifest->apps as $app) {
            try {
                $resolved[$app->declaration] = $resolver->resolve($app);
            } catch (SyncException $e) {
                // An unselected app that can't be resolved is only a problem once
                // something references it, so don't report it as this run's failure.
                if (isset($wanted[$app->declaration])) {
                    $report->addError(SyncErrorFactory::fromThrowable($e, $app->declaration, SyncError::SCOPE_APP));
                }
            }
        }

        $synchronizer = $this->appSynchronizer($lock, $vendorId, $functionIds);

        // Pass 1 — the app records themselves.
        /** @var array<string, AppSyncResult> $results */
        $results = [];

        foreach ($manifest->apps as $app) {
            if (!isset($wanted[$app->declaration], $resolved[$app->declaration])) {
                continue;
            }

            try {
                $result = $synchronizer->sync($resolved[$app->declaration], $dryRun);

                if (null !== $result->appId) {
                    $resolver->claim($app->declaration, $result->appId);
                    // Newly minted ids have to reach the sub-agent pass below.
                    $resolved[$app->declaration] = $resolved[$app->declaration]->withId($result->appId);
                }

                $results[$app->declaration] = $result;
                $report->addResult($result);
            } catch (ManifestException|SyncException|ApiException $e) {
                $report->addError(SyncErrorFactory::fromThrowable($e, $app->declaration, SyncError::SCOPE_APP));
            }
        }

        // Pass 2 — sub-agents, now that every app has an id. Every resolution is handed
        // over, including apps `--app` excluded: a selected app may delegate to one.
        $synchronizer->setPeers($resolved);

        foreach ($results as $declaration => $result) {
            try {
                $synchronizer->reconcileSubagents($result, $resolved[$declaration], $dryRun);
            } catch (SyncException|ApiException $e) {
                $report->addError(SyncErrorFactory::fromThrowable($e, $declaration, SyncError::SCOPE_SUBAGENT));
            }
        }

        foreach ($lock->warnings as $warning) {
            $report->addError(new SyncError(
                scope: SyncError::SCOPE_LOCK,
                category: SyncError::CATEGORY_LOCAL,
                summary: $warning,
                subject: AppLockFile::FILE_NAME,
            ));
        }
    }

    private function finish(OutputStyle $io, SyncReport $report, bool $json, bool $dryRun, ?AppLockFile $lock, ?SyncError $extra = null): int
    {
        if (null !== $extra) {
            $report->addError($extra);
        }

        if ($json) {
            $io->writeln($this->encodeJson($report->toArray()));
        } else {
            $this->renderReport($io, $report, $dryRun);

            // The whole app-identity design rests on this file being committed, so say
            // so at the moment it changes rather than burying it in the docs.
            if (null !== $lock && $lock->wasWritten()) {
                $io->note(\sprintf('Recorded app ids in %s — commit this file.', AppLockFile::FILE_NAME));
            }
        }

        return $report->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Narrow one kind of declaration to a single `--function`/`--app` value, or return
     * all of them when no filter was given.
     *
     * @template T of AppDefinition|FunctionDefinition
     *
     * @param list<T>             $declarations
     * @param callable(T): string $keyOf
     *
     * @return list<T>
     *
     * @throws ManifestException
     */
    private function select(array $declarations, mixed $only, string $noun, callable $keyOf): array
    {
        if (null === $only || '' === $only) {
            return $declarations;
        }

        foreach ($declarations as $declaration) {
            if ($keyOf($declaration) === $only) {
                return [$declaration];
            }
        }

        throw new ManifestException(\sprintf('%s "%s" is not declared in the manifest.', $noun, (string) $only));
    }
}
