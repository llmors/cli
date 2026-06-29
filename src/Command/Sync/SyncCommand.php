<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Sync;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractManifestCommand;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Manifest\FunctionDefinition;
use Llmor\Cli\Manifest\ManifestException;
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
 * Reconcile every function declared in `llmor.scsc` with llmor.com: create or
 * update the function record and mirror its source files. Failures for one
 * function don't stop the rest — they're collected into a {@see SyncReport} and
 * rendered together at the end.
 */
#[AsCommand(
    name: 'sync',
    description: 'Create/update functions declared in llmor.scsc and sync their source files.',
)]
final class SyncCommand extends AbstractManifestCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('function', null, InputOption::VALUE_REQUIRED, 'Sync only the function with this key.')
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

        try {
            $manifest = $this->loadManifest();
            $functions = $this->selectFunctions($manifest->functions, $input->getOption('function'));
        } catch (ManifestException $e) {
            return $this->finish($io, $report, $json, $dryRun, SyncErrorFactory::fromThrowable($e, null, SyncError::SCOPE_MANIFEST));
        }

        if ([] === $functions) {
            if (!$json) {
                $io->info('No functions to sync.');
            } else {
                $output->writeln($this->encodeJson($report->toArray()));
            }

            return Command::SUCCESS;
        }

        try {
            $vendorId = $this->resolveVendorId();
        } catch (SyncException|ApiException $e) {
            return $this->finish($io, $report, $json, $dryRun, SyncErrorFactory::fromThrowable($e, null, SyncError::SCOPE_VENDOR));
        }

        $synchronizer = $this->synchronizer($vendorId);
        foreach ($functions as $function) {
            try {
                $report->addResult($synchronizer->sync($function, $prune, $dryRun));
            } catch (ManifestException|SyncException|ApiException $e) {
                $report->addError(SyncErrorFactory::fromThrowable($e, $function->functionKey, SyncError::SCOPE_FUNCTION));
            }
        }

        return $this->finish($io, $report, $json, $dryRun);
    }

    private function finish(OutputStyle $io, SyncReport $report, bool $json, bool $dryRun, ?SyncError $extra = null): int
    {
        if (null !== $extra) {
            $report->addError($extra);
        }

        if ($json) {
            $io->writeln($this->encodeJson($report->toArray()));
        } else {
            $this->renderReport($io, $report, $dryRun);
        }

        return $report->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<FunctionDefinition> $functions
     *
     * @return list<FunctionDefinition>
     *
     * @throws ManifestException
     */
    private function selectFunctions(array $functions, mixed $only): array
    {
        if (null === $only || '' === $only) {
            return $functions;
        }

        foreach ($functions as $function) {
            if ($function->functionKey === $only) {
                return [$function];
            }
        }

        throw new ManifestException(\sprintf('Function "%s" is not declared in the manifest.', (string) $only));
    }
}
