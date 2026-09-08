<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Sync\SyncCommand;
use Llmor\Cli\Sync\AppLockFile;
use Llmor\Cli\Sync\AppResolver;
use Llmor\Cli\Sync\AppSynchronizer;
use Llmor\Cli\Sync\AppSyncResult;
use Llmor\Cli\Sync\FunctionIdResolver;
use Llmor\Cli\Sync\SubagentChange;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use Llmor\Cli\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SyncCommand::class)]
#[CoversClass(AppSynchronizer::class)]
#[CoversClass(AppSyncResult::class)]
#[CoversClass(AppResolver::class)]
#[CoversClass(FunctionIdResolver::class)]
#[CoversClass(SubagentChange::class)]
final class SyncAppCommandTest extends TestCase
{
    use TempProject;

    protected function setUp(): void
    {
        $this->makeProject();
        $this->writeProjectFile('prompts/support.md', "You are a support agent.\n");
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function testCreatesAppAndRecordsItsIdInTheLockFile(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());

        $api = $this->api()
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/apps$#', static fn (): array => [200, ['data' => ['id' => 17]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        $create = $api->findCall('POST', '#/v1/vendors/42/apps$#');
        self::assertNotNull($create);
        self::assertSame('llmor/generic', $create['body']['app_key']);
        self::assertSame('Support Bot', $create['body']['name']);
        self::assertSame("You are a support agent.\n", $create['body']['parameters']['prompt'], '@file loads the prompt from disk.');
        self::assertSame(0.2, $create['body']['parameters']['temperature']);
        self::assertSame(4, $create['body']['completion_vendor_model_id']);

        self::assertSame(
            ['id' => 17, 'app_key' => 'llmor/generic'],
            $this->lock()->lookup('acme-co', 'support_bot'),
        );

        $display = $tester->getDisplay();
        self::assertStringContainsString('#17', $display, 'The id is shown so it can be found in the console.');
        self::assertStringContainsString('commit this file', $display);
        self::assertStringContainsString('1 app(s)', $display);
    }

    public function testASecondRunWithNothingChangedWritesNothing(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());
        $this->seedLock(17);

        $api = $this->api()
            ->on('GET', '#/apps$#', fn (): array => [200, ['data' => [$this->remoteApp()]]])
            ->on('GET', '#/apps/17$#', fn (): array => [200, ['data' => $this->remoteApp()]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());

        self::assertSame([], $api->writes(), 'An idempotent run must not write.');

        self::assertNull($api->findCall('GET', '#/models#'), 'The model list is only fetched when [model] differs.');
    }

    public function testOnlyChangedParametersAreSentAndNamedInTheReport(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());
        $this->writeProjectFile('prompts/support.md', "A rewritten prompt.\n");
        $this->seedLock(17);

        $api = $this->api()
            ->on('GET', '#/apps$#', fn (): array => [200, ['data' => [$this->remoteApp()]]])
            ->on('GET', '#/apps/17$#', fn (): array => [200, ['data' => $this->remoteApp()]])
            ->on('PUT', '#/apps/17$#', static fn (): array => [200, ['data' => ['id' => 17]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        $put = $api->findCall('PUT', '#/apps/17$#');
        self::assertNotNull($put);
        self::assertSame("A rewritten prompt.\n", $put['body']['parameters']['prompt']);
        self::assertSame(0.2, $put['body']['parameters']['temperature'], 'Unchanged declared values still travel in the merged bag.');
        self::assertTrue($put['body']['parameters']['set_in_console'], 'A parameter only the console set is preserved.');

        self::assertArrayNotHasKey('app_key', $put['body'], "An app's type is create-only and must never be sent.");
        self::assertArrayNotHasKey('embed_config', $put['body'], 'Fields the manifest does not own are left alone.');

        self::assertStringContainsString('params ~1 (prompt)', $tester->getDisplay());
    }

    public function testAdoptsAnAppThatAlreadyExistsInsteadOfCreatingASecond(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());

        $api = $this->api()
            ->on('GET', '#/apps$#', fn (): array => [200, ['data' => [$this->remoteApp()]]])
            ->on('GET', '#/apps/17$#', fn (): array => [200, ['data' => $this->remoteApp()]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertNull($api->findCall('POST', '#/apps$#'), 'A console-made app must not be duplicated.');
        self::assertSame(17, $this->lock()->lookup('acme-co', 'support_bot')['id'] ?? null);
        self::assertStringContainsString('Adopted existing app #17', $tester->getDisplay());
    }

    public function testDryRunWritesNothingAtAllIncludingTheLockFile(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());

        $api = $this->api()->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute(['--dry-run' => true]), $tester->getDisplay());

        self::assertFileDoesNotExist($this->projectPath(AppLockFile::FILE_NAME));
        self::assertFileDoesNotExist($this->projectPath(AppLockFile::FILE_NAME.'.tmp'));

        self::assertSame([], $api->writes(), 'A dry run is read-only.');

        $display = $tester->getDisplay();
        self::assertStringContainsString('(new)', $display, 'An app that does not exist yet has no id to show.');
        self::assertStringContainsString('Would sync', $display);
    }

    public function testDryRunDoesNotRewriteAnExistingLockFile(): void
    {
        // Resolution — not just the writes — can change the lock: a binding whose app
        // was deleted in the console is forgotten. A dry run must not do that either,
        // or it edits a committed file while claiming to change nothing.
        $this->writeProjectFile('llmor.scsc', $this->appManifest());
        $this->lock()->record('acme-co', 'support_bot', 17, 'llmor/generic');
        $before = (string) \file_get_contents($this->projectPath(AppLockFile::FILE_NAME));

        $api = $this->api()->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute(['--dry-run' => true]), $tester->getDisplay());

        self::assertSame($before, (string) \file_get_contents($this->projectPath(AppLockFile::FILE_NAME)));
        self::assertSame([], $api->writes(), 'A dry run is read-only.');
        self::assertStringContainsString('no longer exists', $tester->getDisplay(), 'The stale binding is still reported.');
    }

    public function testAppFilterWritesOnlyThatAppAndKeepsOtherLockEntries(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest().<<<'SCSC'

            research_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Research Bot'
            }
            SCSC);
        $this->seedLock(17);
        $this->lock()->record('acme-co', 'research_bot', 18, 'llmor/generic');

        $api = $this->api()
            ->on('GET', '#/apps$#', fn (): array => [200, ['data' => [
                $this->remoteApp(),
                ['id' => 18, 'name' => 'Research Bot', 'app_key' => 'llmor/generic', 'parameters' => []],
            ]]])
            ->on('GET', '#/apps/17$#', fn (): array => [200, ['data' => $this->remoteApp()]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute(['--app' => 'support_bot']), $tester->getDisplay());

        self::assertNull($api->findCall('GET', '#/apps/18$#'), 'An unselected app is resolved but never read in detail.');
        self::assertSame(18, $this->lock()->lookup('acme-co', 'research_bot')['id'] ?? null, "The other app's binding survives.");
        self::assertStringNotContainsString('research_bot', $tester->getDisplay());
    }

    public function testFunctionFilterDoesNotDragAppsAlong(): void
    {
        $this->writeProjectFile('main/main.lua', "return success('x')\n");
        $this->writeProjectFile('llmor.scsc', $this->functionManifest().$this->appManifest());

        $api = $this->api()
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => [['id' => 7, 'function_key' => 'greeter', 'name' => 'G', 'description' => 'D', 'runtime' => 'silicon', 'code' => "return success('x')\n"]]]])
            ->on('GET', '#/functions/7/files$#', static fn (): array => [200, ['data' => []]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute(['--function' => 'greeter']), $tester->getDisplay());

        self::assertNull($api->findCall('GET', '#/apps$#'), '--function must not touch apps.');
        self::assertFileDoesNotExist($this->projectPath(AppLockFile::FILE_NAME));
    }

    public function testAStaleLockEntryIsRecoveredByRecreatingTheApp(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());
        $this->seedLock(999);

        $api = $this->api()
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/apps$#', static fn (): array => [200, ['data' => ['id' => 21]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertStringContainsString('no longer exists', $tester->getDisplay());
        self::assertSame(21, $this->lock()->lookup('acme-co', 'support_bot')['id'] ?? null);
    }

    public function testChangingAnAppTypeFailsWithoutWriting(): void
    {
        $this->writeProjectFile('llmor.scsc', <<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/silicon'
              [name]    = 'Support Bot'
            }
            SCSC);
        $this->seedLock(17);

        $api = $this->api()->on('GET', '#/apps$#', fn (): array => [200, ['data' => [$this->remoteApp()]]]);

        $tester = $this->tester($api);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('cannot be changed', $tester->getDisplay());
        self::assertNull($api->findCall('PUT', '#/apps/17$#'));
    }

    public function testNestedValidationErrorsAreRenderedReadably(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());

        $api = $this->api()
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/apps$#', static fn (): array => [400, [
                'message' => 'The given configuration parameters are invalid.',
                'errors' => ['parameters' => ['temperature' => ['must be at most 2']]],
            ]]);

        $tester = $this->tester($api);
        self::assertSame(1, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('[parameters] → temperature', $display);
        self::assertStringContainsString('must be at most 2', $display);
    }

    public function testJsonOutputDescribesTheAppOutcome(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());

        $api = $this->api()
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/apps$#', static fn (): array => [200, ['data' => ['id' => 17]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute(['--json' => true]));

        $payload = \json_decode(\trim($tester->getDisplay()), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertSame(['app' => 1], $payload['summary']['by_kind']);

        $result = $payload['results'][0];
        self::assertSame('app', $result['kind']);
        self::assertSame('support_bot', $result['app']);
        self::assertSame('created', $result['action']);
        self::assertSame(17, $result['app_id']);
    }

    public function testInstallsFunctionsIncludingOneCreatedInTheSameRun(): void
    {
        $this->writeProjectFile('main/main.lua', "return success('x')\n");
        $this->writeProjectFile('llmor.scsc', $this->functionManifest().<<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Support Bot'
              [functions] = {
                [greeter] = { units = 'metric' }
              }
            }
            SCSC);

        $api = $this->api()
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static fn (): array => [200, ['data' => ['id' => 7]]])
            ->on('GET', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => []]])
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/apps$#', static fn (): array => [200, ['data' => ['id' => 17]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        $create = $api->findCall('POST', '#/v1/vendors/42/apps$#');
        self::assertNotNull($create);
        self::assertSame(
            [['id' => 7, 'config' => ['units' => 'metric']]],
            $create['body']['functions']['data'],
            'The id minted moments earlier in the same run is used.',
        );
        self::assertStringContainsString('fn 1', $tester->getDisplay());
    }

    public function testAnUnresolvableFunctionAbortsWithoutTouchingTheLinks(): void
    {
        // `functions` present replaces the whole link table, so sending the subset that
        // happened to resolve would silently unlink a function nobody touched.
        $this->writeProjectFile('llmor.scsc', <<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Support Bot'
              [functions] = { known_fn, ghost_fn }
            }
            SCSC);
        $this->seedLock(17);

        $api = $this->api()
            ->on('GET', '#/functions$#', static fn (string $m, string $p, array $b): array => [200, ['data' => [
                ['id' => 7, 'function_key' => 'known_fn'],
            ]]])
            ->on('GET', '#/apps$#', fn (): array => [200, ['data' => [$this->remoteApp()]]])
            ->on('GET', '#/apps/17$#', fn (): array => [200, ['data' => $this->remoteApp()]]);

        $tester = $this->tester($api);
        self::assertSame(1, $tester->execute([]));

        self::assertStringContainsString('has not been synced yet', $tester->getDisplay());

        $put = $api->findCall('PUT', '#/apps/17$#');
        self::assertNull($put, 'Nothing at all is written when a reference cannot be resolved.');
    }

    public function testUnchangedFunctionLinksAreNotResent(): void
    {
        $this->writeProjectFile('llmor.scsc', <<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Support Bot'
              [functions] = { [weather] = { units = 'metric' } }
            }
            SCSC);
        $this->seedLock(17);

        $remote = $this->remoteApp();
        $remote['functions'] = ['data' => [['id' => 7, 'function_key' => 'weather', 'config' => ['units' => 'metric']]]];

        $api = $this->api()
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => [['id' => 7, 'function_key' => 'weather']]]])
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => [$remote]]])
            ->on('GET', '#/apps/17$#', static fn (): array => [200, ['data' => $remote]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertSame([], $api->writes(), 'Matching links must not provoke a PUT.');
        self::assertStringContainsString('unchanged', $tester->getDisplay());
    }

    public function testAnEmptyFunctionsBlockUnlinksEverything(): void
    {
        $this->writeProjectFile('llmor.scsc', <<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Support Bot'
              [functions] = {}
            }
            SCSC);
        $this->seedLock(17);

        $remote = $this->remoteApp();
        $remote['functions'] = ['data' => [['id' => 7, 'function_key' => 'weather', 'config' => []]]];

        $api = $this->api()
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => [$remote]]])
            ->on('GET', '#/apps/17$#', static fn (): array => [200, ['data' => $remote]])
            ->on('PUT', '#/apps/17$#', static fn (): array => [200, ['data' => ['id' => 17]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        $put = $api->findCall('PUT', '#/apps/17$#');
        self::assertNotNull($put);
        self::assertSame([], $put['body']['functions']['data'], 'Declaring an empty set is how you unlink.');
    }

    public function testAnAbsentFunctionsBlockLeavesTheLinksAlone(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->appManifest());
        $this->seedLock(17);

        $remote = $this->remoteApp();
        $remote['functions'] = ['data' => [['id' => 7, 'function_key' => 'weather', 'config' => []]]];
        $remote['parameters']['prompt'] = 'stale';

        $api = $this->api()
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => [$remote]]])
            ->on('GET', '#/apps/17$#', static fn (): array => [200, ['data' => $remote]])
            ->on('PUT', '#/apps/17$#', static fn (): array => [200, ['data' => ['id' => 17]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        $put = $api->findCall('PUT', '#/apps/17$#');
        self::assertNotNull($put, 'The prompt still changed, so there is a PUT.');
        self::assertArrayNotHasKey('functions', $put['body'], 'Links the manifest never mentions must survive.');
    }

    public function testCreatesUpdatesAndWarnsAboutSubagents(): void
    {
        $this->writeProjectFile('llmor.scsc', <<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Support Bot'
              [subagents] = {
                [triage] = {
                  [app]            = research_bot
                  [expose_as_tool] = true
                  [tool_name]      = 'deep_research'
                }
                [summarise] = { [app] = research_bot }
              }
            }

            research_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Research Bot'
            }
            SCSC);
        $this->seedLock(17);
        $this->lock()->record('acme-co', 'research_bot', 18, 'llmor/generic');

        $researchBot = ['id' => 18, 'name' => 'Research Bot', 'app_key' => 'llmor/generic', 'parameters' => []];

        $api = $this->api()
            ->on('GET', '#/apps$#', fn (): array => [200, ['data' => [$this->remoteApp(), $researchBot]]])
            ->on('GET', '#/apps/17$#', fn (): array => [200, ['data' => $this->remoteApp()]])
            ->on('GET', '#/apps/18$#', static fn (): array => [200, ['data' => $researchBot]])
            ->on('GET', '#/apps/17/subagents$#', static fn (): array => [200, ['data' => [
                // already correct
                ['id' => 90, 'alias' => 'summarise', 'target_vendor_app_id' => 18, 'description' => '', 'expose_as_tool' => false, 'tool_name' => '', 'tool_description' => '', 'input_description' => ''],
                // no longer declared
                ['id' => 91, 'alias' => 'orphan', 'target_vendor_app_id' => 18],
            ]]])
            ->on('GET', '#/apps/18/subagents$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/apps/17/subagents$#', static fn (): array => [200, ['data' => ['id' => 92]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        $create = $api->findCall('POST', '#/apps/17/subagents$#');
        self::assertNotNull($create);
        self::assertSame('triage', $create['body']['alias']);
        self::assertSame(18, $create['body']['target_vendor_app_id'], 'The target resolves through the other declaration.');
        self::assertTrue($create['body']['expose_as_tool']);
        self::assertSame('deep_research', $create['body']['tool_name']);
        self::assertSame('', $create['body']['tool_description'], 'Every field is sent — the manifest owns them all.');

        self::assertNull($api->findCall('DELETE', '#/subagents/91$#'), 'An orphan is reported, never deleted.');

        $display = $tester->getDisplay();
        self::assertStringContainsString('triage  created  → research_bot (#18)', $display);
        self::assertStringContainsString('as tool "deep_research"', $display);
        self::assertStringContainsString('summarise  unchanged', $display);
        self::assertStringContainsString('Sub-agent "orphan" exists remotely', $display);
    }

    public function testASubagentTargetDeclaredLaterInTheFileStillWorksInOneRun(): void
    {
        // Sub-agents are reconciled in a pass of their own, after every app has an id,
        // so whether the manifest happens to be in dependency order does not matter.
        $this->writeProjectFile('llmor.scsc', <<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Support Bot'
              [subagents] = { [triage] = { [app] = research_bot } }
            }

            research_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Research Bot'
            }
            SCSC);

        $created = [];
        $api = $this->api()
            ->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/apps$#', static function (string $m, string $p, array $body) use (&$created): array {
                $id = 'Support Bot' === ($body['name'] ?? null) ? 17 : 18;
                $created[] = $id;

                return [200, ['data' => ['id' => $id]]];
            })
            ->on('GET', '#/apps/17/subagents$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/apps/17/subagents$#', static fn (): array => [200, ['data' => ['id' => 90]]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertSame([17, 18], $created, 'Both apps are created in the record pass.');

        $subagent = $api->findCall('POST', '#/apps/17/subagents$#');
        self::assertNotNull($subagent, 'The sub-agent is wired after both apps exist.');
        self::assertSame(18, $subagent['body']['target_vendor_app_id']);
        self::assertStringContainsString('triage  created  → research_bot (#18)', $tester->getDisplay());
    }

    public function testDryRunReportsAPendingSubagentTargetRatherThanFailing(): void
    {
        // On a virgin project nothing exists yet, which is exactly when a readable
        // report matters most.
        $this->writeProjectFile('llmor.scsc', <<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Support Bot'
              [subagents] = { [triage] = { [app] = research_bot } }
            }

            research_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Research Bot'
            }
            SCSC);

        $api = $this->api()->on('GET', '#/apps$#', static fn (): array => [200, ['data' => []]]);

        $tester = $this->tester($api);
        self::assertSame(0, $tester->execute(['--dry-run' => true]), $tester->getDisplay());

        self::assertStringContainsString('triage  pending  → research_bot (pending)', $tester->getDisplay());
        self::assertSame([], $api->writes());
    }

    public function testASubagentTargetThatWasNeverSyncedFailsWithAnActionableMessage(): void
    {
        $this->writeProjectFile('llmor.scsc', <<<'SCSC'
            support_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Support Bot'
              [subagents] = { [triage] = { [app] = research_bot } }
            }

            research_bot: App {
              [app_key] = 'llmor/generic'
              [name]    = 'Research Bot'
            }
            SCSC);
        $this->seedLock(17);

        $api = $this->api()
            ->on('GET', '#/apps$#', fn (): array => [200, ['data' => [$this->remoteApp()]]])
            ->on('GET', '#/apps/17$#', fn (): array => [200, ['data' => $this->remoteApp()]])
            ->on('GET', '#/apps/17/subagents$#', static fn (): array => [200, ['data' => []]]);

        $tester = $this->tester($api);
        self::assertSame(1, $tester->execute(['--app' => 'support_bot']));

        $display = $tester->getDisplay();
        self::assertStringContainsString('has not been synced yet', $display);
        self::assertStringContainsString('without --app', $display);
    }

    private function api(): FakeLlmorApi
    {
        return (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/models#', static fn (): array => [200, ['data' => [
                ['id' => 4, 'name' => 'gpt-4o', 'type' => 'chat_completion'],
            ]]]);
    }

    /**
     * The remote record as the API returns it, including a parameter only the console
     * set and a field the manifest does not own.
     *
     * @return array<string, mixed>
     */
    private function remoteApp(): array
    {
        return [
            'id' => 17,
            'name' => 'Support Bot',
            'description' => 'Answers customer questions.',
            'app_key' => 'llmor/generic',
            'completion_vendor_model_id' => 4,
            'completion_vendor_model' => ['data' => ['id' => 4, 'name' => 'gpt-4o']],
            'embed_config' => ['theme' => 'dark'],
            'parameters' => [
                'prompt' => "You are a support agent.\n",
                'temperature' => 0.2,
                'set_in_console' => true,
            ],
        ];
    }

    private function seedLock(int $id): void
    {
        $this->lock()->record('acme-co', 'support_bot', $id, 'llmor/generic');
    }

    private function lock(): AppLockFile
    {
        return new AppLockFile($this->projectPath(AppLockFile::FILE_NAME));
    }

    private function tester(FakeLlmorApi $api): CommandTester
    {
        $client = TestClient::forApi($api, $this->projectDir);

        return new CommandTester(new SyncCommand($client, TestClient::VENDOR_KEY, $this->projectDir));
    }

    private function appManifest(): string
    {
        return <<<'SCSC'
            support_bot: App {
              [app_key]     = 'llmor/generic'
              [name]        = 'Support Bot'
              [description] = 'Answers customer questions.'
              [model]       = 'gpt-4o'

              [parameters] = {
                @file('./prompts/support.md')
                prompt = ''
                temperature = 0.2
              }
            }
            SCSC;
    }

    private function functionManifest(): string
    {
        return <<<'SCSC'
            greeter: Function {
              [name]        = 'G'
              [description] = 'D'
              [runtime]     = 'silicon'
              [srcdir]      = './main'
              [entry]       = 'main.lua'
            }

            SCSC;
    }
}
