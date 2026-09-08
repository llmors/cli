<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Import;

use Llmor\Cli\Import\ImportException;
use Llmor\Cli\Import\MappedApp;
use Llmor\Cli\Import\RemoteAppMapper;
use Llmor\Cli\Import\SubagentTargetNamer;
use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\Manifest;
use Llmor\Cli\Sync\AppLockFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteAppMapper::class)]
#[CoversClass(SubagentTargetNamer::class)]
#[CoversClass(MappedApp::class)]
final class RemoteAppMapperTest extends TestCase
{
    public function testMapsTheFieldsAManifestOwns(): void
    {
        $mapped = $this->map($this->record());
        $app = $mapped->definition;

        self::assertSame('support_bot', $app->declaration);
        self::assertSame('llmor/generic', $app->appKey);
        self::assertSame('Support Bot', $app->name);
        self::assertSame('Answers questions.', $app->description);
        self::assertSame('gpt-4o', $app->model);
        self::assertNull($app->id, 'identity belongs in the lock file, not in an [id] pin');
        self::assertSame(0.2, $app->parameters->temperature ?? null);
        self::assertSame([], $mapped->warnings);
    }

    public function testReportsConsoleManagedFieldsThatAreActuallySet(): void
    {
        $record = $this->record();
        $record['embed_config'] = ['theme' => 'dark'];
        $record['allowed_origins'] = [];
        $record['conversation_expire_after'] = null;

        self::assertSame(['embed_config'], $this->map($record)->skipped);
    }

    public function testAnAppWithoutAnAppKeyCannotBeDeclared(): void
    {
        $record = $this->record();
        unset($record['app_key']);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('has no app_key');

        $this->map($record);
    }

    public function testTheModelIsTakenFromTheResolvedRecord(): void
    {
        $record = $this->record();
        $record['completion_vendor_model'] = ['data' => ['id' => 9, 'name' => 'claude-opus']];

        self::assertSame('claude-opus', $this->map($record)->definition->model);
    }

    public function testAnUnresolvableModelIsLeftOut(): void
    {
        $record = $this->record();
        unset($record['completion_vendor_model'], $record['completion_vendor_model_id']);

        self::assertNull($this->map($record)->definition->model);
    }

    public function testFunctionLinksBecomeKeyedReferences(): void
    {
        $record = $this->record();
        $record['functions'] = ['data' => [
            ['id' => 7, 'function_key' => 'weather', 'config' => ['units' => 'metric']],
            ['id' => 8, 'function_key' => 'greeter', 'config' => null],
        ]];

        $links = $this->map($record)->definition->functions ?? [];

        self::assertCount(2, $links);
        self::assertSame('weather', $links[0]->name);
        self::assertSame('metric', $links[0]->config->units ?? null);
        self::assertSame('greeter', $links[1]->name);
        self::assertEquals([], \get_object_vars($links[1]->config));
    }

    /**
     * The field is a full replace server-side, so a block that lost one entry would
     * silently unlink it. Omitting the block leaves every link exactly as it is.
     */
    public function testOneUnusableFunctionKeyOmitsTheWholeBlock(): void
    {
        $record = $this->record();
        $record['functions'] = ['data' => [
            ['id' => 7, 'function_key' => 'weather'],
            ['id' => 8, 'function_key' => 'not a key'],
        ]];

        $mapped = $this->map($record);

        self::assertNull($mapped->definition->functions);
        self::assertStringContainsString('[functions] is left out entirely', \implode("\n", $mapped->warnings));
    }

    public function testNoLinksMeansNoBlockRatherThanAnEmptyOne(): void
    {
        $record = $this->record();
        $record['functions'] = ['data' => []];

        self::assertNull($this->map($record)->definition->functions);
    }

    public function testAbsentFunctionsLeaveTheConsolesLinksAlone(): void
    {
        self::assertNull($this->map($this->record())->definition->functions);
    }

    public function testASubagentTargetInTheManifestIsNamed(): void
    {
        $mapped = $this->map($this->record(), [$this->subagent(['target_vendor_app_id' => 21])], $this->manifestPinning(21, 'research_bot'));
        $subagent = ($mapped->definition->subagents ?? [])[0] ?? null;

        self::assertNotNull($subagent);
        self::assertSame('research_bot', $subagent->target);
        self::assertNull($subagent->targetId, 'a named target must not also carry an id');
        self::assertSame([], $mapped->warnings);
    }

    public function testASubagentTargetOutsideTheManifestKeepsItsId(): void
    {
        $mapped = $this->map($this->record(), [$this->subagent(['target_vendor_app_id' => 21])]);
        $subagent = ($mapped->definition->subagents ?? [])[0] ?? null;

        self::assertNotNull($subagent);
        self::assertSame('21', $subagent->target);
        self::assertSame(21, $subagent->targetId);
        self::assertStringContainsString('targets app #21', \implode("\n", $mapped->warnings));
    }

    /**
     * The parser rejects self-delegation by name, so a self-referencing sub-agent has
     * to take the numeric form or the manifest would not parse at all.
     */
    public function testASubagentPointingAtItsOwnAppUsesTheNumericForm(): void
    {
        $mapped = $this->map($this->record(), [$this->subagent(['target_vendor_app_id' => 17])], $this->manifestPinning(17, 'support_bot'));
        $subagent = ($mapped->definition->subagents ?? [])[0] ?? null;

        self::assertNotNull($subagent);
        self::assertSame(17, $subagent->targetId);
    }

    public function testAnUnusableAliasIsLeftOut(): void
    {
        $mapped = $this->map($this->record(), [
            $this->subagent(['alias' => 'Not An Alias']),
            $this->subagent(['alias' => 'research']),
        ]);

        self::assertCount(1, $mapped->definition->subagents ?? []);
        self::assertStringContainsString('Sub-agent "Not An Alias" is left out', \implode("\n", $mapped->warnings));
    }

    public function testAnUnusableToolNameIsLeftToTheConsole(): void
    {
        $mapped = $this->map($this->record(), [$this->subagent(['tool_name' => 'deep research!'])]);
        $subagent = ($mapped->definition->subagents ?? [])[0] ?? null;

        self::assertNotNull($subagent);
        self::assertSame('', $subagent->toolName);
        self::assertStringContainsString('keeps its tool name in the console', \implode("\n", $mapped->warnings));
    }

    public function testNoSubagentsMeansNoBlock(): void
    {
        self::assertNull($this->map($this->record())->definition->subagents);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function exposeAsToolValues(): iterable
    {
        yield 'boolean true' => [true, true];
        yield 'boolean false' => [false, false];
        yield 'integer one' => [1, true];
        yield 'integer zero' => [0, false];
        yield 'string one' => ['1', true];
        yield 'string true' => ['true', true];
        yield 'string zero' => ['0', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('exposeAsToolValues')]
    public function testExposeAsToolAcceptsHoweverTheApiSpelledIt(mixed $value, bool $expected): void
    {
        $mapped = $this->map($this->record(), [$this->subagent(['expose_as_tool' => $value])]);

        self::assertSame($expected, (($mapped->definition->subagents ?? [])[0] ?? null)?->exposeAsTool);
    }

    /**
     * @param array<string, mixed>       $record
     * @param list<array<string, mixed>> $subagents
     */
    private function map(array $record, array $subagents = [], ?Manifest $manifest = null): MappedApp
    {
        $manifest ??= new Manifest('/manifest/llmor.scsc', []);
        $namer = new SubagentTargetNamer('acme-co', $manifest, new AppLockFile('/manifest/llmor.lock'));

        return (new RemoteAppMapper($namer))->toDefinition('support_bot', 17, $record, $subagents);
    }

    /** A manifest whose only app is pinned to an id, so the namer can resolve it. */
    private function manifestPinning(int $id, string $declaration): Manifest
    {
        return new Manifest('/manifest/llmor.scsc', [], [
            new AppDefinition(declaration: $declaration, appKey: 'llmor/generic', id: $id),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function subagent(array $overrides = []): array
    {
        return $overrides + [
            'id' => 90,
            'alias' => 'research',
            'target_vendor_app_id' => 21,
            'description' => '',
            'expose_as_tool' => false,
            'tool_name' => '',
            'tool_description' => '',
            'input_description' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        return [
            'id' => 17,
            'name' => 'Support Bot',
            'description' => 'Answers questions.',
            'app_key' => 'llmor/generic',
            'completion_vendor_model_id' => 4,
            'completion_vendor_model' => ['data' => ['id' => 4, 'name' => 'gpt-4o']],
            'created_at' => '2026-01-01 00:00:00',
            'parameters' => ['temperature' => 0.2],
        ];
    }
}
