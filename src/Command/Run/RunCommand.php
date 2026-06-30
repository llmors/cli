<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Run;

use JsonException;
use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractManifestCommand;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Manifest\FunctionDefinition;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Sync\SyncError;
use Llmor\Cli\Sync\SyncErrorFactory;
use Llmor\Cli\Sync\SyncException;
use Llmor\Cli\Sync\SyncResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sync a declared function, then execute it inline via the build endpoint and
 * print the run result. The build runs against the function's persisted files,
 * which is why the function is synced first (unless `--no-sync`).
 */
#[AsCommand(
    name: 'run',
    description: 'Sync then execute a function from llmor.scsc and print the result.',
)]
final class RunCommand extends AbstractManifestCommand
{
    use RendersRun;

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The function key to run. Omit to list the available functions.')
            ->addOption('arg', 'a', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Argument as key=value (repeatable).')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Config as key=value (repeatable).')
            ->addOption('input', null, InputOption::VALUE_REQUIRED, 'Arguments as a JSON object (merged under --arg).')
            ->addOption('config-json', null, InputOption::VALUE_REQUIRED, 'Config as a JSON object (merged under --config).')
            ->addOption('no-sync', null, InputOption::VALUE_NONE, 'Skip syncing; run the already-synced function.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw run response as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);
        $name = $input->getArgument('name');

        if (null === $name || '' === $name) {
            return $this->listFunctions($io, $output, (bool) $input->getOption('json'));
        }
        $name = (string) $name;

        try {
            $manifest = $this->loadManifest();
            $function = $manifest->get($name);
            if (null === $function) {
                $io->error(\sprintf('Function "%s" is not declared in the manifest.', $name));

                return Command::FAILURE;
            }

            $arguments = $this->mergeInputs($input->getOption('input'), $input->getOption('arg'));
            $config = $this->mergeInputs($input->getOption('config-json'), $input->getOption('config'));

            $vendorId = $this->resolveVendorId();
            $synchronizer = $this->synchronizer($vendorId);

            if ($input->getOption('no-sync')) {
                $existing = $synchronizer->findFunction($function->functionKey);
                if (null === $existing) {
                    $io->error(\sprintf('Function "%s" has not been synced yet; run without --no-sync first.', $name));

                    return Command::FAILURE;
                }
                $functionId = (int) $existing['id'];
            } else {
                $result = $synchronizer->sync($function);
                if (null === $result->functionId) {
                    $io->error('Could not resolve the function id after syncing.');

                    return Command::FAILURE;
                }
                $functionId = $result->functionId;
                if ($result->fileChangeCount() > 0 || SyncResult::UNCHANGED !== $result->functionAction) {
                    $io->note(\sprintf('Synced %s (%s).', $function->functionKey, $result->functionAction));
                }
            }

            $response = $this->client->post(
                \sprintf('/v1/vendors/%d/functions/%d/build', $vendorId, $functionId),
                [
                    'code' => $function->readCode(),
                    'arguments' => (object) $arguments,
                    'config' => (object) $config,
                ],
            );
        } catch (ManifestException|SyncException|ApiException|JsonException $e) {
            $scope = match (true) {
                $e instanceof ManifestException => SyncError::SCOPE_MANIFEST,
                $e instanceof JsonException => SyncError::SCOPE_INPUT,
                default => SyncError::SCOPE_FUNCTION,
            };
            $error = SyncErrorFactory::fromThrowable($e, $name, $scope);

            if ($input->getOption('json')) {
                $output->writeln($this->encodeJson(['ok' => false, 'errors' => [$error->toArray()]]));
            } else {
                $this->renderSyncError($io, $error);
            }

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln($this->encodeJson($response->body));
        } else {
            $this->renderRun($io, $name, $response->data());
        }

        $status = (int) ($response->data()['status'] ?? 0);

        return 1 === $status ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The no-argument behaviour: list the functions declared in the manifest.
     */
    private function listFunctions(OutputStyle $io, OutputInterface $output, bool $json): int
    {
        try {
            $manifest = $this->loadManifest();
        } catch (ManifestException $e) {
            $error = SyncErrorFactory::fromThrowable($e, null, SyncError::SCOPE_MANIFEST);
            if ($json) {
                $output->writeln($this->encodeJson(['ok' => false, 'errors' => [$error->toArray()]]));
            } else {
                $this->renderSyncError($io, $error);
            }

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln($this->encodeJson(\array_map(static fn (FunctionDefinition $f): array => [
                'function_key' => $f->functionKey,
                'name' => $f->name,
                'runtime' => $f->runtime,
                'description' => $f->description,
            ], $manifest->functions)));

            return Command::SUCCESS;
        }

        if ([] === $manifest->functions) {
            $io->info('No functions are declared in llmor.scsc.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($manifest->functions as $function) {
            $rows[] = [$function->functionKey, $function->runtime, self::truncate($function->description, 60)];
        }

        $io->table(['function', 'runtime', 'description'], $rows);
        $io->meta(\sprintf('%d function(s) · run one with  llmor run <key>', \count($manifest->functions)));

        return Command::SUCCESS;
    }

    private static function truncate(string $text, int $max): string
    {
        $text = \trim(\preg_replace('/\s+/', ' ', $text) ?? $text);

        return \mb_strlen($text) > $max ? \mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * Merge `key=value` pairs over an optional JSON object, the pairs winning.
     *
     * @param array<int, string> $pairs
     *
     * @return array<string, mixed>
     *
     * @throws JsonException when the JSON is invalid or not an object
     */
    private function mergeInputs(mixed $json, array $pairs): array
    {
        $base = [];
        if (\is_string($json) && '' !== $json) {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                throw new JsonException('Expected a JSON object.');
            }
            $base = $decoded;
        }

        foreach ($pairs as $pair) {
            $eq = \strpos($pair, '=');
            if (false === $eq) {
                throw new JsonException(\sprintf('Invalid key=value pair: "%s".', $pair));
            }
            $base[\substr($pair, 0, $eq)] = \substr($pair, $eq + 1);
        }

        return $base;
    }
}
