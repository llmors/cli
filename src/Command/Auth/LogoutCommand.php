<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Auth;

use Llmor\Cli\Auth\SessionStore;
use Llmor\Cli\Command\AbstractCommand;
use Llmor\Cli\Console\OutputStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'auth:logout',
    description: 'Forget the stored session (credentials in .env are kept).',
)]
final class LogoutCommand extends AbstractCommand
{
    public function __construct(private readonly SessionStore $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);

        $this->store->clear();
        $io->success('Session cleared. The next command will re-authenticate using your stored credentials.');

        return Command::SUCCESS;
    }
}
