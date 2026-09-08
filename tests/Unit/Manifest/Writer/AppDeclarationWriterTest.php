<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Manifest\Writer;

use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\FunctionLink;
use Llmor\Cli\Manifest\ManifestParser;
use Llmor\Cli\Manifest\SubagentDefinition;
use Llmor\Cli\Manifest\Writer\AppDeclarationWriter;
use Llmor\Cli\Manifest\Writer\EmittedValue;
use Llmor\Cli\Manifest\Writer\ParameterEmitter;
use Llmor\Cli\Manifest\Writer\ScscBlock;
use Llmor\Cli\Manifest\Writer\ValueExtractor;
use Llmor\Cli\Manifest\Writer\WrittenDeclaration;
use Llmor\Cli\Sync\ParameterMerger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The writer's contract, asserted the only way that is actually convincing: emit a
 * definition, hand the text to the real {@see ManifestParser}, and require the app that
 * comes back to be the one that went in.
 */
#[CoversClass(AppDeclarationWriter::class)]
#[CoversClass(ParameterEmitter::class)]
#[CoversClass(EmittedValue::class)]
#[CoversClass(ScscBlock::class)]
#[CoversClass(WrittenDeclaration::class)]
final class AppDeclarationWriterTest extends TestCase
{
    /**
     * @return iterable<string, array{AppDefinition}>
     */
    public static function definitions(): iterable
    {
        yield 'minimal' => [self::app()];

        yield 'every scalar field' => [self::app(
            name: 'Support Bot',
            description: 'Answers customer questions.',
            model: 'gpt-4o',
            id: 17,
        )];

        yield 'every scalar type' => [self::app(parameters: [
            'text' => 'plain',
            'int' => 7,
            'negative' => -7,
            'float' => 0.2,
            'yes' => true,
            'no' => false,
            'nothing' => null,
        ])];

        yield 'deeply nested maps' => [self::app(parameters: [
            'a' => ['b' => ['c' => ['d' => 1]]],
        ])];

        yield 'lists' => [self::app(parameters: [
            'strings' => ['a', 'b'],
            'maps' => [['k' => 1], ['k' => 2]],
            'mixed' => [1, 'two', 3.5],
            'empty' => [],
        ])];

        // A one-element list of a bare literal is the case that forces the trailing
        // comma: `{ true }` would read as a block with a valueless key.
        yield 'single-element lists' => [self::app(parameters: [
            'just_true' => [true],
            'just_null' => [null],
            'just_word' => ['high'],
            'just_number' => [1],
        ])];

        yield 'awkward keys' => [self::app(parameters: [
            'extra.body' => 1,
            'ns:key' => 2,
            'type' => 3,
            '0' => 4,
            '_x' => 5,
        ])];

        yield 'awkward strings' => [self::app(
            description: 'It\'s got "both" kinds of quote',
            parameters: [
                'multiline' => "line one\nline two\n",
                'backslash' => 'C:\path',
                'trailing_backslash' => 'ends\\',
                'numeric_string' => '0.2',
                'keyword_string' => 'null',
            ],
        )];

        yield 'functions with and without config' => [self::app(functions: [
            new FunctionLink('greeter'),
            new FunctionLink('weather', ParameterMerger::bag(['units' => 'metric'])),
        ])];

        // Absent and empty are different claims, and both have to survive the trip.
        yield 'functions declared empty' => [self::app(functions: [])];
        yield 'subagents declared empty' => [self::app(subagents: [])];

        yield 'subagents' => [self::app(subagents: [
            new SubagentDefinition(
                alias: 'research',
                target: 'research_bot',
                description: 'Looks things up.',
                exposeAsTool: true,
                toolName: 'deep_research',
                toolDescription: 'Ask it something.',
                inputDescription: 'The question.',
            ),
            new SubagentDefinition(alias: 'triage', target: '21', targetId: 21),
            new SubagentDefinition(alias: 'bare', target: 'research_bot'),
        ])];
    }

    #[DataProvider('definitions')]
    public function testADefinitionSurvivesBeingWrittenAndParsedBack(AppDefinition $app): void
    {
        $written = (new AppDeclarationWriter())->write($app);

        self::assertSame([], $written->files, 'nothing should be extracted without an extractor');
        self::assertSame($written->scsc, $written->inline);

        $back = self::reparse($written->inline);

        self::assertSame($app->appKey, $back->appKey);
        self::assertSame($app->name, $back->name);
        self::assertSame($app->description, $back->description);
        self::assertSame($app->model, $back->model);
        self::assertSame($app->id, $back->id);
        self::assertEquals($app->functions, $back->functions);
        self::assertEquals($app->subagents, $back->subagents);
        self::assertTrue(
            ParameterMerger::equals($app->parameters, $back->parameters),
            \sprintf("parameters differ.\n%s", $written->inline),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function lossyBags(): iterable
    {
        yield 'inexpressible key' => [['top-k' => 40, 'ok' => 1], 'top-k'];
        yield 'inexpressible nested key' => [['nested' => ['a-b' => 1, 'ok' => 2]], 'nested.a-b'];
        yield 'inexpressible key inside a list' => [['examples' => [['a-b' => 1]]], 'examples'];
        yield 'number with no decimal spelling' => [['big' => 1.0e100], 'big'];
    }

    /**
     * A dropped key has to be *provably* harmless, not just plausibly harmless: what
     * makes leaving one out safe is that `[parameters]` are overrides, so re-applying
     * the emitted declaration over the app's own bag must change nothing at all — which
     * is exactly the comparison the next `sync` makes.
     *
     * @param array<string, mixed> $bag
     */
    #[DataProvider('lossyBags')]
    public function testAValueThatCannotBeWrittenIsLeftOutHarmlessly(array $bag, string $reportedPath): void
    {
        $written = (new AppDeclarationWriter())->write(self::app(parameters: $bag));
        $back = self::reparse($written->inline);

        $original = ParameterMerger::bag($bag);
        self::assertTrue(
            ParameterMerger::equals($original, ParameterMerger::merge($original, $back->parameters)),
            \sprintf("re-applying the declaration changed the bag.\n%s", $written->inline),
        );

        self::assertNotSame([], $written->notes, 'a compromise must be reported');
        self::assertStringContainsString($reportedPath, \implode("\n", $written->notes));
    }

    public function testAListIsDroppedWholeRatherThanPartially(): void
    {
        // Lists replace wholesale, so a list that lost an element's key would delete
        // remote data on the next sync. It has to go entirely or not at all.
        $written = (new AppDeclarationWriter())->write(self::app(parameters: [
            'examples' => [['a-b' => 1, 'keep' => 2]],
        ]));

        self::assertStringNotContainsString('examples', $written->inline);
        self::assertStringNotContainsString('keep', $written->inline);
        self::assertStringContainsString('replaced as a whole', \implode("\n", $written->notes));
    }

    public function testLongValuesAreExtractedToFilesAndReferencedWithFile(): void
    {
        $prompt = "You are a support agent.\nBe brief.\n";
        $written = (new AppDeclarationWriter(new ValueExtractor()))->write(self::app(parameters: [
            'prompt' => $prompt,
            'temperature' => 0.2,
        ]));

        self::assertSame(['prompts/support_bot_prompt.md' => $prompt], $written->files);
        self::assertStringContainsString("@file('./prompts/support_bot_prompt.md')", $written->scsc);
        self::assertStringContainsString("prompt = ''", $written->scsc);
        self::assertStringNotContainsString('Be brief', $written->scsc);

        // The inline variant is what verification parses, so it must still be complete.
        self::assertStringContainsString('Be brief', $written->inline);
        self::assertStringNotContainsString('@file', $written->inline);
    }

    public function testShortValuesStayInline(): void
    {
        $written = (new AppDeclarationWriter(new ValueExtractor()))->write(self::app(parameters: [
            'prompt' => 'Be nice.',
        ]));

        self::assertSame([], $written->files);
        self::assertStringContainsString("prompt = 'Be nice.'", $written->scsc);
    }

    public function testExtractedFilesTakeAnExtensionFromTheirKey(): void
    {
        $long = \str_repeat('x', 200);
        $written = (new AppDeclarationWriter(new ValueExtractor()))->write(self::app(parameters: [
            'code' => $long,
            'output_json_schema' => $long,
            'custom_css' => $long,
        ]));

        self::assertSame([
            'prompts/support_bot_code.lua',
            'prompts/support_bot_output_json_schema.json',
            'prompts/support_bot_custom_css.css',
        ], \array_keys($written->files));
    }

    public function testKeysThatSanitiseToTheSameFilenameDoNotOverwriteEachOther(): void
    {
        $written = (new AppDeclarationWriter(new ValueExtractor()))->write(self::app(parameters: [
            'a.prompt' => \str_repeat('x', 200),
            'a_prompt' => \str_repeat('y', 200),
        ]));

        self::assertSame([
            'prompts/support_bot_a_prompt.md',
            'prompts/support_bot_a_prompt_2.md',
        ], \array_keys($written->files));
    }

    /**
     * @param array<array-key, mixed>   $parameters PHP re-keys a literal '0', hence array-key
     * @param ?list<FunctionLink>       $functions
     * @param ?list<SubagentDefinition> $subagents
     */
    private static function app(
        ?string $name = null,
        ?string $description = null,
        ?string $model = null,
        ?int $id = null,
        array $parameters = [],
        ?array $functions = null,
        ?array $subagents = null,
    ): AppDefinition {
        return new AppDefinition(
            declaration: 'support_bot',
            appKey: 'llmor/generic',
            name: $name,
            description: $description,
            model: $model,
            id: $id,
            parameters: ParameterMerger::bag($parameters),
            functions: $functions,
            subagents: $subagents,
        );
    }

    private static function reparse(string $source): AppDefinition
    {
        // The sub-agent targets have to resolve, so the peer is declared alongside.
        $peer = "research_bot: App {\n  [app_key] = 'llmor/generic'\n}\n";
        $manifest = (new ManifestParser())->parse($source."\n\n".$peer, '/manifest/llmor.scsc', '/manifest');

        $app = $manifest->getApp('support_bot');
        self::assertNotNull($app, \sprintf("the declaration did not parse back as an app:\n%s", $source));

        return $app;
    }

    public function testAnEmptyParameterBagWritesNoBlockAtAll(): void
    {
        $written = (new AppDeclarationWriter())->write(self::app());

        self::assertStringNotContainsString('[parameters]', $written->scsc);
        self::assertEquals(new stdClass(), self::reparse($written->scsc)->parameters);
    }
}
