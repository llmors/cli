<?php

declare(strict_types=1);

namespace Llmor\Cli;

use Llmor\Cli\Command\Auth\LoginCommand;
use Llmor\Cli\Command\Auth\LogoutCommand;
use Llmor\Cli\Command\Auth\WhoamiCommand;
use Llmor\Cli\Command\Conversation\ListCommand as ConversationListCommand;
use Llmor\Cli\Command\Run\RunCommand;
use Llmor\Cli\Command\Run\RunsListCommand;
use Llmor\Cli\Command\Run\RunsShowCommand;
use Llmor\Cli\Command\SelfUpdate\SelfUpdateCommand;
use Llmor\Cli\Command\Sync\SyncCommand;
use Llmor\Cli\Config\ConfigResolver;
use Phar;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * The llmor CLI application.
 *
 * Resolves configuration, wires the service container and registers commands.
 * Both the {@see Services} container and the {@see ConfigResolver} are
 * injectable so functional tests can drive the app with a mocked HTTP client.
 */
final class Application extends BaseApplication
{
    public const string NAME = 'llmor';
    public const string VERSION = '1.0.0';

    public function __construct(?Services $services = null, ?ConfigResolver $resolver = null)
    {
        parent::__construct(self::NAME, self::VERSION);

        $resolver ??= ConfigResolver::fromEnvironment();
        $services ??= new Services($resolver->load());

        $workingDir = \getcwd() ?: '.';

        $this->addCommands([
            new LoginCommand($resolver),
            new LogoutCommand($services->store),
            new WhoamiCommand($services->client),
            new ConversationListCommand($services->client),
            new SyncCommand($services->client, $services->config->vendor, $workingDir),
            new RunCommand($services->client, $services->config->vendor, $workingDir),
            new RunsListCommand($services->client, $services->config->vendor, $workingDir),
            new RunsShowCommand($services->client, $services->config->vendor, $workingDir),
            new SelfUpdateCommand($services->http, self::VERSION, Phar::running(false) ?: null),
        ]);
    }
}
