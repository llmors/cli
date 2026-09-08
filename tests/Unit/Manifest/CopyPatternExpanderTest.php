<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Manifest;

use Llmor\Cli\Manifest\CopyPatternExpander;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CopyPatternExpander::class)]
final class CopyPatternExpanderTest extends TestCase
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
     * @return iterable<string, array{string, bool}>
     */
    public static function sourceProvider(): iterable
    {
        yield 'literal path' => ['./docs/README.md', false];
        yield 'star' => ['./docs/*.md', true];
        yield 'globstar' => ['./docs/**/*.md', true];
        yield 'single char' => ['./docs/v?.md', true];
    }

    #[DataProvider('sourceProvider')]
    public function testIsPattern(string $source, bool $expected): void
    {
        self::assertSame($expected, CopyPatternExpander::isPattern($source));
    }

    public function testStarStaysInsideOneSegment(): void
    {
        $this->writeProjectFile('docs/a.md', 'a');
        $this->writeProjectFile('docs/b.md', 'b');
        $this->writeProjectFile('docs/notes.txt', 'n');
        $this->writeProjectFile('docs/nested/c.md', 'c');

        self::assertSame(
            ['a.md', 'b.md'],
            \array_values(CopyPatternExpander::expand($this->projectDir, './docs/*.md')),
            '*.md must not reach into docs/nested/, and must not pick up the .txt.',
        );
    }

    public function testGlobstarPreservesTheTreeBelowTheFixedPrefix(): void
    {
        $this->writeProjectFile('books/silicon/index.md', 'i');
        $this->writeProjectFile('books/silicon/data/dql.md', 'd');
        $this->writeProjectFile('books/silicon/data/index.md', 'di');
        $this->writeProjectFile('books/silicon/reports/deep/nested.md', 'n');

        $relative = \array_values(CopyPatternExpander::expand($this->projectDir, './books/silicon/**/*.md'));

        self::assertSame(
            ['data/dql.md', 'data/index.md', 'index.md', 'reports/deep/nested.md'],
            $relative,
            'the root index.md must match too, and same-basename files must stay distinguishable by their dir.',
        );
    }

    public function testMapsToAbsoluteSourcePaths(): void
    {
        $expected = $this->writeProjectFile('docs/a.md', 'a');

        $matches = CopyPatternExpander::expand($this->projectDir, './docs/*.md');

        self::assertSame([$expected => 'a.md'], $matches);
        self::assertFileExists(\array_key_first($matches));
    }

    public function testStarAlsoMatchesExtensionlessFilesButNotDirectories(): void
    {
        $this->writeProjectFile('docs/symbols.json', '{}');
        $this->writeProjectFile('docs/LICENSE', 'l');
        $this->writeProjectFile('docs/sub/inner.md', 'i');

        self::assertSame(
            ['LICENSE', 'symbols.json'],
            \array_values(CopyPatternExpander::expand($this->projectDir, './docs/*')),
            'docs/sub is a directory and must not be copied as a file.',
        );
    }

    public function testSkipsDotEntriesAtEveryLevel(): void
    {
        $this->writeProjectFile('docs/a.md', 'a');
        $this->writeProjectFile('docs/.DS_Store', 'junk');
        $this->writeProjectFile('docs/.git/HEAD', 'ref');
        $this->writeProjectFile('docs/.hidden/b.md', 'b');

        self::assertSame(
            ['a.md'],
            \array_values(CopyPatternExpander::expand($this->projectDir, './docs/**/*')),
            'a stray .DS_Store or a .git directory must never join a bundle.',
        );
    }

    public function testReturnsEmptyForAMissingRoot(): void
    {
        self::assertSame([], CopyPatternExpander::expand($this->projectDir, './nope/*.md'));
    }

    public function testWildcardInAMiddleSegment(): void
    {
        $this->writeProjectFile('books/silicon/data/a.md', 'a');
        $this->writeProjectFile('books/designer/data/b.md', 'b');
        $this->writeProjectFile('books/silicon/other/c.md', 'c');

        self::assertSame(
            ['designer/data/b.md', 'silicon/data/a.md'],
            \array_values(CopyPatternExpander::expand($this->projectDir, './books/*/data/*.md')),
        );
    }

    public function testQuestionMarkMatchesExactlyOneCharacter(): void
    {
        $this->writeProjectFile('docs/v1.md', '1');
        $this->writeProjectFile('docs/v2.md', '2');
        $this->writeProjectFile('docs/v10.md', '10');

        self::assertSame(
            ['v1.md', 'v2.md'],
            \array_values(CopyPatternExpander::expand($this->projectDir, './docs/v?.md')),
        );
    }
}
