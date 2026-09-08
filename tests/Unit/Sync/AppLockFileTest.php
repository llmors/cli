<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

use Llmor\Cli\Sync\AppLockFile;
use Llmor\Cli\Sync\SyncException;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppLockFile::class)]
final class AppLockFileTest extends TestCase
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

    public function testRecordsAndReadsBackABinding(): void
    {
        $lock = $this->lock();
        $lock->record('acme-co', 'support_bot', 17, 'llmor/generic');

        self::assertSame(['id' => 17, 'app_key' => 'llmor/generic'], $lock->lookup('acme-co', 'support_bot'));
        self::assertNull($lock->lookup('acme-co', 'other'));
        self::assertNull($lock->lookup('other-vendor', 'support_bot'), 'Bindings are per vendor.');
        self::assertTrue($lock->wasWritten());
    }

    public function testWritesBesideTheManifest(): void
    {
        $lock = AppLockFile::besideManifest($this->projectPath('llmor.scsc'));
        $lock->record('acme-co', 'a', 1, 'llmor/generic');

        self::assertFileExists($this->projectPath('llmor.lock'));
    }

    public function testPartialUpdatePreservesOtherAppsAndOtherVendors(): void
    {
        // A `--app x` run must not disturb anything else in the file.
        $this->writeProjectFile('llmor.lock', (string) \json_encode([
            'version' => 1,
            'vendors' => [
                'acme-co' => ['apps' => [
                    'keep_me' => ['id' => 1, 'app_key' => 'llmor/generic'],
                    'update_me' => ['id' => 2, 'app_key' => 'llmor/generic'],
                ]],
                'staging' => ['apps' => ['keep_me' => ['id' => 99, 'app_key' => 'llmor/generic']]],
            ],
        ]));

        $lock = $this->lock();
        $lock->record('acme-co', 'update_me', 3, 'llmor/generic');

        $document = $this->decodeLock();

        self::assertSame(1, $document['vendors']['acme-co']['apps']['keep_me']['id']);
        self::assertSame(3, $document['vendors']['acme-co']['apps']['update_me']['id']);
        self::assertSame(99, $document['vendors']['staging']['apps']['keep_me']['id'], "Another vendor's section is untouched.");
    }

    public function testPreservesKeysWrittenByAFutureVersion(): void
    {
        $this->writeProjectFile('llmor.lock', (string) \json_encode([
            'version' => 1,
            'note' => 'hand-written',
            'vendors' => ['acme-co' => [
                'apps' => ['a' => ['id' => 1, 'app_key' => 'llmor/generic', 'extra' => 'kept']],
                'datastores' => ['something' => 5],
            ]],
        ]));

        $this->lock()->record('acme-co', 'b', 2, 'llmor/silicon');
        $document = $this->decodeLock();

        self::assertSame('hand-written', $document['note']);
        self::assertSame('kept', $document['vendors']['acme-co']['apps']['a']['extra']);
        self::assertSame(['something' => 5], $document['vendors']['acme-co']['datastores']);
    }

    public function testOutputIsDeterministicAndDiffFriendly(): void
    {
        $lock = $this->lock();
        $lock->record('zeta-co', 'zz', 3, 'llmor/generic');
        $lock->record('acme-co', 'bb', 2, 'llmor/generic');
        $lock->record('acme-co', 'aa', 1, 'llmor/generic');

        $raw = $this->readProjectFile('llmor.lock');

        self::assertStringEndsWith("\n", $raw, 'A committed file needs a trailing newline.');
        self::assertLessThan(
            \strpos($raw, 'zeta-co'),
            (int) \strpos($raw, 'acme-co'),
            'Vendors are sorted so the file does not churn.',
        );
        self::assertLessThan((int) \strpos($raw, '"bb"'), (int) \strpos($raw, '"aa"'), 'Apps are sorted too.');
        self::assertStringNotContainsString('\/', $raw, 'Slashes stay unescaped so app keys are readable.');
    }

    public function testRecordingTheSameBindingTwiceDoesNotRewrite(): void
    {
        $this->lock()->record('acme-co', 'a', 1, 'llmor/generic');

        $second = $this->lock();
        $second->record('acme-co', 'a', 1, 'llmor/generic');

        self::assertFalse($second->wasWritten(), 'An unchanged lock file must not be rewritten on every sync.');
    }

    public function testLeavesNoTemporaryFileBehind(): void
    {
        $this->lock()->record('acme-co', 'a', 1, 'llmor/generic');

        self::assertFileDoesNotExist($this->projectPath('llmor.lock.tmp'));
    }

    public function testForgetDropsOneBinding(): void
    {
        $lock = $this->lock();
        $lock->record('acme-co', 'a', 1, 'llmor/generic');
        $lock->record('acme-co', 'b', 2, 'llmor/generic');

        $lock->forget('acme-co', 'a');

        self::assertNull($lock->lookup('acme-co', 'a'));
        self::assertNotNull($lock->lookup('acme-co', 'b'));
    }

    public function testMalformedEntryIsDroppedWithAWarning(): void
    {
        $this->writeProjectFile('llmor.lock', (string) \json_encode([
            'version' => 1,
            'vendors' => ['acme-co' => ['apps' => ['a' => ['id' => 'not-a-number']]]],
        ]));

        $lock = $this->lock();

        self::assertNull($lock->lookup('acme-co', 'a'));
        self::assertCount(1, $lock->warnings);
        self::assertStringContainsString('malformed', $lock->warnings[0]);
    }

    public function testClaimedIdsListsEveryBoundAppForTheVendor(): void
    {
        $lock = $this->lock();
        $lock->record('acme-co', 'a', 1, 'llmor/generic');
        $lock->record('acme-co', 'b', 2, 'llmor/generic');
        $lock->record('staging', 'c', 3, 'llmor/generic');

        self::assertSame(['a' => 1, 'b' => 2], $lock->claimedIds('acme-co'));
    }

    public function testAFutureFormatVersionIsAHardError(): void
    {
        $this->writeProjectFile('llmor.lock', (string) \json_encode(['version' => 2, 'vendors' => []]));

        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/newer llmor/');

        $this->lock()->lookup('acme-co', 'a');
    }

    public function testInvalidJsonIsAHardError(): void
    {
        $this->writeProjectFile('llmor.lock', '{ not json');

        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $this->lock()->lookup('acme-co', 'a');
    }

    public function testAnEmptyFileIsTreatedAsNoBindings(): void
    {
        $this->writeProjectFile('llmor.lock', "\n");

        self::assertNull($this->lock()->lookup('acme-co', 'a'));
    }

    private function lock(): AppLockFile
    {
        return new AppLockFile($this->projectPath('llmor.lock'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeLock(): array
    {
        /** @var array<string, mixed> $document */
        $document = \json_decode($this->readProjectFile('llmor.lock'), true, 32, \JSON_THROW_ON_ERROR);

        return $document;
    }
}
