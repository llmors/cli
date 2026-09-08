<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Apps;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractManifestCommand;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Import\AppImporter;
use Llmor\Cli\Import\DeclarationNamer;
use Llmor\Cli\Import\ImportException;
use Llmor\Cli\Import\ImportPlan;
use Llmor\Cli\Import\RemoteAppMapper;
use Llmor\Cli\Import\RemoteAppReader;
use Llmor\Cli\Import\SubagentTargetNamer;
use Llmor\Cli\Manifest\Manifest;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\Writer\AppDeclarationWriter;
use Llmor\Cli\Manifest\Writer\ManifestAppender;
use Llmor\Cli\Manifest\Writer\ValueExtractor;
use Llmor\Cli\Sync\AppLockFile;
use Llmor\Cli\Sync\Json;
use Llmor\Cli\Sync\ModelResolver;
use Llmor\Cli\Sync\RemoteAppIndex;
use Llmor\Cli\Sync\SyncException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bring an app that already exists on llmor.com under manifest control.
 *
 * The reverse of `sync` for apps, and the answer to how a project adopts something
 * built in the console: reading the record, writing the declaration it would have been
 * parsed from, appending that to `llmor.scsc`, and recording the id in `llmor.lock`.
 *
 * The bar it holds itself to is that `llmor sync --app <declaration> --dry-run`
 * immediately afterwards reports **unchanged** — which is why the full parameter bag is
 * imported, server-seeded defaults included, rather than a tidier subset.
 */
#[AsCommand(
    name: 'apps:import',
    description: 'Import an existing app from llmor.com into llmor.scsc.',
)]
final class ImportCommand extends AbstractManifestCommand
{
    private ?SubagentTargetNamer $declaredIds = null;

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'The app id, as shown in the console. Omit to pick from a list.')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'Declaration name to use (default: derived from the app name).')
            ->addOption('inline', null, InputOption::VALUE_NONE, 'Keep long parameters inline instead of extracting them to files beside the manifest.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Import even when the app is already declared. Requires --as.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the declaration without writing anything.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $manifest = $this->loadManifestOrEmpty();
            $vendorId = $this->resolveVendorId();
            $lock = $this->lockFile($manifest);

            $reader = new RemoteAppReader($this->client, $vendorId);

            $appId = $this->resolveAppId($input, $io, $manifest, $lock, $vendorId, $json);
            if (null === $appId) {
                return Command::SUCCESS;
            }

            $declaration = $this->resolveDeclaration($input, $io, $reader, $manifest, $lock, $appId, $json);
            if (null === $declaration) {
                return Command::SUCCESS;
            }

            $importer = $this->importer($reader, $manifest, $lock, $vendorId, $input);
            $plan = $importer->plan($appId, $declaration);

            if (!$dryRun) {
                $importer->apply($plan);
            }
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        } catch (ImportException|ManifestException|SyncException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln($this->encodeJson(['dry_run' => $dryRun] + $plan->toArray()));

            return Command::SUCCESS;
        }

        $this->render($io, $output, $plan, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Which declaration already owns which remote app id, built once per run.
     *
     * Three callers need the same answer — the mapper naming a sub-agent target, the
     * picker greying out imported apps, and the "already imported" guard — and each one
     * re-walking the manifest and the lock to rebuild an identical map is pure repetition.
     */
    private function declaredIds(Manifest $manifest, AppLockFile $lock): SubagentTargetNamer
    {
        return $this->declaredIds ??= new SubagentTargetNamer($this->vendorKey(), $manifest, $lock);
    }

    private function importer(RemoteAppReader $reader, Manifest $manifest, AppLockFile $lock, int $vendorId, InputInterface $input): AppImporter
    {
        $models = new ModelResolver($this->client, $vendorId);

        return new AppImporter(
            $reader,
            new RemoteAppMapper($this->declaredIds($manifest, $lock), $models),
            // Long values go to the manifest's own [prompt_dir] — `prompts/` unless its
            // `: Config` block says otherwise.
            new AppDeclarationWriter($input->getOption('inline') ? null : new ValueExtractor($manifest->config->promptDir)),
            ManifestAppender::at($manifest->path),
            $lock,
            $this->vendorKey(),
        );
    }

    /**
     * The app to import: the `id` argument, or a pick from the vendor's apps.
     *
     * Returns null when there is nothing left to do and the command should simply
     * succeed — an empty vendor, or every app already declared.
     *
     * @throws ApiException
     * @throws ImportException
     */
    private function resolveAppId(InputInterface $input, OutputStyle $io, Manifest $manifest, AppLockFile $lock, int $vendorId, bool $json): ?int
    {
        $argument = $input->getArgument('id');
        if (null !== $argument && '' !== $argument) {
            $id = Json::idOf($argument);
            if (null === $id) {
                throw new ImportException(\sprintf('"%s" is not an app id. Pass the numeric id shown in the console, or run the command with no argument to pick from a list.', (string) $argument));
            }

            return $id;
        }

        if ($json || !$input->isInteractive()) {
            throw new ImportException('Pass an app id: llmor apps:import <id>. Run it without arguments in a terminal to pick from a list.');
        }

        return $this->pick($io, $manifest, $lock, $vendorId);
    }

    /**
     * @throws ApiException
     */
    private function pick(OutputStyle $io, Manifest $manifest, AppLockFile $lock, int $vendorId): ?int
    {
        $index = RemoteAppIndex::fetch($this->client, $vendorId);
        $records = $index->all();

        if ([] === $records) {
            $io->info('This vendor has no apps yet.');

            return null;
        }

        $declared = $this->declaredIds($manifest, $lock);

        /** @var array<string, int> $choices */
        $choices = [];
        $already = 0;

        foreach (self::sorted($records) as $record) {
            $id = Json::idOf($record['id'] ?? null);
            if (null === $id) {
                continue;
            }

            // Offering an app that is already declared would only lead to the "already
            // imported" message, so leave it out and say how many were left out.
            if (null !== $declared->nameOf($id)) {
                ++$already;
                continue;
            }

            $choices[\sprintf('#%-6d %-40s %s', $id, Json::stringOf($record['name'] ?? null, '(unnamed)'), Json::stringOf($record['app_key'] ?? null))] = $id;
        }

        if ([] === $choices) {
            $io->info(\sprintf('Every app of this vendor is already declared in %s.', \basename($manifest->path)));

            return null;
        }

        if ($already > 0) {
            $io->note(\sprintf('%d app(s) already declared in this manifest are not listed.', $already));
        }

        // SymfonyStyle::choice hands back the label, not the key, so the id is looked
        // up rather than parsed back out of the string.
        $label = $io->choice('Which app do you want to import?', \array_keys($choices));

        return $choices[$label] ?? null;
    }

    /**
     * The declaration name to import under, or null when the app is already declared
     * and there is nothing to do.
     *
     * @throws ImportException
     */
    private function resolveDeclaration(InputInterface $input, OutputStyle $io, RemoteAppReader $reader, Manifest $manifest, AppLockFile $lock, int $appId, bool $json): ?string
    {
        $namer = new DeclarationNamer($manifest);
        $force = (bool) $input->getOption('force');

        if (!$force) {
            $existing = $this->declaredIds($manifest, $lock)->nameOf($appId);
            if (null !== $existing) {
                if (!$json) {
                    $io->success(\sprintf('App #%d is already imported as "%s" — nothing to do.', $appId, $existing));
                    $io->hint(\sprintf('llmor sync --app %s   to push your local changes.', $existing));
                }

                return null;
            }
        }

        $as = self::stringOption($input, 'as');
        if (null !== $as) {
            if (!$namer->isValid($as)) {
                throw new ImportException(\sprintf('"%s" is not a valid declaration name — use letters, digits and underscores, starting with a letter or underscore.', $as));
            }

            if (null !== $namer->takenBy($as)) {
                throw new ImportException(\sprintf('The manifest already declares %s called "%s". Choose another name with --as.', $namer->describeTaken($as), $as));
            }

            return $as;
        }

        if ($force) {
            throw new ImportException('--force needs --as: the derived name is the one already in the manifest.');
        }

        return $this->settleName($input, $io, $reader, $namer, $manifest, $appId, $json);
    }

    /**
     * @throws ImportException
     */
    private function settleName(InputInterface $input, OutputStyle $io, RemoteAppReader $reader, DeclarationNamer $namer, Manifest $manifest, int $appId, bool $json): string
    {
        $record = $reader->record($appId);
        $suggested = $namer->suggest(Json::stringOf($record['name'] ?? null), Json::stringOf($record['app_key'] ?? null), $appId);

        if (null === $namer->takenBy($suggested)) {
            $this->warnAboutAdoption($io, $manifest, $record, $appId, $json);

            return $suggested;
        }

        // Auto-suffixing would put `support_bot_2` in a committed manifest without
        // anyone having decided that, so the choice is always made deliberately —
        // interactively when possible, and as an error naming the flag when not.
        if ($json || !$input->isInteractive()) {
            throw new ImportException(\sprintf('The manifest already declares %s called "%s". Re-run with --as %s.', $namer->describeTaken($suggested), $suggested, $namer->nextFree($suggested)));
        }

        $answer = $io->ask('Declaration name', $namer->nextFree($suggested), static function (?string $value) use ($namer): string {
            $value = \trim((string) $value);
            if (!$namer->isValid($value)) {
                throw new ImportException(\sprintf('"%s" is not a valid declaration name.', $value));
            }
            if (null !== $namer->takenBy($value)) {
                throw new ImportException(\sprintf('"%s" is already declared in this manifest.', $value));
            }

            return $value;
        });

        return (string) $answer;
    }

    /**
     * Warn when `sync` would have adopted this app anyway.
     *
     * Two declarations with the same `[name]` and `[app_type]` both try to adopt the same
     * record and {@see \Llmor\Cli\Sync\AppResolver} then refuses both, so it is worth
     * saying before the declaration exists rather than after.
     *
     * @param array<string, mixed> $record
     */
    private function warnAboutAdoption(OutputStyle $io, Manifest $manifest, array $record, int $appId, bool $json): void
    {
        if ($json) {
            return;
        }

        foreach ($manifest->apps as $app) {
            if (RemoteAppIndex::describes($record, $app->name, $app->appType)) {
                $io->warning(\sprintf('The manifest already declares "%s" with the same [name] and [app_type]. `llmor sync` would adopt app #%d for it, so you may not need to import at all.', $app->declaration, $appId));

                return;
            }
        }
    }

    private function render(OutputStyle $io, OutputInterface $output, ImportPlan $plan, bool $dryRun): void
    {
        if ($dryRun) {
            $io->note('Dry run — nothing was written.');
        }

        $io->newLine();
        // Raw, because a prompt full of `<tag>`-ish text would otherwise be eaten by
        // the output formatter.
        $output->writeln($plan->written->scsc, OutputInterface::OUTPUT_RAW);
        $io->newLine();

        $verb = $dryRun ? 'Would import' : 'Imported';
        $io->success(\sprintf('%s app #%d as "%s"', $verb, $plan->appId, $plan->declaration()));

        $io->kv($this->files($plan, $dryRun));

        foreach ([...$plan->warnings, ...$plan->written->notes] as $warning) {
            $io->warning($warning);
        }

        if ([] !== $plan->skipped) {
            $io->warning(\sprintf('Not imported (console-managed): %s', \implode(', ', $plan->skipped)));
        }

        $io->newLine();

        if (!$dryRun) {
            $io->note(\sprintf('Recorded the app id in %s — commit this file.', AppLockFile::FILE_NAME));
        }

        $io->hint(\sprintf('llmor sync --app %s --dry-run   to confirm nothing would change.', $plan->declaration()));
    }

    /**
     * @return array<string, string>
     */
    private function files(ImportPlan $plan, bool $dryRun): array
    {
        $rows = [\basename($plan->manifestPath) => \sprintf(
            '%s%d lines%s',
            $dryRun ? 'would add ' : '+',
            $plan->written->lineCount(),
            $plan->manifestExists ? '' : ' (new file)',
        )];

        foreach ($plan->written->files as $path => $contents) {
            $rows[$path] = OutputStyle::humanBytes(\strlen($contents));
        }

        if (!$dryRun) {
            $rows[AppLockFile::FILE_NAME] = \sprintf('%s → #%d', $plan->declaration(), $plan->appId);
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $records): array
    {
        \usort($records, static fn (array $a, array $b): int => [Json::stringOf($a['name'] ?? null), Json::intOf($a['id'] ?? null)]
            <=> [Json::stringOf($b['name'] ?? null), Json::intOf($b['id'] ?? null)]);

        return $records;
    }
}
