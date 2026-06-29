<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Auth;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Command\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'auth:whoami',
    description: 'Show the currently authenticated user.',
)]
final class WhoamiCommand extends AbstractCommand
{
    public function __construct(private readonly LlmorClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the raw JSON response.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $response = $this->client->get('/v1/auth/session/user');
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        }

        if ($input->getOption('json')) {
            $output->writeln($this->encodeJson($response->body));

            return Command::SUCCESS;
        }

        // GET /v1/auth/session/user returns the user under a "user" key; fall
        // back to the response payload for other envelope shapes.
        $body = $response->body;
        $user = isset($body['user']) && \is_array($body['user']) ? $body['user'] : $response->data();

        $rows = [];
        foreach (['id', 'email', 'firstname', 'lastname', 'locale', 'currency', 'is_su', 'is_active', 'last_signin'] as $key) {
            if (\array_key_exists($key, $user)) {
                $rows[] = [$key, self::stringify($user[$key])];
            }
        }

        $io->title('Authenticated user');
        $io->table(['Field', 'Value'], $rows);

        return Command::SUCCESS;
    }
}
