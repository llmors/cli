<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Manifest\Writer;

use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\Writer\ManifestAppender;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManifestAppender::class)]
final class ManifestAppenderTest extends TestCase
{
    use TempProject;

    private const DECLARATION = "support_bot: App {\n  [app_key] = 'llmor/generic'\n}";

    protected function setUp(): void
    {
        $this->makeProject();
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    /**
     * Existing content, paired with the body that must survive it byte for byte.
     *
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function existingContent(): iterable
    {
        $simple = "greeter: Function {\n}";
        $commented = "// mine\n\ngreeter: Function {\n\n  // keep this\n}";

        yield 'no trailing newline' => [$simple, $simple];
        yield 'one trailing newline' => [$simple."\n", $simple];
        yield 'several trailing newlines' => [$simple."\n\n\n", $simple];
        yield 'comments and blank lines' => [$commented."\n", $commented];
    }

    /**
     * The contract that makes this safe to run against a hand-maintained file: nothing
     * already in it changes, whatever its formatting. Only the run of newlines at the
     * very end is normalised.
     *
     * @param non-empty-string $body
     */
    #[DataProvider('existingContent')]
    public function testTheExistingContentSurvivesVerbatim(string $original, string $body): void
    {
        $composed = ManifestAppender::at($this->write($original))->compose(self::DECLARATION);

        self::assertStringStartsWith($body, $composed);
        self::assertStringContainsString($body."\n\n".self::DECLARATION, $composed, 'exactly one blank line at the seam');
        self::assertStringEndsWith("}\n", $composed);
        self::assertStringNotContainsString("\n\n\n", \substr($composed, \strlen($body) - 1));
    }

    public function testAMissingFileBecomesTheDeclarationAlone(): void
    {
        $appender = ManifestAppender::at($this->projectPath('llmor.scsc'));

        self::assertFalse($appender->exists());
        self::assertSame(self::DECLARATION."\n", $appender->compose(self::DECLARATION));
    }

    public function testAnEmptyFileGetsNoLeadingBlankLine(): void
    {
        self::assertSame(
            self::DECLARATION."\n",
            ManifestAppender::at($this->write("\n\n"))->compose(self::DECLARATION),
        );
    }

    /** A Windows-authored manifest must not end up with mixed line endings. */
    public function testCrlfLineEndingsArePreserved(): void
    {
        $composed = ManifestAppender::at($this->write("greeter: Function {\r\n}\r\n"))->compose(self::DECLARATION);

        self::assertStringStartsWith("greeter: Function {\r\n}", $composed);
        self::assertStringEndsWith("}\r\n", $composed);
    }

    public function testAppendWritesAtomicallyAndLeavesNoTempFile(): void
    {
        $path = $this->write("greeter: Function {\n}\n");

        ManifestAppender::at($path)->append(self::DECLARATION);

        self::assertSame("greeter: Function {\n}\n\n".self::DECLARATION."\n", $this->readProjectFile('llmor.scsc'));
        self::assertFileDoesNotExist($path.'.tmp');
    }

    public function testAppendCreatesTheFileWhenThereIsNone(): void
    {
        ManifestAppender::at($this->projectPath('llmor.scsc'))->append(self::DECLARATION);

        self::assertSame(self::DECLARATION."\n", $this->readProjectFile('llmor.scsc'));
    }

    /**
     * The bytes read at construction are what verification ran against, so an edit that
     * landed in between has to be refused rather than overwritten.
     */
    public function testAConcurrentEditIsRefusedRatherThanClobbered(): void
    {
        $path = $this->write("greeter: Function {\n}\n");
        $appender = ManifestAppender::at($path);

        $this->write("someone: Function {\n}\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('changed while the app was being imported');

        try {
            $appender->append(self::DECLARATION);
        } finally {
            self::assertSame("someone: Function {\n}\n", $this->readProjectFile('llmor.scsc'));
        }
    }

    private function write(string $content): string
    {
        return $this->writeProjectFile('llmor.scsc', $content);
    }
}
