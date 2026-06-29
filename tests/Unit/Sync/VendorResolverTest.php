<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Services;
use Llmor\Cli\Sync\SyncException;
use Llmor\Cli\Sync\VendorResolver;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VendorResolver::class)]
final class VendorResolverTest extends TestCase
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

    public function testResolvesNumericIdFromVendorKey(): void
    {
        $api = (new FakeLlmorApi())->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [
            ['id' => 7, 'key' => 'other-co'],
            ['id' => 42, 'key' => 'acme-co'],
        ]]]);

        self::assertSame(42, (new VendorResolver($this->client($api)))->resolveId('acme-co'));
    }

    public function testThrowsWhenVendorNotFound(): void
    {
        $api = (new FakeLlmorApi())->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [
            ['id' => 7, 'key' => 'other-co'],
        ]]]);

        $this->expectException(SyncException::class);
        (new VendorResolver($this->client($api)))->resolveId('acme-co');
    }

    public function testThrowsWhenNoVendorConfigured(): void
    {
        $this->expectException(SyncException::class);
        (new VendorResolver($this->client(new FakeLlmorApi())))->resolveId(null);
    }

    private function client(FakeLlmorApi $api): LlmorClient
    {
        $config = new Configuration('https://api.test', 'admin@test.llmor', 'pw', 'acme-co', $this->projectDir);

        return (new Services($config, $api->client()))->client;
    }
}
