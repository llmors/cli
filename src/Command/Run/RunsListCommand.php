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
 * List the run history of a declared function. Resolves the manifest function key
 * to its numeric id (read-only, no sync) and pages through the function's runs.
 */
#[AsCommand(
    name: 'runs:list',
    description: 'List the run history of a function from llmor.scsc.',
)]
final class RunsListCommand extends AbstractManifestCommand
{
    use RendersRun;

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The function key whose runs to list.')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number (1-indexed).', '1')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Items per page.', '25')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw JSON response.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);
        $name = (string) $input->getArgument('name');

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
                \sprintf('/v1/vendors/%d/functions/%d/runs', $vendorId, $functionId),
                [
                    'page' => (int) $input->getOption('page'),
                    'page_size' => (int) $input->getOption('page-size'),
                    'count' => 1,
                ],
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

        $items = $response->data();
        if ([] === $items) {
            $io->info(\sprintf('No runs found for "%s".', $name));

            return Command::SUCCESS;
        }

        $columns = ['id', 'status', 'took', 'memory', 'created_at'];
        $rows = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $rows[] = [
                self::stringify($item['id'] ?? null),
                $this->statusLabel((int) ($item['status'] ?? 0)),
                \sprintf('%d ms', (int) ($item['took'] ?? 0)),
                OutputStyle::humanBytes((int) ($item['memory'] ?? 0)),
                self::stringify($item['created_at'] ?? null),
            ];
        }

        $io->table($columns, $rows);

        $meta = $response->meta();
        if ([] !== $meta) {
            $io->meta(\sprintf(
                'page %s/%s · %s total',
                self::stringify($meta['page'] ?? '?'),
                self::stringify(isset($meta['total_count'], $meta['page_size']) && (int) $meta['page_size'] > 0
                    ? (int) \ceil((int) $meta['total_count'] / (int) $meta['page_size'])
                    : '?'),
                self::stringify($meta['total_count'] ?? '?'),
            ));
        }

        return Command::SUCCESS;
    }
}
