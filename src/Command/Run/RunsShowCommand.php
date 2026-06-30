<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Run;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractManifestCommand;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Sync\SyncException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Inspect a single persisted run of a declared function: status, timing, return
 * value, console output and any error. Resolves the manifest function key to its
 * numeric id (read-only, no sync).
 */
#[AsCommand(
    name: 'runs:show',
    description: 'Inspect a single run of a function from llmor.scsc.',
)]
final class RunsShowCommand extends AbstractManifestCommand
{
    use RendersRun;

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The function key the run belongs to.')
            ->addArgument('runId', InputArgument::REQUIRED, 'The id of the run to inspect.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw JSON response.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);
        $name = (string) $input->getArgument('name');
        $runId = (string) $input->getArgument('runId');

        try {
            $manifest = $this->loadManifest();
            $function = $manifest->get($name);
            if (null === $function) {
                $io->error(\sprintf('Function "%s" is not declared in the manifest.', $name));

                return Command::FAILURE;
            }

            $vendorId = $this->resolveVendorId();
            $existing = $this->synchronizer($vendorId)->findFunction($function->functionKey);
            if (null === $existing) {
                $io->error(\sprintf('Function "%s" has not been synced yet; run "llmor sync" first.', $name));

                return Command::FAILURE;
            }
            $functionId = (int) $existing['id'];

            $response = $this->client->get(
                \sprintf('/v1/vendors/%d/functions/%d/runs/%s', $vendorId, $functionId, \rawurlencode($runId)),
            );
        } catch (ManifestException|SyncException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        }

        if ($input->getOption('json')) {
            $output->writeln($this->encodeJson($response->body));

            return Command::SUCCESS;
        }

        $run = $response->data();
        $this->renderRun($io, \sprintf('%s #%s', $name, $runId), $run);

        $status = (int) ($run['status'] ?? 0);

        return 1 === $status ? Command::SUCCESS : Command::FAILURE;
    }
}
