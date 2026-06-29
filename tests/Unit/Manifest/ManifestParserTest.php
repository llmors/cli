<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Manifest;

use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\ManifestParser;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManifestParser::class)]
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

        self::assertNotNull($manifest->get('pjas_silicon_docs'));
        self::assertNull($manifest->get('missing'));
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
