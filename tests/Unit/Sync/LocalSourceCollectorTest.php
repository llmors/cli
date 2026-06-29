<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

use Llmor\Cli\Manifest\FunctionDefinition;
use Llmor\Cli\Sync\LocalSourceCollector;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalSourceCollector::class)]
final class LocalSourceCollectorTest extends TestCase
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

    public function testCollectsAuxiliaryFilesExcludingEntryAndBinary(): void
    {
        $this->writeProjectFile('main/main.lua', "return 1\n");
        $this->writeProjectFile('main/notes.md', "# Notes\n");
        $this->writeProjectFile('main/sub/more.md', 'deeper');
        $this->writeProjectFile('main/blob.bin', "\xff\xfe\x00binary");

        $collected = (new LocalSourceCollector())->collect($this->definition());

        $paths = \array_map(static fn ($f) => $f->path, $collected->files);
        self::assertSame(['notes.md', 'sub/more.md'], $paths, 'Entry is excluded, binary is skipped, result is sorted.');
        self::assertSame(\hash('sha256', "# Notes\n"), $collected->files[0]->sha256);

        self::assertCount(1, $collected->warnings);
        self::assertStringContainsString('blob.bin', $collected->warnings[0]);
    }

    private function definition(): FunctionDefinition
    {
        return new FunctionDefinition(
            functionKey: 'docs',
            name: 'Docs',
            description: 'Docs',
            runtime: 'silicon',
            srcdir: './main',
            entry: 'main.lua',
            srcdirPath: $this->projectDir.'/main',
            entryPath: $this->projectDir.'/main/main.lua',
        );
    }
}
