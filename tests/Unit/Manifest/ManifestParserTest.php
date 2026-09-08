<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Manifest;

use Llmor\Cli\Manifest\Builder\ConfigDefinitionBuilder;
use Llmor\Cli\Manifest\ConfigDefinition;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\ManifestParser;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManifestParser::class)]
#[CoversClass(ConfigDefinitionBuilder::class)]
#[CoversClass(ConfigDefinition::class)]
final class ManifestParserTest extends TestCase
{
    use TempProject;

    protected function setUp(): void
    {
        $this->makeProject();
        $this->writeProjectFile('main/main.lua', "return success('hi')\n");
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function testExtractsOnlyFunctionDeclarations(): void
    {
        $manifest = (new ManifestParser())->parse($this->validManifest(), 'llmor.scsc', $this->projectDir);

        self::assertCount(1, $manifest->functions, 'MCP and the private type definition must be ignored.');

        $function = $manifest->functions[0];
        self::assertSame('pjas_silicon_docs', $function->functionKey);
        self::assertSame('PJAS Silicon Docs', $function->name);
        self::assertSame('A collection of documentation for PJAS Silicon.', $function->description);
        self::assertSame('silicon', $function->runtime);
        self::assertSame('main.lua', $function->entry);
        self::assertStringEndsWith('/main', $function->srcdirPath);
        self::assertSame("return success('hi')\n", $function->readCode());
    }

    public function testGetReturnsFunctionByKey(): void
    {
        $manifest = (new ManifestParser())->parse($this->validManifest(), 'llmor.scsc', $this->projectDir);

        self::assertNotNull($manifest->getFunction('pjas_silicon_docs'));
        self::assertNull($manifest->getFunction('missing'));
    }

    public function testRejectsUnknownRuntime(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[runtime\]/');

        (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='python'\n  [srcdir]='./main'\n  [entry]='main.lua'\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testRejectsMissingRequiredMetadata(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[name\] is required/');

        (new ManifestParser())->parse(
            "f: Function {\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testRejectsMissingEntryFile(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[entry\]/');

        (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='nope.lua'\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testResolvesCopyInstructions(): void
    {
        $this->writeProjectFile('README.md', "# readme\n");

        $manifest = (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n  @path('docs/')\n  [copy] = {\n    './README.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );

        $copies = $manifest->functions[0]->copies;
        self::assertCount(1, $copies);
        self::assertSame('docs/README.md', $copies[0]->destination);
        self::assertFileExists($copies[0]->sourcePath);
        self::assertSame("# readme\n", \file_get_contents($copies[0]->sourcePath));
    }

    public function testCopyWithoutPathAnnotationLandsAtRoot(): void
    {
        $this->writeProjectFile('README.md', "# readme\n");

        $manifest = (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n  [copy] = {\n    './README.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );

        self::assertSame('README.md', $manifest->functions[0]->copies[0]->destination);
    }

    public function testRejectsMissingCopySource(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[copy\] source/');

        (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n  @path('docs/')\n  [copy] = {\n    './nope.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testResolvesMultipleCopyBlocks(): void
    {
        $this->writeProjectFile('help/silicon/index.md', "# silicon\n");
        $this->writeProjectFile('help/sandbox/index.md', "# sandbox\n");

        $manifest = (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n"
            ."  @path('docs/silicon/')\n  [copy] = {\n    './help/silicon/index.md',\n  }\n"
            ."  @path('docs/sandbox/')\n  [copy] = {\n    './help/sandbox/index.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );

        $copies = $manifest->functions[0]->copies;
        self::assertCount(2, $copies, 'both [copy] blocks must survive — the second must not override the first.');
        $destinations = \array_map(static fn ($c): string => $c->destination, $copies);
        self::assertSame(['docs/silicon/index.md', 'docs/sandbox/index.md'], $destinations);
    }

    public function testRejectsCollidingCopyDestinationsAcrossBlocks(): void
    {
        $this->writeProjectFile('a/index.md', "# a\n");
        $this->writeProjectFile('b/index.md', "# b\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/declared more than once/');

        (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n"
            ."  @path('docs/')\n  [copy] = {\n    './a/index.md',\n  }\n"
            ."  @path('docs/')\n  [copy] = {\n    './b/index.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testExpandsAWildcardCopySource(): void
    {
        $this->writeProjectFile('help/a.md', "# a\n");
        $this->writeProjectFile('help/b.md', "# b\n");
        $this->writeProjectFile('help/notes.txt', "skip\n");

        $manifest = (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n  @path('docs/')\n  [copy] = {\n    './help/*.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );

        $destinations = \array_map(
            static fn ($c): string => $c->destination,
            $manifest->functions[0]->copies,
        );
        self::assertSame(['docs/a.md', 'docs/b.md'], $destinations);
    }

    public function testRecursiveWildcardKeepsTheSourceTreeShape(): void
    {
        $this->writeProjectFile('books/index.md', "# root\n");
        $this->writeProjectFile('books/data/index.md', "# data\n");
        $this->writeProjectFile('books/data/dql.md', "# dql\n");

        $manifest = (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n  @path('docs/book/')\n  [copy] = {\n    './books/**/*.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );

        $destinations = \array_map(
            static fn ($c): string => $c->destination,
            $manifest->functions[0]->copies,
        );
        self::assertSame(
            ['docs/book/data/dql.md', 'docs/book/data/index.md', 'docs/book/index.md'],
            $destinations,
            'one pattern must mirror the tree — two index.md files cannot both flatten onto docs/book/index.md.',
        );
    }

    public function testRejectsWildcardCopySourceMatchingNothing(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/matched no files/');

        (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n  @path('docs/')\n  [copy] = {\n    './help/*.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testStillRejectsCollisionsBetweenAWildcardAndALiteral(): void
    {
        $this->writeProjectFile('help/a.md', "# a\n");
        $this->writeProjectFile('other/a.md', "# other a\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/declared more than once/');

        (new ManifestParser())->parse(
            "f: Function {\n  [name]='F'\n  [description]='D'\n  [runtime]='silicon'\n  [srcdir]='./main'\n  [entry]='main.lua'\n"
            ."  @path('docs/')\n  [copy] = {\n    './help/*.md',\n    './other/a.md',\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testReadsTheProjectConfigBlock(): void
    {
        $manifest = (new ManifestParser())->parse(
            "llmor: Config {\n  [prompt_dir] = './resources/prompts'\n}",
            'llmor.scsc',
            $this->projectDir,
        );

        self::assertSame('resources/prompts', $manifest->config->promptDir);
        self::assertSame([], $manifest->functions, 'a Config block declares nothing syncable.');
        self::assertSame([], $manifest->apps);
    }

    public function testDefaultsThePromptDirWithoutAConfigBlock(): void
    {
        $manifest = (new ManifestParser())->parse($this->validManifest(), 'llmor.scsc', $this->projectDir);

        self::assertSame(ConfigDefinition::DEFAULT_PROMPT_DIR, $manifest->config->promptDir);
    }

    /**
     * The value ends up both in an `@file('./…')` reference and joined onto the manifest
     * directory, so however it is spelled it has to arrive as one plain relative path.
     */
    #[DataProvider('promptDirSpellings')]
    public function testNormalisesThePromptDir(string $declared): void
    {
        $manifest = (new ManifestParser())->parse(
            "llmor: Config {\n  [prompt_dir] = '$declared'\n}",
            'llmor.scsc',
            $this->projectDir,
        );

        self::assertSame('resources/prompts', $manifest->config->promptDir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function promptDirSpellings(): iterable
    {
        yield 'plain' => ['resources/prompts'];
        yield 'dot-slash prefixed' => ['./resources/prompts'];
        yield 'trailing slash' => ['./resources/prompts/'];
        yield 'windows separators' => ['.\\resources\\prompts'];
    }

    #[DataProvider('unusablePromptDirs')]
    public function testRejectsAPromptDirThatIsNotAPlaceInTheProject(string $declared): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[prompt_dir\]/');

        (new ManifestParser())->parse(
            "llmor: Config {\n  [prompt_dir] = '$declared'\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusablePromptDirs(): iterable
    {
        yield 'empty' => [''];
        yield 'the manifest directory itself' => ['.'];
        yield 'escaping the project' => ['../outside'];
        yield 'escaping further down' => ['./resources/../../outside'];
        yield 'absolute' => ['/etc/llmor'];
        yield 'windows absolute' => ['C:\\prompts'];
    }

    public function testRejectsAnUnknownConfigSetting(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[prompts_dir\] is not a setting/');

        (new ManifestParser())->parse(
            "llmor: Config {\n  [prompts_dir] = './prompts'\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testRejectsASecondConfigBlock(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/more than one ": Config" block/');

        (new ManifestParser())->parse(
            "llmor: Config {\n  [prompt_dir] = './a'\n}\n\nother: Config {\n  [prompt_dir] = './b'\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testConfigSharesTheDeclarationNamespace(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/Duplicate declaration "llmor"/');

        (new ManifestParser())->parse(
            "llmor: Config {\n  [prompt_dir] = './prompts'\n}\n\nllmor: App {\n  [app_type] = 'llmor/generic'\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    public function testNamesTheKindWhenASubagentTargetsTheConfigBlock(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/which is a config, not an app/');

        (new ManifestParser())->parse(
            "llmor: Config {\n  [prompt_dir] = './prompts'\n}\n\na: App {\n  [app_type] = 'llmor/generic'\n  [subagents] = {\n    [helper] = { [app] = llmor }\n  }\n}",
            'llmor.scsc',
            $this->projectDir,
        );
    }

    private function validManifest(): string
    {
        return <<<SCSC
            private Function {
            }

            pjas_silicon_docs: Function {
              [name]        = 'PJAS Silicon Docs'
              [description] = 'A collection of documentation for PJAS Silicon.'
              [runtime]     = 'silicon'
              [srcdir]      = './main'
              [entry]       = 'main.lua'
            }

            my_mcp: MCP {
              [name] = 'My MCP'
              [functions] = {
                pjas_silicon_docs
              }
            }
            SCSC;
    }
}
