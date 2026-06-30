<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Run\RunsListCommand;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Services;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RunsListCommand::class)]
final class RunsListCommandTest extends TestCase
{
    use TempProject;

    protected function setUp(): void
    {
        $this->makeProject();
        $this->writeProjectFile('llmor.scsc', $this->manifest());
        $this->writeProjectFile('main/main.lua', "return success(1)\n");
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function testListsRunsWithMappedStatusLabels(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => [['id' => 7, 'function_key' => 'pjas_silicon_docs']]]])
            ->on('GET', '#/functions/\d+/runs$#', static fn (): array => [200, [
                'data' => [
                    ['id' => 99, 'status' => 1, 'took' => 12, 'memory' => 2048, 'created_at' => '2026-06-30T10:00:00Z'],
                    ['id' => 98, 'status' => -5, 'took' => 3, 'memory' => 1024, 'created_at' => '2026-06-29T09:00:00Z'],
                ],
                'meta' => ['page' => 1, 'page_size' => 25, 'total_count' => 2],
            ]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exit, $display);
        self::assertStringContainsString('99', $display);
        self::assertStringContainsString('success', $display);
        self::assertStringContainsString('explicit failure', $display);
        self::assertStringContainsString('2 total', $display);

        $runs = $this->findCall($api, 'GET', '#/v1/vendors/42/functions/7/runs$#');
        self::assertNotNull($runs, 'The runs list must be fetched under the resolved vendor and function ids.');
    }

    public function testReportsNoRuns(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => [['id' => 7, 'function_key' => 'pjas_silicon_docs']]]])
            ->on('GET', '#/functions/\d+/runs$#', static fn (): array => [200, ['data' => []]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No runs found', $tester->getDisplay());
    }

    public function testErrorsWhenFunctionNotSyncedAndSkipsRunsCall(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('has not been synced yet', $tester->getDisplay());
        self::assertNull(
            $this->findCall($api, 'GET', '#/functions/\d+/runs$#'),
            'An unsynced function must not hit the runs endpoint.',
        );
    }

    private function tester(FakeLlmorApi $api): CommandTester
    {
        $config = new Configuration('https://api.test', 'admin@test.llmor', 'pw', 'acme-co', $this->projectDir);
        $services = new Services($config, $api->client());

        return new CommandTester(new RunsListCommand($services->client, 'acme-co', $this->projectDir));
    }

    /**
     * @return array{method: string, path: string, body: array<string, mixed>}|null
     */
    private function findCall(FakeLlmorApi $api, string $method, string $pattern): ?array
    {
        foreach ($api->calls as $call) {
            if ($call['method'] === $method && 1 === \preg_match($pattern, $call['path'])) {
                return $call;
            }
        }

        return null;
    }

    private function manifest(): string
    {
        return <<<SCSC
            pjas_silicon_docs: Function {
              [name]        = 'PJAS Silicon Docs'
              [description] = 'Docs.'
              [runtime]     = 'silicon'
              [srcdir]      = './main'
              [entry]       = 'main.lua'
            }
            SCSC;
    }
}
