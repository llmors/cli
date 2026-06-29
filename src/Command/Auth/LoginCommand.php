<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Auth;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractCommand;
use Llmor\Cli\Config\ConfigResolver;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Config\EnvFile;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Services;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

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
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Login password / secret.')
            ->addOption('vendor', null, InputOption::VALUE_REQUIRED, 'Vendor key to act as (sent as the X-Vendor header).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);

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

        $vendor = $this->resolveValue(
            $input->getOption('vendor'),
            fn (): string => (string) $io->ask('Vendor key (optional, press enter to skip)', $existing['LLMOR_VENDOR'] ?? null),
        );

        $config = new Configuration($host, $email, $password, '' !== $vendor ? $vendor : null, $directory);

        $io->section('Verifying credentials');
        $services = new Services($config);

        try {
            $services->sessions->reset();
            $response = $services->client->get('/v1/auth/session/user');
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        }

        $values = [
            'LLMOR_HOST' => $host,
            'LLMOR_IDENTIFIER' => $email,
            'LLMOR_SECRET' => $password,
        ];
        if (null !== $config->vendor) {
            $values['LLMOR_VENDOR'] = $config->vendor;
        }
        EnvFile::write($config->envFile(), $values);

        $body = $response->body;
        $user = isset($body['user']) && \is_array($body['user']) ? $body['user'] : $response->data();
        $name = \trim(self::stringify($user['firstname'] ?? '').' '.self::stringify($user['lastname'] ?? ''));

        $io->success(\sprintf(
            'Signed in as %s%s. Credentials saved to %s',
            '' !== $name ? $name.' <'.$email.'>' : $email,
            null !== $config->vendor ? ' (vendor: '.$config->vendor.')' : '',
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
