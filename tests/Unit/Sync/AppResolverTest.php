<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Sync\AppLockFile;
use Llmor\Cli\Sync\AppResolver;
use Llmor\Cli\Sync\RemoteAppIndex;
use Llmor\Cli\Sync\ResolvedApp;
use Llmor\Cli\Sync\SyncException;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppResolver::class)]
#[CoversClass(RemoteAppIndex::class)]
#[CoversClass(ResolvedApp::class)]
final class AppResolverTest extends TestCase
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

    public function testALockedBindingWins(): void
    {
        $lock = $this->lock();
        $lock->record('acme-co', 'support', 17, 'llmor/generic');

        $resolved = $this->resolve($this->app(name: 'Renamed Since'), $lock, [
            ['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/generic'],
        ]);

        self::assertSame(17, $resolved->id);
        self::assertSame(ResolvedApp::ORIGIN_LOCK, $resolved->origin, 'A rename in the manifest must not lose the binding.');
    }

    public function testAnIdPinBeatsTheLockFile(): void
    {
        $lock = $this->lock();
        $lock->record('acme-co', 'support', 17, 'llmor/generic');

        $resolved = $this->resolve($this->app(id: 18), $lock, [
            ['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/generic'],
            ['id' => 18, 'name' => 'Other', 'app_key' => 'llmor/generic'],
        ]);

        self::assertSame(18, $resolved->id);
        self::assertSame(ResolvedApp::ORIGIN_PIN, $resolved->origin);
    }

    public function testAdoptsAnExistingAppMatchedByNameAndAppKey(): void
    {
        // The common first sync: the app was built in the console, and creating a
        // second one would be both confusing and impossible to clean up.
        $resolved = $this->resolve($this->app(), $this->lock(), [
            ['id' => 5, 'name' => 'Support Bot', 'app_key' => 'llmor/silicon'],
            ['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/generic'],
            ['id' => 18, 'name' => 'Something Else', 'app_key' => 'llmor/generic'],
        ]);

        self::assertSame(17, $resolved->id);
        self::assertSame(ResolvedApp::ORIGIN_ADOPTED, $resolved->origin);
        self::assertStringContainsString('Adopted existing app #17', $resolved->warnings[0], 'Taking over a record a human made must be visible.');
    }

    public function testAmbiguousAdoptionIsAnError(): void
    {
        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/matches 2 existing apps.*\[id\]/s');

        $this->resolve($this->app(), $this->lock(), [
            ['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/generic'],
            ['id' => 18, 'name' => 'Support Bot', 'app_key' => 'llmor/generic'],
        ]);
    }

    public function testAnAppWithNoDeclaredNameIsNeverAdopted(): void
    {
        $resolved = $this->resolve($this->app(name: null), $this->lock(), [
            ['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/generic'],
        ]);

        self::assertNull($resolved->id);
        self::assertSame(ResolvedApp::ORIGIN_NEW, $resolved->origin);
    }

    public function testAnAlreadyClaimedAppIsNotAdoptedTwice(): void
    {
        // Two declarations sharing a name would otherwise fight over one app forever.
        $lock = $this->lock();
        $lock->record('acme-co', 'first', 17, 'llmor/generic');

        $resolved = $this->resolve($this->app(declaration: 'second'), $lock, [
            ['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/generic'],
        ]);

        self::assertNull($resolved->id, 'The second declaration creates its own app rather than hijacking the first.');
    }

    public function testAStaleLockEntryIsDroppedAndTheAppRecreated(): void
    {
        $lock = $this->lock();
        $lock->record('acme-co', 'support', 17, 'llmor/generic');

        $resolved = $this->resolve($this->app(name: null), $lock, []);

        self::assertNull($resolved->id);
        self::assertStringContainsString('no longer exists', $resolved->warnings[0]);
        self::assertNull($lock->lookup('acme-co', 'support'), 'The dead binding is forgotten.');
    }

    public function testChangingTheAppTypeOfALockedAppIsAnError(): void
    {
        $lock = $this->lock();
        $lock->record('acme-co', 'support', 17, 'llmor/silicon');

        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/cannot be changed/');

        $this->resolve($this->app(), $lock, [['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/silicon']]);
    }

    public function testAppTypeDriftIsAlsoCheckedAgainstTheRemoteRecord(): void
    {
        // The lock file is hand-editable, so the remote record is the real authority.
        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/is a llmor\/silicon app/');

        $this->resolve($this->app(id: 17), $this->lock(), [
            ['id' => 17, 'name' => 'Support Bot', 'app_key' => 'llmor/silicon'],
        ]);
    }

    public function testAPinToAMissingAppIsAnError(): void
    {
        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->resolve($this->app(id: 404), $this->lock(), []);
    }

    public function testAnUnknownAppIsMarkedForCreation(): void
    {
        $resolved = $this->resolve($this->app(), $this->lock(), []);

        self::assertNull($resolved->id);
        self::assertSame(ResolvedApp::ORIGIN_NEW, $resolved->origin);
    }

    /**
     * @param list<array<string, mixed>> $remote
     */
    private function resolve(AppDefinition $app, AppLockFile $lock, array $remote): ResolvedApp
    {
        return (new AppResolver('acme-co', $lock, new RemoteAppIndex($remote)))->resolve($app);
    }

    private function app(
        string $declaration = 'support',
        ?string $name = 'Support Bot',
        ?int $id = null,
        string $appType = 'llmor/generic',
    ): AppDefinition {
        return new AppDefinition(declaration: $declaration, appType: $appType, name: $name, id: $id);
    }

    private function lock(): AppLockFile
    {
        return new AppLockFile($this->projectPath('llmor.lock'));
    }
}
