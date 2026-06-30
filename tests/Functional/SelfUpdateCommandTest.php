<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\SelfUpdate\SelfUpdateCommand;
use Phar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Drives the GitHub-backed self-update flow against a mocked HTTP transport and
 * a throwaway on-disk "binary".
 */
#[CoversClass(SelfUpdateCommand::class)]
final class SelfUpdateCommandTest extends TestCase
{
    private string $dir;
    private string $binary;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir().'/llmor-su-'.\uniqid('', true);
        \mkdir($this->dir, 0o700, true);
        $this->binary = $this->dir.'/llmor';
        \file_put_contents($this->binary, 'OLD-BINARY');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir.'/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testReportsUpToDateWhenCurrent(): void
    {
        $tester = $this->tester('1.0.0', $this->mockReleaseOnly('v1.0.0'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('up to date', $tester->getDisplay());
        self::assertSame('OLD-BINARY', (string) \file_get_contents($this->binary));
    }

    public function testCheckReportsAvailableUpdateWithoutWriting(): void
    {
        $tester = $this->tester('1.0.0', $this->mockReleaseOnly('v1.2.0'));

        self::assertSame(0, $tester->execute(['--check' => true]));
        self::assertStringContainsString('1.2.0', $tester->getDisplay());
        self::assertSame('OLD-BINARY', (string) \file_get_contents($this->binary), 'check must not modify the binary');
    }

    public function testNonPharRunPrintsGuidance(): void
    {
        $tester = new CommandTester(new SelfUpdateCommand(new MockHttpClient([]), '1.0.0', null));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('source checkout', $tester->getDisplay());
    }

    public function testBadChecksumLeavesBinaryUntouched(): void
    {
        $http = $this->mockRelease('v2.0.0', 'NEW-BINARY', \str_repeat('0', 64));
        $tester = $this->tester('1.0.0', $http);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Checksum', $tester->getDisplay());
        self::assertSame('OLD-BINARY', (string) \file_get_contents($this->binary));
        self::assertCount(0, \glob($this->dir.'/.llmor-update-*') ?: [], 'the temp download must be cleaned up');
    }

    public function testUpdatesBinaryInPlace(): void
    {
        if (\filter_var(\ini_get('phar.readonly'), \FILTER_VALIDATE_BOOL)) {
            self::markTestSkipped('phar.readonly is on; cannot build a phar fixture to validate the swap.');
        }

        [$bytes, $sha] = $this->buildPharFixture();
        $http = $this->mockRelease('v2.0.0', $bytes, $sha);
        $tester = $this->tester('1.0.0', $http);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Updated', $tester->getDisplay());
        self::assertSame($bytes, (string) \file_get_contents($this->binary), 'the binary must hold the new release bytes');
    }

    private function tester(string $version, MockHttpClient $http): CommandTester
    {
        return new CommandTester(new SelfUpdateCommand($http, $version, $this->binary));
    }

    private function mockReleaseOnly(string $tag): MockHttpClient
    {
        return $this->mockRelease($tag, 'unused', \str_repeat('a', 64));
    }

    private function mockRelease(string $tag, string $assetBody, string $sha256): MockHttpClient
    {
        $factory = static function (string $method, string $url) use ($tag, $assetBody, $sha256): MockResponse {
            if (\str_contains($url, 'api.github.com')) {
                return new MockResponse((string) \json_encode([
                    'tag_name' => $tag,
                    'assets' => [[
                        'name' => 'llmor.phar',
                        'browser_download_url' => 'https://github.com/llmors/cli/releases/download/'.$tag.'/llmor.phar',
                        'digest' => 'sha256:'.$sha256,
                    ]],
                ]), ['http_code' => 200]);
            }

            return new MockResponse($assetBody, ['http_code' => 200]);
        };

        return new MockHttpClient($factory);
    }

    /**
     * @return array{0: string, 1: string} the phar bytes and their sha256
     */
    private function buildPharFixture(): array
    {
        $path = $this->dir.'/fixture.phar';
        $phar = new Phar($path);
        $phar->addFromString('index.php', '<?php // llmor test fixture');
        $phar->setStub('<?php __HALT_COMPILER();');
        unset($phar);

        $bytes = (string) \file_get_contents($path);
        @\unlink($path);

        return [$bytes, \hash('sha256', $bytes)];
    }
}
