<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Auth;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractCommand;
use Llmor\Cli\Config\ConfigResolver;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Config\EnvFile;
use Llmor\Cli\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'auth:login',
    description: 'Store llmor credentials and verify they work.',
)]
final class LoginCommand extends AbstractCommand
{
    public function __construct(private readonly ConfigResolver $resolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('global', 'g', InputOption::VALUE_NONE, 'Store credentials in ~/.llmor instead of the project directory.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'API host (e.g. https://llmor.com).')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Login email / identifier.')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Login password / secret.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory = $input->getOption('global')
            ? $this->resolver->homeDirectory()
            : $this->resolver->projectDirectory();

        $existing = EnvFile::parse($directory.\DIRECTORY_SEPARATOR.'.env');

        $host = $this->resolveValue(
            $input->getOption('host'),
            fn (): string => (string) $io->ask('API host', $existing['LLMOR_HOST'] ?? ConfigResolver::DEFAULT_HOST),
        );
        $host = \rtrim($host, '/');

        $email = $this->resolveValue(
            $input->getOption('email'),
            fn (): string => (string) $io->ask('Email', $existing['LLMOR_IDENTIFIER'] ?? null),
        );

        $password = $this->resolveValue(
            $input->getOption('password'),
            function () use ($io): string {
                $question = (new Question('Password'))->setHidden(true)->setHiddenFallback(false);

                return (string) $io->askQuestion($question);
            },
        );

        if ('' === $email || '' === $password) {
            $io->error('Both an email and a password are required.');

            return Command::FAILURE;
        }

        $config = new Configuration($host, $email, $password, $directory);

        $io->section('Verifying credentials');
        $services = new Services($config);

        try {
            $services->sessions->reset();
            $response = $services->client->get('/v1/auth/session/user');
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        }

        EnvFile::write($config->envFile(), [
            'LLMOR_HOST' => $host,
            'LLMOR_IDENTIFIER' => $email,
            'LLMOR_SECRET' => $password,
        ]);

        $user = $response->data();
        $name = \trim(self::stringify($user['firstname'] ?? '').' '.self::stringify($user['lastname'] ?? ''));

        $io->success(\sprintf(
            'Signed in as %s. Credentials saved to %s',
            '' !== $name ? $name.' <'.$email.'>' : $email,
            $config->envFile(),
        ));

        return Command::SUCCESS;
    }

    /**
     * Use the provided option value when given, otherwise prompt interactively.
     *
     * @param callable(): string $prompt
     */
    private function resolveValue(mixed $optionValue, callable $prompt): string
    {
        if (\is_string($optionValue) && '' !== $optionValue) {
            return $optionValue;
        }

        return $prompt();
    }
}
