<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Model\ListCommand;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use Llmor\Cli\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ListCommand::class)]
final class ModelsListCommandTest extends TestCase
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

    public function testListsModelsAndMarksTheDefault(): void
    {
        $tester = $this->tester($this->api());
        $exit = $tester->execute([], ['decorated' => false]);
        $display = $tester->getDisplay();

        self::assertSame(0, $exit, $display);
        self::assertStringContainsString('GPT-4o', $display);
        self::assertStringContainsString('0.002 CHF', $display);
        self::assertStringContainsString('1,200,000', $display);
        self::assertStringContainsString('gpt-4o-2024-08-06', $display);
        self::assertStringContainsString('●', $display);
        self::assertStringContainsString('2 model(s) · type chat_completion', $display);
        self::assertStringContainsString("[model] = 'GPT-4o'", $display);
    }

    public function testOmitsTheDefaultMarkerWhenEveryModelClaimsIt(): void
    {
        // Older catalogues have `default` set on every row — the server only enforces
        // one-per-type on write — so a column of markers would say nothing at all.
        $api = $this->api([
            ['id' => 2, 'name' => 'GPT-4', 'type' => 'chat_completion', 'default' => true],
            ['id' => 3, 'name' => 'GPT-4 Fixer', 'type' => 'chat_completion', 'default' => true],
        ]);

        $tester = $this->tester($api);
        $tester->execute([], ['decorated' => false]);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString('●', $display);
        self::assertStringContainsString("[model] = 'GPT-4'", $display);
    }

    public function testHidesTokensAndUpstreamWhenTheyCarryNothing(): void
    {
        $api = $this->api([
            ['id' => 2, 'name' => 'GPT-4', 'type' => 'chat_completion', 'prepaid' => false, 'available_tokens' => 0],
        ]);

        $tester = $this->tester($api);
        $tester->execute([], ['decorated' => false]);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString('tokens', $display);
        self::assertStringNotContainsString('upstream', $display);
    }

    public function testMarksExpiredModels(): void
    {
        $api = $this->api([
            ['id' => 9, 'name' => 'Old Model', 'type' => 'chat_completion', 'expires_at' => '2020-01-01T00:00:00+00:00'],
        ]);

        $tester = $this->tester($api);
        $tester->execute([], ['decorated' => false]);

        self::assertStringContainsString('(expired)', $tester->getDisplay());
    }

    public function testFiltersByTypeAndPassesTheSearchThrough(): void
    {
        $api = $this->api();
        $this->tester($api)->execute(['--search' => 'gpt']);

        $call = $api->findCall('GET', '#/v1/vendors/42/models$#');
        self::assertNotNull($call);
        self::assertStringContainsString('type=chat_completion', $call['query']);
        self::assertStringContainsString('search=gpt', $call['query']);
        self::assertStringContainsString('order=name', $call['query']);
    }

    public function testTypeAllSendsNoTypeFilterAndShowsTheColumn(): void
    {
        $api = $this->api([
            ['id' => 11, 'name' => 'text-embed-3', 'type' => 'embedding'],
        ]);

        $tester = $this->tester($api);
        $tester->execute(['--type' => 'all'], ['decorated' => false]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('embedding', $display);
        self::assertStringContainsString('type any', $display);

        $call = $api->findCall('GET', '#/v1/vendors/42/models$#');
        self::assertNotNull($call);
        self::assertStringNotContainsString('type=', $call['query']);
    }

    public function testReportsAnEmptyCatalogue(): void
    {
        $api = $this->api([]);

        $tester = $this->tester($api);
        $exit = $tester->execute([], ['decorated' => false]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('no chat_completion models yet', $tester->getDisplay());
    }

    public function testJsonOutput(): void
    {
        $tester = $this->tester($this->api());
        $tester->execute(['--json' => true]);

        /** @var array{data: list<array<string, mixed>>} $decoded */
        $decoded = \json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $decoded['data']);
        self::assertSame('GPT-4o', $decoded['data'][0]['name']);
    }

    public function testFailsWithoutAConfiguredVendor(): void
    {
        $api = $this->api();
        $client = TestClient::forApi($api, $this->projectDir, '');
        $tester = new CommandTester(new ListCommand($client, ''));

        $exit = $tester->execute([], ['decorated' => false]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No vendor configured', $tester->getDisplay());
    }

    /**
     * The vendor lookup plus a models catalogue — every test here needs both, and the
     * vendor key has to be the one {@see TestClient::VENDOR_KEY} configures or the
     * command reports no vendor at all.
     *
     * @param ?list<array<string, mixed>> $models rows to serve, or null for the default pair
     */
    private function api(?array $models = null): FakeLlmorApi
    {
        $models ??= [
            [
                'id' => 4,
                'name' => 'GPT-4o',
                'type' => 'chat_completion',
                'default' => true,
                'cost_per_1k_micros' => 2000,
                'cost_per_1k_formatted' => '0.002 CHF',
                'prepaid' => true,
                'available_tokens' => 1200000,
                'parameters' => ['model' => 'gpt-4o-2024-08-06'],
            ],
            [
                'id' => 9,
                'name' => 'Claude Sonnet',
                'type' => 'chat_completion',
                'default' => false,
                'cost_per_1k_micros' => 4000,
                'cost_per_1k_formatted' => '0.004 CHF',
                'available_tokens' => 840000,
            ],
        ];

        return (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => TestClient::VENDOR_KEY]]]])
            ->on('GET', '#/v1/vendors/\d+/models$#', static fn (): array => [200, ['data' => $models]]);
    }

    private function tester(FakeLlmorApi $api): CommandTester
    {
        $client = TestClient::forApi($api, $this->projectDir);

        return new CommandTester(new ListCommand($client, TestClient::VENDOR_KEY));
    }
}
