<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Conversation;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Command\AbstractCommand;
use Llmor\Cli\Console\OutputStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Example authenticated resource command. Lists conversations, exercising the
 * full signed-request + pagination pipeline end-to-end.
 */
#[AsCommand(
    name: 'conversations:list',
    description: 'List conversations.',
)]
final class ListCommand extends AbstractCommand
{
    public function __construct(private readonly LlmorClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number (1-indexed).', '1')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Items per page.', '25')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw JSON response.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);

        try {
            $response = $this->client->get('/v1/conversations', [
                'page' => (int) $input->getOption('page'),
                'page_size' => (int) $input->getOption('page-size'),
                'count' => 1,
            ]);
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        }

        if ($input->getOption('json')) {
            $output->writeln($this->encodeJson($response->body));

            return Command::SUCCESS;
        }

        $items = $response->data();
        if ([] === $items) {
            $io->info('No conversations found.');

            return Command::SUCCESS;
        }

        $columns = ['token', 'vendor_channel', 'created_at', 'modified_at'];
        $rows = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $rows[] = \array_map(fn (string $key): string => self::stringify($item[$key] ?? null), $columns);
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
