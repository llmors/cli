<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Chat\TestCommand;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use Llmor\Cli\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `llmor test` end to end, with the relay switched off.
 *
 * `--no-stream` keeps every assertion here about the HTTP conversation — creating,
 * interacting, rendering — while the relay transport is covered against a real socket in
 * tests/Unit/Relay.
 */
#[CoversClass(TestCommand::class)]
final class TestCommandTest extends TestCase
{
    use TempProject;

    private const MANIFEST = <<<'SCSC'
        support_bot: App {
          [app_type]    = 'llmor/generic'
          [name]        = 'Support Bot'
          [model]       = 'GPT-4'
        }
        SCSC;

    protected function setUp(): void
    {
        $this->makeProject();
        $this->writeProjectFile('llmor.scsc', self::MANIFEST);
        $this->writeProjectFile('llmor.lock', (string) \json_encode([
            'version' => 1,
            'vendors' => [TestClient::VENDOR_KEY => ['apps' => ['support_bot' => ['id' => 17, 'app_type' => 'llmor/generic']]]],
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function testRunsOneTurnFromTheArgumentsAndExits(): void
    {
        $api = $this->api();
        $tester = $this->tester($api);

        $status = $tester->execute(['app' => 'support_bot', 'message' => ['what', 'is', 'the', 'weather?'], '--no-stream' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('you › what is the weather?', $tester->getDisplay());
        self::assertStringContainsString('It is 14°C and raining in Bern.', $tester->getDisplay());
        self::assertStringContainsString('conversation 17-abc', $tester->getDisplay());
    }

    public function testCreatesTheConversationAgainstTheResolvedAppId(): void
    {
        $api = $this->api();
        $this->tester($api)->execute(['app' => 'support_bot', 'message' => ['hi'], '--no-stream' => true]);

        $create = $api->findCall('POST', '#/v1/conversations$#');

        self::assertNotNull($create);
        // the id comes from llmor.lock — test never creates an app of its own
        self::assertSame(17, $create['body']['vendor_app_id']);
    }

    public function testSendsTheMessageWithStreamingOffWhenAskedTo(): void
    {
        $api = $this->api();
        $this->tester($api)->execute(['app' => 'support_bot', 'message' => ['hi'], '--no-stream' => true]);

        $interact = $api->findCall('POST', '#/v1/conversations/17-abc$#');

        self::assertNotNull($interact);
        self::assertSame('hi', $interact['body']['content']);
        // asking the server to publish deltas nobody is listening for is pure waste
        self::assertFalse($interact['body']['stream']);
        self::assertFalse($interact['body']['relay']);
    }

    public function testPassesConversationParametersThrough(): void
    {
        $api = $this->api();
        $this->tester($api)->execute([
            'app' => 'support_bot',
            'message' => ['hi'],
            '--no-stream' => true,
            '--channel' => 'cli-smoke',
            '--param-json' => '{"tier":"pro","seats":3}',
            '--param' => ['tier=enterprise'],
        ]);

        $create = $api->findCall('POST', '#/v1/conversations$#');

        self::assertNotNull($create);
        self::assertSame('cli-smoke', $create['body']['vendor_channel']);
        // the repeatable pair wins over the JSON object it is layered on
        self::assertSame(['tier' => 'enterprise', 'seats' => 3], $create['body']['parameters']);
    }

    public function testRendersToolCallsAlongsideTheAnswer(): void
    {
        $api = $this->api([
            ['role' => 'user', 'message' => 'weather?'],
            [
                'role' => 'assistant',
                'message' => '',
                'function_calls' => [['id' => 'call_1', 'name' => 'weather', 'arguments' => ['city' => 'Bern']]],
            ],
            ['role' => 'tool', 'message' => '{"temp":14}', 'function_response' => 'call_1'],
            ['role' => 'assistant', 'message' => 'It is 14°C and raining in Bern.', 'function_calls' => []],
        ]);

        $tester = $this->tester($api);
        $tester->execute(['app' => 'support_bot', 'message' => ['weather?'], '--no-stream' => true]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('⚙ weather', $display);
        self::assertStringContainsString('{"city":"Bern"}', $display);
        self::assertStringContainsString('✓ weather', $display);
        self::assertStringContainsString('It is 14°C and raining in Bern.', $display);
    }

    public function testJsonModePrintsTheRawResponse(): void
    {
        $api = $this->api();
        $tester = $this->tester($api);

        $tester->execute(['app' => 'support_bot', 'message' => ['hi'], '--no-stream' => true, '--json' => true]);

        $decoded = \json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('conversation', $decoded);
        self::assertArrayHasKey('data', $decoded);
    }

    public function testResumesAnExistingConversationInsteadOfCreatingOne(): void
    {
        $api = $this->api();
        $tester = $this->tester($api);

        $tester->execute(['message' => ['still there?'], '--conversation' => '17-abc', '--no-stream' => true]);

        self::assertNull($api->findCall('POST', '#/v1/conversations$#'));
        self::assertNotNull($api->findCall('GET', '#/v1/conversations/17-abc$#'));
        self::assertNotNull($api->findCall('POST', '#/v1/conversations/17-abc$#'));
    }

    public function testFailsWhenTheAppHasNotBeenSyncedYet(): void
    {
        // no lock entry, and no remote app to adopt by name + app type either
        $this->writeProjectFile('llmor.lock', (string) \json_encode(['version' => 1, 'vendors' => []]));

        $tester = $this->tester($this->api(remoteApps: []));
        $status = $tester->execute(['app' => 'support_bot', 'message' => ['hi'], '--no-stream' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('has not been synced yet', $tester->getDisplay());
        self::assertStringContainsString('llmor sync --app support_bot', $tester->getDisplay());
    }

    public function testDoesNotCreateAnAppWhileResolvingIt(): void
    {
        $api = $this->api();
        $this->tester($api)->execute(['app' => 'support_bot', 'message' => ['hi'], '--no-stream' => true]);

        self::assertNotContains('POST /v1/vendors/42/apps', $api->writes());
    }

    public function testFailsOnAnUndeclaredApp(): void
    {
        $tester = $this->tester($this->api());
        $status = $tester->execute(['app' => 'nope', 'message' => ['hi'], '--no-stream' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('not declared in the manifest', $tester->getDisplay());
    }

    public function testRejectsANonNumericAppId(): void
    {
        $tester = $this->tester($this->api());
        $status = $tester->execute(['message' => ['hi'], '--app-id' => 'seventeen', '--no-stream' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('numeric app id', $tester->getDisplay());
    }

    public function testTalksToABareAppIdWithoutTouchingTheManifest(): void
    {
        $api = $this->api();
        $tester = $this->tester($api);

        $status = $tester->execute(['message' => ['hi'], '--app-id' => '99', '--no-stream' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(99, $this->createBody($api)['vendor_app_id']);
        // resolving through the manifest would have had to look the vendor up first
        self::assertNull($api->findCall('GET', '#/v1/vendors$#'));
    }

    public function testListsDeclaredAppsWhenGivenNoArguments(): void
    {
        $tester = $this->tester($this->api());
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('support_bot', $tester->getDisplay());
        self::assertStringContainsString('llmor/generic', $tester->getDisplay());
    }

    public function testAdoptsAMatchingRemoteAppWhenTheLockIsEmpty(): void
    {
        // the lock is how an app id normally travels, but a first run on a fresh clone
        // still finds the app the console (or another machine) already created
        $this->writeProjectFile('llmor.lock', (string) \json_encode(['version' => 1, 'vendors' => []]));

        $api = $this->api();
        $status = $this->tester($api)->execute(['app' => 'support_bot', 'message' => ['hi'], '--no-stream' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(17, $this->createBody($api)['vendor_app_id']);
    }

    public function testOneShotFailsWhenTheTurnSuspendsOnAQuestion(): void
    {
        $api = $this->api(interact: fn (): array => [200, [
            'conversation' => ['token' => '17-abc'],
            'data' => [['role' => 'user', 'message' => 'hi', 'function_calls' => []]],
            'pending_ask_user' => [['id' => 'q1', 'type' => 'text', 'question' => 'Which account?']],
        ]]);

        $tester = $this->tester($api);
        $status = $tester->execute(['app' => 'support_bot', 'message' => ['hi'], '--no-stream' => true]);

        // reporting success would hide that the turn never actually finished
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Which account?', $tester->getDisplay());
    }

    public function testTheReplSendsEachLineAndLeavesOnExit(): void
    {
        $api = $this->api();
        $tester = $this->tester($api);
        $tester->setInputs(['hello', '/token', '/exit']);

        $status = $tester->execute(['app' => 'support_bot', '--no-stream' => true]);

        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Support Bot', $display);
        self::assertStringContainsString('It is 14°C and raining in Bern.', $display);
        self::assertStringContainsString('17-abc', $display);
        self::assertNotNull($api->findCall('POST', '#/v1/conversations/17-abc$#'));
    }

    public function testTheReplLeavesOnEndOfInput(): void
    {
        // a piped stdin that simply runs out must not spin forever
        $tester = $this->tester($this->api());
        $tester->setInputs(['hello']);

        self::assertSame(Command::SUCCESS, $tester->execute(['app' => 'support_bot', '--no-stream' => true]));
    }

    public function testTheReplReportsAnUnknownMetaCommand(): void
    {
        $tester = $this->tester($this->api());
        $tester->setInputs(['/nope', '/exit']);
        $tester->execute(['app' => 'support_bot', '--no-stream' => true]);

        self::assertStringContainsString('Unknown command "/nope"', $tester->getDisplay());
    }

    public function testTheReplAnswersAskUserPromptsAndResumes(): void
    {
        $turn = 0;
        $api = $this->api(interact: function () use (&$turn): array {
            ++$turn;

            // first turn suspends on a question; the answer resumes and completes it
            if (1 === $turn) {
                return [200, [
                    'conversation' => ['token' => '17-abc'],
                    'data' => [['role' => 'user', 'message' => 'hi', 'function_calls' => []]],
                    'pending_ask_user' => [['id' => 'q1', 'type' => 'text', 'question' => 'Which account?']],
                ]];
            }

            return [200, [
                'conversation' => ['token' => '17-abc'],
                'data' => [
                    ['role' => 'user', 'message' => 'hi', 'function_calls' => []],
                    ['role' => 'assistant', 'message' => 'Thanks, checking acme.', 'function_calls' => []],
                ],
            ]];
        });

        $tester = $this->tester($api);
        $tester->setInputs(['hi', 'acme', '/exit']);
        $tester->execute(['app' => 'support_bot', '--no-stream' => true]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Which account?', $display);
        self::assertStringContainsString('Thanks, checking acme.', $display);
        self::assertSame([['id' => 'q1', 'result' => 'acme']], $api->calls[\count($api->calls) - 1]['body']['ask_user_response']);
    }

    /**
     * A scripted API: vendor lookup, the app index, conversation create, and an
     * interact that answers with $messages.
     *
     * $interact replaces the default interact handler entirely. FakeLlmorApi matches the
     * first route that fits, so an override has to be registered before the default
     * rather than added afterwards.
     *
     * @param list<array<string, mixed>>|null                                                               $messages
     * @param (callable(string, string, array<string, mixed>): array{0: int, 1: array<string, mixed>})|null $interact
     * @param list<array<string, mixed>>                                                                    $remoteApps
     */
    private function api(?array $messages = null, ?callable $interact = null, array $remoteApps = [['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/generic']]): FakeLlmorApi
    {
        $messages ??= [
            ['role' => 'user', 'message' => 'hi', 'function_calls' => []],
            [
                'role' => 'assistant',
                'message' => 'It is 14°C and raining in Bern.',
                'function_calls' => [],
                'meta' => ['took' => 1800, 'usage' => ['model' => ['input' => 412, 'output' => 96, 'total' => 508]]],
            ],
        ];

        $conversation = [
            'token' => '17-abc',
            'api_path' => '/v1/conversations/17-abc',
            'relay_url' => 'https://relay.test',
            'relay_path' => '/stream',
        ];

        $api = new FakeLlmorApi();

        if (null !== $interact) {
            $api->on('POST', '#/v1/conversations/17-abc$#', $interact);
        }

        return $api
            ->on('GET', '#/v1/vendors$#', fn (): array => [200, ['data' => [['id' => 42, 'key' => TestClient::VENDOR_KEY]]]])
            ->on('GET', '#/v1/vendors/42/apps$#', fn (): array => [200, ['data' => $remoteApps]])
            ->on('POST', '#/v1/conversations$#', fn (): array => [200, ['data' => $conversation]])
            ->on('GET', '#/v1/conversations/17-abc$#', fn (): array => [200, ['status' => 'idle', 'conversation' => $conversation, 'data' => []]])
            ->on('POST', '#/v1/conversations/17-abc$#', fn (): array => [200, ['conversation' => $conversation, 'meta' => [], 'data' => $messages]]);
    }

    /**
     * The body of the conversation-create call, which must have happened.
     *
     * @return array<string, mixed>
     */
    private function createBody(FakeLlmorApi $api): array
    {
        $call = $api->findCall('POST', '#/v1/conversations$#');
        self::assertNotNull($call, 'expected a conversation to have been created');

        return $call['body'];
    }

    private function tester(FakeLlmorApi $api): CommandTester
    {
        return new CommandTester(new TestCommand(
            TestClient::forApi($api, $this->projectDir),
            TestClient::VENDOR_KEY,
            $this->projectDir,
        ));
    }
}
