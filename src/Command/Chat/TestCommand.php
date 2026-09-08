<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Chat;

use JsonException;
use Llmor\Cli\Chat\AskUserPrompter;
use Llmor\Cli\Chat\ConversationSession;
use Llmor\Cli\Chat\TurnRenderer;
use Llmor\Cli\Chat\TurnRunner;
use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Command\AbstractManifestCommand;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Relay\ConversationRelay;
use Llmor\Cli\Relay\RelayEndpoint;
use Llmor\Cli\Relay\RelayException;
use Llmor\Cli\Sync\SyncException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Talk to a declared app from the terminal — the missing last step of the
 * `edit llmor.scsc → llmor sync → ?` loop.
 *
 * With a message it runs one turn and exits, which makes it scriptable; without one it
 * opens a REPL. Either way the model's answer streams in live over the conversation
 * relay, with tool calls shown as they run.
 *
 * App resolution here is strictly read-only: `test` binds a declaration to an existing
 * remote app but never creates one. Creating an app as a side effect of testing it would
 * leave an orphan the CLI cannot delete (DELETE needs super-admin), so an unsynced
 * declaration is an error pointing at `llmor sync`.
 */
#[AsCommand(
    name: 'test',
    description: 'Chat with an app from llmor.scsc — a REPL, or one turn from the arguments.',
)]
final class TestCommand extends AbstractManifestCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('app', InputArgument::OPTIONAL, 'The app declaration to talk to. Omit to list the declared apps.')
            ->addArgument('message', InputArgument::IS_ARRAY, 'Send this message, print the answer and exit. Omit for a REPL.')
            ->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'Talk to a numeric app id, ignoring the manifest.')
            ->addOption('conversation', null, InputOption::VALUE_REQUIRED, 'Resume an existing conversation ({appId}-{token}).')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'vendor_channel to tag a new conversation with.')
            ->addOption('param', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Conversation parameter as key=value (repeatable).')
            ->addOption('param-json', null, InputOption::VALUE_REQUIRED, 'Conversation parameters as a JSON object (merged under --param).')
            ->addOption('no-stream', null, InputOption::VALUE_NONE, 'Do not connect the relay; print each turn once it completes.')
            ->addOption('relay-url', null, InputOption::VALUE_REQUIRED, 'Override the relay URL reported by the API.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One-shot only: print the raw interact response.')
            ->setHelp(<<<'HELP'
                Chat with an app declared in <info>llmor.scsc</info>.

                  <info>llmor test support_bot</info>                  open a REPL
                  <info>llmor test support_bot how do I reset?</info>  one turn, then exit
                  <info>llmor test --app-id 17 ping</info>             skip the manifest

                In the REPL: <info>/exit</info>, <info>/new</info>, <info>/history</info>, <info>/token</info>, <info>/json</info>, <info>/help</info>.

                Ctrl+C during a turn asks the server to stop generating. The runtime checks
                for that between function-call iterations, so it takes effect at the next
                iteration boundary rather than mid-sentence.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);

        /** @var list<string> $words */
        $words = $input->getArgument('message');
        $message = \trim(\implode(' ', $words));
        $json = (bool) $input->getOption('json');

        try {
            $target = $this->resolveTarget($input, $io);
            if (null === $target) {
                return $this->listApps($io, $output, $json);
            }

            $session = $this->openSession($input, $target);
            // `--json` prints the raw response and renders nothing, so there is nothing
            // for a live stream to draw on — don't open a socket to ignore
            $stream = !$input->getOption('no-stream') && !$json;
            $relay = $stream ? $this->connectRelay($io, $session, $input->getOption('relay-url')) : null;
        } catch (ManifestException|SyncException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        } catch (JsonException $e) {
            $io->error(\sprintf('Invalid parameters: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $renderer = new TurnRenderer($io);
        $runner = new TurnRunner($renderer, $session, $relay);
        $this->trapInterrupts($runner);

        try {
            return '' !== $message
                ? $this->runOnce($io, $output, $session, $runner, $message, $json)
                : $this->runRepl($io, $input, $session, $runner, $renderer, $target, $relay, $stream);
        } finally {
            $relay?->close();
        }
    }

    // -- one-shot ---------------------------------------------------------------

    private function runOnce(
        OutputStyle $io,
        OutputInterface $output,
        ConversationSession $session,
        TurnRunner $runner,
        string $message,
        bool $json,
    ): int {
        if (!$json) {
            $io->writeln(\sprintf('<accent>you ›</accent> %s', $message));
        }

        // `--json` renders nothing, so it pumps the turn without closing it on screen
        try {
            $pendingRequest = $session->send($message, $runner->isLive());
            $response = $json ? $runner->run($pendingRequest) : $runner->turn($pendingRequest);
        } catch (ApiException $e) {
            return $this->renderApiError($io, $e);
        }

        if ($json) {
            $output->writeln($this->encodeJson($response->body));

            return Command::SUCCESS;
        }

        $pending = ConversationSession::pendingAskUser($response->body);
        if ([] !== $pending) {
            // one-shot has no way to answer, and silently reporting success would hide
            // that the turn never actually finished
            (new AskUserPrompter($io))->describe($pending);
            $io->meta(\sprintf('conversation %s', $session->token()));

            return Command::FAILURE;
        }

        $io->meta(\sprintf('conversation %s', $session->token()));

        return Command::SUCCESS;
    }

    // -- REPL -------------------------------------------------------------------

    private function runRepl(
        OutputStyle $io,
        InputInterface $input,
        ConversationSession $session,
        TurnRunner $runner,
        TurnRenderer $renderer,
        AppTarget $target,
        ?ConversationRelay $relay,
        bool $stream,
    ): int {
        $this->renderHeader($io, $target, $session, $relay, $stream);

        $prompter = new AskUserPrompter($io);
        $lastBody = [];

        while (true) {
            $io->newLine();

            try {
                $answer = $io->askQuestion(new Question('<accent>you ›</accent> '));
            } catch (MissingInputException) {
                // Ctrl+D, or a stdin that has run out (a piped script, a test) — the
                // same thing as /exit. Without this the loop would spin forever on an
                // input stream that will never produce another line.
                $io->newLine();

                return Command::SUCCESS;
            }

            $line = \is_string($answer) ? \trim($answer) : '';
            if ('' === $line) {
                continue;
            }

            if (\str_starts_with($line, '/')) {
                $outcome = $this->handleMetaCommand($io, $line, $session, $renderer, $lastBody);
                if (self::META_EXIT === $outcome) {
                    return Command::SUCCESS;
                }
                if (self::META_RESET === $outcome) {
                    try {
                        $session = ConversationSession::create($this->client, $target->id, self::stringOption($input, 'channel'), $this->parameters($input));
                        $io->note(\sprintf('New conversation %s.', $session->token()));
                    } catch (ApiException|JsonException $e) {
                        $io->error($e->getMessage());
                    }
                }

                continue;
            }

            try {
                $lastBody = $runner->turn($session->send($line, $runner->isLive()))->body;

                $this->resolveAskUser($prompter, $session, $runner, $lastBody);
            } catch (ApiException $e) {
                $this->renderApiError($io, $e);
            }

            if ($runner->wasInterrupted()) {
                $io->note('Interrupted.');
            }
        }
    }

    /**
     * Keep answering ask-user batches until the model stops asking.
     *
     * @param array<string, mixed> $lastBody
     */
    private function resolveAskUser(
        AskUserPrompter $prompter,
        ConversationSession $session,
        TurnRunner $runner,
        array &$lastBody,
    ): void {
        while ([] !== ($pending = ConversationSession::pendingAskUser($lastBody))) {
            $answers = $prompter->ask($pending);
            $live = $runner->isLive();
            // an empty batch means the user answered nothing at all — cancel the
            // prompts so the conversation leaves `waiting_for_ask_user` instead of
            // staying suspended with no way back
            $lastBody = $runner->turn([] === $answers
                ? $session->cancelAskUser($live)
                : $session->answerAskUser($answers, $live))->body;
        }
    }

    private const META_CONTINUE = 0;
    private const META_EXIT = 1;
    private const META_RESET = 2;

    /**
     * @param array<string, mixed> $lastBody
     */
    private function handleMetaCommand(
        OutputStyle $io,
        string $line,
        ConversationSession $session,
        TurnRenderer $renderer,
        array $lastBody,
    ): int {
        $command = \strtolower(\strtok($line, " \t") ?: $line);

        switch ($command) {
            case '/exit':
            case '/quit':
            case '/q':
                return self::META_EXIT;

            case '/new':
                return self::META_RESET;

            case '/token':
                $io->note($session->token());

                return self::META_CONTINUE;

            case '/history':
                try {
                    $renderer->renderHistory(ConversationSession::messagesIn($session->fetch()->body));
                } catch (ApiException $e) {
                    $this->renderApiError($io, $e);
                }

                return self::META_CONTINUE;

            case '/json':
                $io->writeln([] === $lastBody ? '<muted>Nothing sent yet.</muted>' : $this->encodeJson($lastBody));

                return self::META_CONTINUE;

            case '/help':
            case '/?':
                $io->kv([
                    '/exit' => 'leave the REPL (Ctrl+D works too)',
                    '/new' => 'start a fresh conversation with the same app',
                    '/history' => 'reprint the conversation from the server',
                    '/token' => 'print the conversation token',
                    '/json' => 'dump the last raw interact response',
                ]);

                return self::META_CONTINUE;

            default:
                $io->warning(\sprintf('Unknown command "%s". Try /help.', $command));

                return self::META_CONTINUE;
        }
    }

    // -- setup ------------------------------------------------------------------

    /**
     * Which app to talk to, or null when the command should just list them.
     *
     * @throws ManifestException|SyncException|ApiException
     */
    private function resolveTarget(InputInterface $input, OutputStyle $io): ?AppTarget
    {
        $appId = self::stringOption($input, 'app-id');
        if (null !== $appId) {
            if (!\ctype_digit($appId)) {
                throw new SyncException(\sprintf('--app-id expects a numeric app id, got "%s".', $appId));
            }

            return new AppTarget((int) $appId, \sprintf('app #%s', $appId), null);
        }

        $name = $input->getArgument('app');
        if (null === $name || '' === $name) {
            // resuming needs no app: the conversation already knows which one it belongs to
            return null !== self::stringOption($input, 'conversation')
                ? new AppTarget(0, 'conversation', null)
                : null;
        }
        $name = (string) $name;

        $manifest = $this->loadManifest();
        $app = $manifest->getApp($name);
        if (null === $app) {
            throw new SyncException(\sprintf('App "%s" is not declared in the manifest.', $name));
        }

        // a read-only lock: resolving must not rewrite llmor.lock just because we looked
        $resolved = $this->appResolver($this->lockFile($manifest, true), $this->resolveVendorId())->resolve($app);

        foreach ($resolved->warnings as $warning) {
            $io->warning($warning);
        }

        if (null === $resolved->id) {
            throw new SyncException(\sprintf('App "%s" has not been synced yet — run `llmor sync --app %s` first.', $name, $name));
        }

        return new AppTarget($resolved->id, $app->name ?? $name, $app);
    }

    /**
     * @throws ApiException|JsonException
     */
    private function openSession(InputInterface $input, AppTarget $target): ConversationSession
    {
        $resume = self::stringOption($input, 'conversation');

        return null !== $resume
            ? ConversationSession::resume($this->client, $resume)
            : ConversationSession::create($this->client, $target->id, self::stringOption($input, 'channel'), $this->parameters($input));
    }

    /**
     * Subscribe to the live stream, or explain why we could not and carry on.
     *
     * A relay we cannot reach costs the live preview, nothing else — the turn's real
     * result still comes back over HTTP — so this warns rather than failing.
     */
    private function connectRelay(OutputStyle $io, ConversationSession $session, mixed $override): ?ConversationRelay
    {
        try {
            $endpoint = RelayEndpoint::fromConversation($session->record(), \is_string($override) ? $override : null);

            return ConversationRelay::connect($endpoint, $session->token());
        } catch (RelayException $e) {
            $io->warning(\sprintf('Live streaming is off: %s', $e->getMessage()));
            $io->hint('Answers will still appear, but only once each turn completes.');

            return null;
        }
    }

    private function renderHeader(OutputStyle $io, AppTarget $target, ConversationSession $session, ?ConversationRelay $relay, bool $stream): void
    {
        $io->newLine();
        $io->writeln(\sprintf('<accent>●</accent> <options=bold>%s</>%s', $target->label, $target->id > 0 ? \sprintf('  <muted>#%d</muted>', $target->id) : ''));

        $pairs = ['conversation' => $session->token()];
        if (null !== $target->definition?->model) {
            $pairs['model'] = $target->definition->model;
        }
        $pairs['streaming'] = match (true) {
            !$stream => 'off (--no-stream)',
            null === $relay => 'unavailable',
            default => 'live',
        };

        $io->kv($pairs);
        $io->meta('Type a message, or /help for commands. Ctrl+D to leave.');
    }

    /**
     * The no-argument behaviour: list the apps declared in the manifest.
     */
    private function listApps(OutputStyle $io, OutputInterface $output, bool $json): int
    {
        try {
            $manifest = $this->loadManifest();
        } catch (ManifestException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln($this->encodeJson(\array_map(static fn (AppDefinition $a): array => [
                'declaration' => $a->declaration,
                'app_type' => $a->appType,
                'name' => $a->name,
                'model' => $a->model,
            ], $manifest->apps)));

            return Command::SUCCESS;
        }

        if ([] === $manifest->apps) {
            $io->info('No apps are declared in llmor.scsc.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($manifest->apps as $app) {
            $rows[] = [$app->declaration, $app->appType, $app->name ?? '', $app->model ?? ''];
        }

        $io->table(['app', 'app_type', 'name', 'model'], $rows);
        $io->meta(\sprintf('%d app(s) · talk to one with  llmor test <app>', \count($manifest->apps)));

        return Command::SUCCESS;
    }

    /**
     * Ctrl+C asks the server to stop the current turn instead of killing the CLI.
     *
     * A second press within the same turn gets the default behaviour back, so the
     * process is never unkillable if the relay is not listening.
     */
    private function trapInterrupts(TurnRunner $runner): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }

        \pcntl_async_signals(true);
        \pcntl_signal(\SIGINT, static function () use ($runner): void {
            if ($runner->wasInterrupted()) {
                exit(130);
            }

            $runner->interrupt();
        });
    }

    /**
     * Conversation `parameters`, from `--param-json` with `--param` pairs layered on top.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function parameters(InputInterface $input): array
    {
        /** @var list<string> $pairs */
        $pairs = $input->getOption('param');

        return $this->mergeKeyValues($input->getOption('param-json'), $pairs);
    }
}
