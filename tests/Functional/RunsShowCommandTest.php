<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Run\RunsShowCommand;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Services;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RunsShowCommand::class)]
final class RunsShowCommandTest extends TestCase
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

    public function testShowsSuccessfulRun(): void
    {
        $api = $this->resolved()
            ->on('GET', '#/functions/\d+/runs/\d+$#', static fn (): array => [200, ['data' => [
                'id' => 99,
                'status' => 1,
                'took' => 12,
                'memory' => 2048,
                'result' => 'ok',
                'console' => ['hello from lua'],
                'error' => ['message' => '', 'line' => null],
            ]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs', 'runId' => '99']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exit, $display);
        self::assertStringContainsString('success', $display);
        self::assertStringContainsString('hello from lua', $display);

        $call = $this->findCall($api, 'GET', '#/v1/vendors/42/functions/7/runs/99$#');
        self::assertNotNull($call, 'The run is fetched under the resolved vendor and function ids.');
    }

    public function testFailedRunExitsNonZeroAndShowsError(): void
    {
        $api = $this->resolved()
            ->on('GET', '#/functions/\d+/runs/\d+$#', static fn (): array => [200, ['data' => [
                'id' => 100,
                'status' => -5,
                'took' => 3,
                'memory' => 1024,
                'result' => null,
                'console' => [],
                'error' => ['message' => 'boom', 'line' => 2],
            ]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs', 'runId' => '100']);

        self::assertSame(1, $exit, 'A non-success run status must exit non-zero.');
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    private function resolved(): FakeLlmorApi
    {
        return (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => [['id' => 7, 'function_key' => 'pjas_silicon_docs']]]]);
    }

    private function tester(FakeLlmorApi $api): CommandTester
    {
        $config = new Configuration('https://api.test', 'admin@test.llmor', 'pw', 'acme-co', $this->projectDir);
        $services = new Services($config, $api->client());

        return new CommandTester(new RunsShowCommand($services->client, 'acme-co', $this->projectDir));
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
