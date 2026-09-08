<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Apps\ImportCommand;
use Llmor\Cli\Command\Sync\SyncCommand;
use Llmor\Cli\Import\AppImporter;
use Llmor\Cli\Import\DeclarationNamer;
use Llmor\Cli\Import\ImportPlan;
use Llmor\Cli\Import\RemoteAppMapper;
use Llmor\Cli\Import\RemoteAppReader;
use Llmor\Cli\Import\SubagentTargetNamer;
use Llmor\Cli\Manifest\Writer\AppDeclarationWriter;
use Llmor\Cli\Manifest\Writer\ManifestAppender;
use Llmor\Cli\Manifest\Writer\ValueExtractor;
use Llmor\Cli\Sync\AppLockFile;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use Llmor\Cli\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ImportCommand::class)]
#[CoversClass(AppImporter::class)]
#[CoversClass(RemoteAppMapper::class)]
#[CoversClass(RemoteAppReader::class)]
#[CoversClass(SubagentTargetNamer::class)]
#[CoversClass(DeclarationNamer::class)]
#[CoversClass(ImportPlan::class)]
#[CoversClass(AppDeclarationWriter::class)]
#[CoversClass(ManifestAppender::class)]
#[CoversClass(ValueExtractor::class)]
final class ImportAppCommandTest extends TestCase
{
    use TempProject;

    protected function setUp(): void
    {
        $this->makeProject();
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    /**
     * The whole point of the feature, in one assertion: an imported app is already in
     * sync. If the emitter loses a parameter, mis-resolves the model, drops a function
     * link or renames a sub-agent, `sync` notices and this fails.
     */
    public function testTheImportedAppSyncsAsUnchanged(): void
    {
        $api = $this->api();
        self::assertSame(0, $this->importer($api)->execute(['id' => '17']), 'import failed');

        $sync = new CommandTester(new SyncCommand(
            TestClient::forApi($api, $this->projectDir),
            TestClient::VENDOR_KEY,
            $this->projectDir,
        ));

        self::assertSame(0, $sync->execute([]), $sync->getDisplay());
        self::assertStringContainsString('unchanged', $sync->getDisplay());
        self::assertSame([], $api->writes(), 'a freshly imported app must not be written back');
    }

    /**
     * The same guarantee with a sub-agent in play. `sync --dry-run` cannot show this —
     * it reports every declared sub-agent as `created` without reading the remote ones —
     * so only a real run proves the aliases and all seven fields round-tripped.
     */
    public function testTheImportedSubagentsAlsoSyncAsUnchanged(): void
    {
        $api = $this->api($this->withSubagents());
        self::assertSame(0, $this->importer($api)->execute(['id' => '17']), 'import failed');

        $sync = new CommandTester(new SyncCommand(
            TestClient::forApi($api, $this->projectDir),
            TestClient::VENDOR_KEY,
            $this->projectDir,
        ));

        self::assertSame(0, $sync->execute([]), $sync->getDisplay());
        self::assertStringContainsString('unchanged', $sync->getDisplay());
        self::assertSame([], $api->writes(), 'an imported sub-agent must not be rewritten');
    }

    /**
     * The manifest's own `[prompt_dir]` decides where extracted values land, and the
     * `@file` reference has to keep pointing at them — so this asserts both the path and
     * that `sync` still reads the prompt back from it.
     */
    public function testTheConfiguredPromptDirDecidesWhereExtractedValuesLand(): void
    {
        $this->writeProjectFile('llmor.scsc', "llmor: Config {\n  [prompt_dir] = './resources/prompts'\n}\n");

        $api = $this->api();
        $tester = $this->importer($api);
        self::assertSame(0, $tester->execute(['id' => '17']), $tester->getDisplay());

        self::assertStringContainsString(
            "@file('./resources/prompts/support_bot_prompt.md')",
            $this->readProjectFile('llmor.scsc'),
        );
        self::assertSame(
            "You are a support agent.\nBe brief.\n",
            $this->readProjectFile('resources/prompts/support_bot_prompt.md'),
        );
        self::assertFileDoesNotExist($this->projectPath('prompts'));

        $sync = new CommandTester(new SyncCommand(
            TestClient::forApi($api, $this->projectDir),
            TestClient::VENDOR_KEY,
            $this->projectDir,
        ));

        self::assertSame(0, $sync->execute([]), $sync->getDisplay());
        self::assertStringContainsString('unchanged', $sync->getDisplay());
        self::assertSame([], $api->writes(), 'the relocated prompt must still read back as unchanged');
    }

    public function testCreatesTheManifestTheLockAndTheExtractedPrompt(): void
    {
        $tester = $this->importer($api = $this->api());

        self::assertSame(0, $tester->execute(['id' => '17']), $tester->getDisplay());

        $manifest = $this->readProjectFile('llmor.scsc');
        self::assertStringContainsString('support_bot: App {', $manifest);
        self::assertStringContainsString("[app_type]    = 'llmor/generic'", $manifest);
        self::assertStringContainsString("[model]       = 'gpt-4o'", $manifest);
        self::assertStringContainsString("@file('./prompts/support_bot_prompt.md')", $manifest);
        self::assertStringContainsString("prompt = ''", $manifest);

        self::assertSame(
            "You are a support agent.\nBe brief.\n",
            $this->readProjectFile('prompts/support_bot_prompt.md'),
        );

        self::assertSame(
            ['id' => 17, 'app_type' => 'llmor/generic'],
            $this->lock()->lookup(TestClient::VENDOR_KEY, 'support_bot'),
        );

        self::assertSame([], $api->writes(), 'importing reads only');
        self::assertStringContainsString('Imported app #17 as "support_bot"', $tester->getDisplay());
    }

    public function testAppendsWithoutRewritingTheExistingManifest(): void
    {
        $existing = <<<'SCSC'
            // Keep my comment, my alignment and my blank lines.
            greeter: Function {
              [name]        = 'G'
              [description] = 'D'
              [runtime]     = 'silicon'
              [srcdir]      = './src'
              [entry]       = 'main.lua'
            }
            SCSC;

        $this->writeProjectFile('llmor.scsc', $existing."\n");
        $this->writeProjectFile('src/main.lua', 'return 1');

        $tester = $this->importer($this->api());
        self::assertSame(0, $tester->execute(['id' => '17']), $tester->getDisplay());

        $after = $this->readProjectFile('llmor.scsc');
        self::assertStringStartsWith($existing, $after, 'the original bytes must survive verbatim');
        self::assertStringContainsString($existing."\n\nsupport_bot: App {", $after, 'exactly one blank line at the seam');
        self::assertStringEndsWith("}\n", $after);
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->importer($api = $this->api());

        self::assertSame(0, $tester->execute(['id' => '17', '--dry-run' => true]), $tester->getDisplay());

        self::assertFileDoesNotExist($this->projectPath('llmor.scsc'));
        self::assertFileDoesNotExist($this->projectPath('llmor.scsc.tmp'));
        self::assertFileDoesNotExist($this->projectPath(AppLockFile::FILE_NAME));
        self::assertFileDoesNotExist($this->projectPath('prompts'));
        self::assertSame([], $api->writes());

        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry run', $display);
        self::assertStringContainsString('support_bot: App {', $display, 'the declaration is still shown');
    }

    public function testAnAlreadyImportedAppIsANoOp(): void
    {
        $tester = $this->importer($this->api());
        self::assertSame(0, $tester->execute(['id' => '17']));

        $before = $this->readProjectFile('llmor.scsc');

        $again = $this->importer($this->api());
        self::assertSame(0, $again->execute(['id' => '17']));

        self::assertSame($before, $this->readProjectFile('llmor.scsc'), 're-importing must not touch the manifest');
        self::assertStringContainsString('already imported as "support_bot"', $again->getDisplay());
    }

    public function testAsOverridesTheDerivedName(): void
    {
        $tester = $this->importer($this->api());

        self::assertSame(0, $tester->execute(['id' => '17', '--as' => 'helpdesk']), $tester->getDisplay());

        self::assertStringContainsString('helpdesk: App {', $this->readProjectFile('llmor.scsc'));
        self::assertSame(17, $this->lock()->lookup(TestClient::VENDOR_KEY, 'helpdesk')['id'] ?? null);
        self::assertFileExists($this->projectPath('prompts/helpdesk_prompt.md'));
    }

    public function testAnInvalidDeclarationNameIsRejected(): void
    {
        $tester = $this->importer($this->api());

        self::assertSame(1, $tester->execute(['id' => '17', '--as' => 'help-desk']));
        self::assertStringContainsString('not a valid declaration name', $tester->getDisplay());
        self::assertFileDoesNotExist($this->projectPath('llmor.scsc'));
    }

    public function testACollidingDerivedNameFailsNonInteractivelyAndNamesTheFlag(): void
    {
        $this->writeProjectFile('llmor.scsc', "support_bot: App {\n  [app_type] = 'llmor/generic'\n}\n");

        $tester = $this->importer($this->api());
        $tester->setInputs([]);

        self::assertSame(1, $tester->execute(['id' => '17'], ['interactive' => false]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('already declares an app called "support_bot"', $display);
        self::assertStringContainsString('--as support_bot_2', $display);
    }

    public function testTheNonInteractiveRunNeedsAnId(): void
    {
        $tester = $this->importer($this->api());

        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('Pass an app id', $tester->getDisplay());
    }

    public function testThePickerImportsTheChosenApp(): void
    {
        $tester = $this->importer($this->api([], null, $this->twoApps()));
        // Sorted by name, so "Research Bot" is 0 and "Support Bot" is 1.
        $tester->setInputs(['1']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('support_bot: App {', $this->readProjectFile('llmor.scsc'));
    }

    public function testAlreadyDeclaredAppsAreNotOffered(): void
    {
        $this->writeProjectFile('llmor.scsc', "pinned: App {\n  [app_type] = 'llmor/generic'\n  [id] = 21\n}\n");

        $tester = $this->importer($this->api([], null, $this->twoApps()));
        $tester->setInputs(['0']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('1 app(s) already declared', $display);
        self::assertStringNotContainsString('Research Bot', $display);
        self::assertStringContainsString('support_bot: App {', $this->readProjectFile('llmor.scsc'));
    }

    public function testASubagentTargetInTheManifestBecomesADeclarationName(): void
    {
        $this->writeProjectFile('llmor.scsc', "research_bot: App {\n  [app_type] = 'llmor/generic'\n  [id] = 21\n}\n");

        $tester = $this->importer($this->api($this->withSubagents()));
        self::assertSame(0, $tester->execute(['id' => '17']), $tester->getDisplay());

        $manifest = $this->readProjectFile('llmor.scsc');
        self::assertMatchesRegularExpression('/\[app\] += research_bot/', $manifest);
        self::assertMatchesRegularExpression("/\\[tool_name\\] += 'deep_research'/", $manifest);
        self::assertMatchesRegularExpression('/\\[expose_as_tool\\] += true/', $manifest);
    }

    public function testASubagentTargetOutsideTheManifestKeepsItsNumericId(): void
    {
        $tester = $this->importer($this->api($this->withSubagents()));
        self::assertSame(0, $tester->execute(['id' => '17']), $tester->getDisplay());

        self::assertMatchesRegularExpression('/\\[app\\] += 21/', $this->readProjectFile('llmor.scsc'));
        self::assertStringContainsString('targets app #21, which this manifest does not declare', $tester->getDisplay());
    }

    public function testConsoleManagedFieldsAreReportedButNotImported(): void
    {
        $tester = $this->importer($this->api());
        self::assertSame(0, $tester->execute(['id' => '17']));

        self::assertStringNotContainsString('embed_config', $this->readProjectFile('llmor.scsc'));
        self::assertStringContainsString('Not imported (console-managed): embed_config', $tester->getDisplay());
    }

    public function testAnInexpressibleParameterKeyIsLeftOutWithAWarning(): void
    {
        $record = $this->remoteApp();
        $record['parameters']['top-k'] = 40;

        $tester = $this->importer($this->api([], $record));
        self::assertSame(0, $tester->execute(['id' => '17']), $tester->getDisplay());

        self::assertStringNotContainsString('top-k', $this->readProjectFile('llmor.scsc'));
        self::assertStringContainsString('the key cannot be written in SchemaScript', $tester->getDisplay());
    }

    public function testFunctionLinksAreImportedByKey(): void
    {
        $record = $this->remoteApp();
        $record['functions'] = ['data' => [
            ['id' => 7, 'function_key' => 'weather', 'config' => ['units' => 'metric']],
            ['id' => 8, 'function_key' => 'greeter', 'config' => null],
        ]];

        $tester = $this->importer($this->api([], $record));
        self::assertSame(0, $tester->execute(['id' => '17']), $tester->getDisplay());

        $manifest = $this->readProjectFile('llmor.scsc');
        self::assertStringContainsString('[weather] = {', $manifest);
        self::assertStringContainsString("units = 'metric'", $manifest);
        self::assertStringContainsString('[greeter] = {}', $manifest);
    }

    public function testAnAppWithNoLinksOmitsTheFunctionsBlock(): void
    {
        $record = $this->remoteApp();
        $record['functions'] = ['data' => []];

        $tester = $this->importer($this->api([], $record));
        self::assertSame(0, $tester->execute(['id' => '17']), $tester->getDisplay());

        // Absent leaves the console's links alone; `{}` would unlink everything.
        self::assertStringNotContainsString('[functions]', $this->readProjectFile('llmor.scsc'));
    }

    public function testJsonOutputDescribesThePlan(): void
    {
        $tester = $this->importer($this->api());
        self::assertSame(0, $tester->execute(['id' => '17', '--json' => true]));

        $payload = \json_decode($tester->getDisplay(), true);
        self::assertIsArray($payload);
        self::assertSame(17, $payload['app_id']);
        self::assertSame('support_bot', $payload['declaration']);
        self::assertSame('llmor/generic', $payload['app_type']);
        self::assertTrue($payload['manifest_created']);
        self::assertFalse($payload['dry_run']);
        self::assertSame(['embed_config'], $payload['skipped_fields']);
        self::assertSame([['path' => 'prompts/support_bot_prompt.md', 'bytes' => 35]], $payload['files']);
    }

    public function testANonNumericIdIsRejected(): void
    {
        $tester = $this->importer($this->api());

        self::assertSame(1, $tester->execute(['id' => 'support_bot']));
        self::assertStringContainsString('is not an app id', $tester->getDisplay());
    }

    /**
     * @param list<array<string, mixed>>  $subagents
     * @param ?array<string, mixed>       $record
     * @param ?list<array<string, mixed>> $listed    what the apps listing returns
     */
    private function api(array $subagents = [], ?array $record = null, ?array $listed = null): FakeLlmorApi
    {
        $record ??= $this->remoteApp();
        $listed ??= [$record];

        return (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/models#', static fn (): array => [200, ['data' => [
                ['id' => 4, 'name' => 'gpt-4o', 'type' => 'chat_completion'],
            ]]])
            ->on('GET', '#/v1/vendors/42/apps$#', static fn (): array => [200, [
                'data' => $listed,
                'meta' => ['total_count' => \count($listed)],
            ]])
            ->on('GET', '#/apps/17/subagents$#', static fn (): array => [200, [
                'data' => $subagents,
                'meta' => ['total_count' => \count($subagents)],
            ]])
            ->on('GET', '#/apps/17$#', static fn (): array => [200, ['data' => $record]]);
    }

    /**
     * Two apps in the vendor's listing, for the picker tests.
     *
     * @return list<array<string, mixed>>
     */
    private function twoApps(): array
    {
        return [
            ['id' => 21, 'name' => 'Research Bot', 'app_key' => 'llmor/generic'],
            ['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/generic'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function withSubagents(): array
    {
        return [[
            'id' => 90,
            'alias' => 'research',
            'target_vendor_app_id' => 21,
            'description' => 'Looks things up in depth.',
            'expose_as_tool' => true,
            'tool_name' => 'deep_research',
            'tool_description' => 'Ask the research assistant.',
            'input_description' => '',
        ]];
    }

    /**
     * The record as the API returns it, with a console-only parameter, a console-managed
     * field the manifest never owns, and a multi-line prompt worth extracting.
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
            'created_at' => '2026-01-01 00:00:00',
            'parameters' => [
                'prompt' => "You are a support agent.\nBe brief.\n",
                'temperature' => 0.2,
                'set_in_console' => true,
                'stop' => ['###'],
                'extra_body' => ['reasoning' => ['effort' => 'high']],
            ],
        ];
    }

    private function lock(): AppLockFile
    {
        return new AppLockFile($this->projectPath(AppLockFile::FILE_NAME));
    }

    private function importer(FakeLlmorApi $api): CommandTester
    {
        return new CommandTester(new ImportCommand(
            TestClient::forApi($api, $this->projectDir),
            TestClient::VENDOR_KEY,
            $this->projectDir,
        ));
    }
}
