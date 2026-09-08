<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Model;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractVendorCommand;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Sync\Json;
use Llmor\Cli\Sync\ModelResolver;
use Llmor\Cli\Sync\SyncException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The models this vendor has installed — the names an app's `[model]` accepts.
 *
 * A manifest names a model, never its id ({@see ModelResolver}), so
 * without this the only way to learn those names from the CLI was to get one wrong and
 * read them out of the resulting error.
 */
#[AsCommand(
    name: 'models:list',
    description: 'List the completion models available to this vendor.',
)]
final class ListCommand extends AbstractVendorCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Model type: chat_completion, embedding, or all.', ModelResolver::TYPE)
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Only models whose name contains this.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw JSON response.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);
        $type = \trim((string) $input->getOption('type'));
        $search = \trim((string) $input->getOption('search'));

        try {
            $vendorId = $this->resolveVendorId();
            $models = (new ModelResolver($this->client, $vendorId))->list(
                '' === $type || 'all' === $type ? null : $type,
                '' === $search ? null : $search,
            );
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        } catch (SyncException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln($this->encodeJson(['data' => $models]));

            return Command::SUCCESS;
        }

        if ([] === $models) {
            $io->info('all' === $type
                ? 'This vendor has no models yet.'
                : \sprintf('This vendor has no %s models yet.', $type));

            return Command::SUCCESS;
        }

        $this->render($io, $models, $type);

        return Command::SUCCESS;
    }

    /**
     * @param non-empty-list<array<string, mixed>> $models
     */
    private function render(OutputStyle $io, array $models, string $type): void
    {
        // A `default` flag every model carries says nothing (the server only enforces
        // one-per-type on write, and older catalogues are full of them), and a token
        // balance is meaningless unless the model is prepaid — so each of those columns
        // only appears when it actually distinguishes something.
        $default = self::soleDefault($models);
        $columns = $this->columns($models, $default, 'all' === $type);

        $rows = [];
        foreach ($models as $model) {
            $row = [];
            foreach ($columns as $value) {
                $row[] = $value($model);
            }
            $rows[] = $row;
        }

        $io->table(\array_keys($columns), $rows);

        $io->meta(\sprintf(
            '%d model(s) · type %s%s',
            \count($models),
            'all' === $type ? 'any' : $type,
            null === $default ? '' : ' · ● default',
        ));

        $io->hint(\sprintf(
            "[model] = '%s'   in llmor.scsc — the name, not the id.",
            $default ?? Json::stringOf($models[0]['name'] ?? null, '(unnamed)'),
        ));
    }

    /**
     * The table, as a header => cell-renderer map.
     *
     * @param non-empty-list<array<string, mixed>> $models
     *
     * @return non-empty-array<string, callable(array<string, mixed>): string>
     */
    private function columns(array $models, ?string $default, bool $showType): array
    {
        $columns = [];

        if (null !== $default) {
            $columns[''] = static fn (array $model): string => self::isDefault($model) ? '<accent>●</accent>' : '';
        }

        $columns['name'] = static fn (array $model): string => OutputFormatter::escape(Json::stringOf($model['name'] ?? null, '(unnamed)'))
            .(self::isExpired($model) ? ' <bad>(expired)</bad>' : '');
        $columns['id'] = static fn (array $model): string => Json::stringOf($model['id'] ?? null);

        if ($showType) {
            $columns['type'] = static fn (array $model): string => Json::stringOf($model['type'] ?? null);
        }

        $columns['cost / 1k'] = static fn (array $model): string => self::cost($model);

        if (self::any($models, static fn (array $model): bool => true === ($model['prepaid'] ?? null))) {
            $columns['tokens'] = static fn (array $model): string => \number_format(Json::intOf($model['available_tokens'] ?? null));
        }

        // Only a platform-admin token sees `parameters` — but when it does, the upstream
        // model is the one thing that tells four same-priced entries apart.
        if (self::any($models, static fn (array $model): bool => '' !== self::upstream($model))) {
            $columns['upstream'] = static fn (array $model): string => OutputFormatter::escape(self::upstream($model));
        }

        return $columns;
    }

    /**
     * The name of the one model flagged as default, or null when none or several are.
     *
     * @param list<array<string, mixed>> $models
     */
    private static function soleDefault(array $models): ?string
    {
        $names = [];
        foreach ($models as $model) {
            if (self::isDefault($model)) {
                $names[] = Json::stringOf($model['name'] ?? null, '(unnamed)');
            }
        }

        return 1 === \count($names) ? $names[0] : null;
    }

    /**
     * @param list<array<string, mixed>>           $models
     * @param callable(array<string, mixed>): bool $predicate
     */
    private static function any(array $models, callable $predicate): bool
    {
        foreach ($models as $model) {
            if ($predicate($model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $model
     */
    private static function upstream(array $model): string
    {
        return Json::stringOf(Json::mapOf($model['parameters'] ?? null)['model'] ?? null);
    }

    /**
     * @param array<string, mixed> $model
     */
    private static function cost(array $model): string
    {
        $formatted = Json::stringOf($model['cost_per_1k_formatted'] ?? null);
        if ('' !== $formatted) {
            return $formatted;
        }

        return \rtrim(\rtrim(\number_format(Json::intOf($model['cost_per_1k_micros'] ?? null) / 1000000, 6), '0'), '.');
    }

    /**
     * @param array<string, mixed> $model
     */
    private static function isDefault(array $model): bool
    {
        $value = $model['default'] ?? null;

        return true === $value || 1 === Json::intOf($value, 0);
    }

    /**
     * Expired models are still listed by the API, but the runtime refuses them — so
     * saying so here saves a puzzling failure at generation time.
     *
     * @param array<string, mixed> $model
     */
    private static function isExpired(array $model): bool
    {
        $expires = Json::stringOf($model['expires_at'] ?? null);
        if ('' === $expires) {
            return false;
        }

        $timestamp = \strtotime($expires);

        return false !== $timestamp && $timestamp < \time();
    }
}
