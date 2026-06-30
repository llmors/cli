<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Run\RunCommand;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Services;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RunCommand::class)]
final class RunCommandTest extends TestCase
{
    use TempProject;

    private const LUA = "return success(args.q)\n";

    protected function setUp(): void
    {
        $this->makeProject();
        $this->writeProjectFile('llmor.scsc', $this->manifest());
        $this->writeProjectFile('main/main.lua', self::LUA);
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function testSyncsThenRunsAndPrintsResult(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static fn (): array => [200, ['data' => ['id' => 7]]])
            ->on('GET', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions/\d+/build$#', static fn (): array => [200, ['data' => [
                'id' => 99,
                'status' => 1,
                'took' => 12,
                'memory' => 2048,
                'result' => 'test',
                'console' => ['hello from lua'],
                'error' => ['message' => '', 'line' => null],
            ]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs', '--arg' => ['q=test']]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('success', $tester->getDisplay());
        self::assertStringContainsString('hello from lua', $tester->getDisplay());

        $build = $this->findCall($api, 'POST', '#/functions/7/build$#');
        self::assertNotNull($build);
        self::assertSame(self::LUA, $build['body']['code']);
        self::assertSame(['q' => 'test'], $build['body']['arguments'], '--arg pairs land in the arguments payload.');
    }

    public function testRendersConsoleTriplesWithoutRawArrays(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static fn (): array => [200, ['data' => ['id' => 7]]])
            ->on('GET', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions/\d+/build$#', static fn (): array => [200, ['data' => [
                'id' => 99,
                'status' => 1,
                'took' => 12,
                'memory' => 2048,
                'result' => 'ok',
                'console' => [[0, "first line\nsecond line", 1782769822]],
                'error' => ['message' => '', 'line' => null],
            ]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exit, $display);
        // The message is printed verbatim with a real line break, not a JSON array.
        self::assertStringContainsString('first line', $display);
        self::assertStringContainsString('second line', $display);
        self::assertStringNotContainsString('[0,', $display, 'Console triples must not render as raw JSON arrays.');
        self::assertStringContainsString(\date('H:i:s', 1782769822), $display, 'The timestamp gutter is shown.');
    }

    public function testRendersStringResultRawNotJsonQuoted(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static fn (): array => [200, ['data' => ['id' => 7]]])
            ->on('GET', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions/\d+/build$#', static fn (): array => [200, ['data' => [
                'id' => 99,
                'status' => 1,
                'took' => 12,
                'memory' => 2048,
                'result' => "first line\nZürich",
                'console' => [],
                'error' => ['message' => '', 'line' => null],
            ]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs']);
        $display = $tester->getDisplay();

        self::assertSame(0, $exit, $display);
        // Real line break and raw unicode — not JSON-quoted/escaped.
        self::assertStringContainsString("first line\nZürich", $display);
        self::assertStringNotContainsString('\nZürich', $display, 'Newlines must not be escaped as \\n.');
        self::assertStringNotContainsString('\\u00fc', $display, 'Unicode must not be escaped as \\uXXXX.');
        self::assertStringNotContainsString('"first line', $display, 'A string result must not be wrapped in JSON quotes.');
    }

    public function testNoSyncRunsExistingAndReportsFailure(): void
    {
        $existing = ['id' => 7, 'function_key' => 'pjas_silicon_docs'];

        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => [$existing]]])
            ->on('POST', '#/functions/\d+/build$#', static fn (): array => [200, ['data' => [
                'id' => 100,
                'status' => -5,
                'took' => 3,
                'memory' => 1024,
                'result' => null,
                'console' => [],
                'error' => ['message' => 'boom', 'line' => 2],
            ]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs', '--no-sync' => true]);

        self::assertSame(1, $exit, 'A non-success run status must exit non-zero.');
        self::assertStringContainsString('boom', $tester->getDisplay());

        self::assertNull(
            $this->findCall($api, 'POST', '#/v1/vendors/42/functions$#'),
            '--no-sync must not create or update the function.',
        );
    }

    public function testReportsValidationErrorDuringSyncAndSkipsBuild(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static fn (): array => [400, [
                'message' => 'The given data is invalid.',
                'errors' => ['runtime' => ['in' => 'bad']],
            ]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['name' => 'pjas_silicon_docs']);
        $display = $tester->getDisplay();

        self::assertSame(1, $exit);
        self::assertStringContainsString('[runtime]', $display);
        self::assertStringContainsString("must be 'silicon' or 'graph'", $display);
        self::assertNull(
            $this->findCall($api, 'POST', '#/build$#'),
            'A failed sync must not proceed to the build/run step.',
        );
    }

    public function testWithoutArgumentListsAvailableFunctions(): void
    {
        $api = new FakeLlmorApi();
        $tester = $this->tester($api);

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(0, $exit, $display);
        self::assertStringContainsString('run one with', $display);
        self::assertStringContainsString('pjas_silicon_docs', $display);
        self::assertSame([], $api->calls, 'Listing functions must not hit the API.');
    }

    private function tester(FakeLlmorApi $api): CommandTester
    {
        $config = new Configuration('https://api.test', 'admin@test.llmor', 'pw', 'acme-co', $this->projectDir);
        $services = new Services($config, $api->client());

        return new CommandTester(new RunCommand($services->client, 'acme-co', $this->projectDir));
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
