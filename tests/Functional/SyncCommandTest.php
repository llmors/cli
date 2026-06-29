<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Functional;

use Llmor\Cli\Command\Sync\SyncCommand;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Services;
use Llmor\Cli\Sync\FunctionSynchronizer;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SyncCommand::class)]
#[CoversClass(FunctionSynchronizer::class)]
final class SyncCommandTest extends TestCase
{
    use TempProject;

    private const LUA = "return success('docs')\n";
    private const NOTES = "# PJAS Silicon Docs\n\nHello.\n";

    protected function setUp(): void
    {
        $this->makeProject();
        $this->writeProjectFile('llmor.scsc', $this->manifest());
        $this->writeProjectFile('main/main.lua', self::LUA);
        $this->writeProjectFile('main/notes.md', self::NOTES);
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function testCreatesFunctionAndSyncsFiles(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static fn (): array => [200, ['data' => ['id' => 7]]])
            ->on('GET', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => ['id' => 1]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('created', $tester->getDisplay());

        $create = $this->findCall($api, 'POST', '#/v1/vendors/42/functions$#');
        self::assertNotNull($create);
        self::assertSame('pjas_silicon_docs', $create['body']['function_key']);
        self::assertSame('silicon', $create['body']['runtime']);
        self::assertSame(self::LUA, $create['body']['code'], 'The entry file becomes the function code.');

        $file = $this->findCall($api, 'POST', '#/functions/7/files$#');
        self::assertNotNull($file);
        self::assertSame('notes.md', $file['body']['path']);
        self::assertSame(self::NOTES, $file['body']['content']);
    }

    public function testCopiesLocalFileIntoFunction(): void
    {
        $readme = "# Project Readme\n";
        $this->writeProjectFile('README.md', $readme);
        $this->writeProjectFile('llmor.scsc', $this->copyManifest());

        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static fn (): array => [200, ['data' => ['id' => 7]]])
            ->on('GET', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => ['id' => 1]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute([]);
        self::assertSame(0, $exit, $tester->getDisplay());

        $copied = null;
        foreach ($api->calls as $call) {
            if ('POST' === $call['method'] && 1 === \preg_match('#/functions/7/files$#', $call['path']) && 'docs/README.md' === ($call['body']['path'] ?? null)) {
                $copied = $call;
            }
        }
        self::assertNotNull($copied, 'Expected the README to be copied to docs/README.md.');
        self::assertSame($readme, $copied['body']['content']);
    }

    public function testUnchangedFunctionMakesNoWrites(): void
    {
        $existing = [
            'id' => 7,
            'function_key' => 'pjas_silicon_docs',
            'name' => 'PJAS Silicon Docs',
            'description' => 'A collection of documentation for PJAS Silicon.',
            'runtime' => 'silicon',
            'code' => self::LUA,
        ];
        $remoteFile = ['id' => 1, 'path' => 'notes.md', 'content_hash' => \hash('sha256', self::NOTES)];

        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => [$existing]]])
            ->on('GET', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => [$remoteFile]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('unchanged', $tester->getDisplay());

        foreach ($api->calls as $call) {
            $isWrite = \in_array($call['method'], ['POST', 'PUT', 'DELETE'], true);
            $touchesFunctions = \str_contains($call['path'], '/functions');
            self::assertFalse($isWrite && $touchesFunctions, \sprintf(
                'No function/file writes expected for an unchanged function, saw %s %s.',
                $call['method'],
                $call['path'],
            ));
        }
    }

    public function testCollectsValidationErrorsAndKeepsGoing(): void
    {
        $this->writeProjectFile('llmor.scsc', $this->twoFunctionManifest());

        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static function (string $m, string $p, array $body): array {
                if ('bad_fn' === ($body['function_key'] ?? null)) {
                    return [400, [
                        'message' => 'The given data is invalid.',
                        'errors' => [
                            'runtime' => ['in' => '"silicon" rejected'],
                            'isLibrary' => ['boolType' => '"0" must be of type boolean'],
                            'is_library' => ['boolType' => '"0" must be of type boolean'],
                            'name' => [],
                        ],
                    ]];
                }

                return [200, ['data' => ['id' => 8]]];
            })
            ->on('GET', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions/\d+/files$#', static fn (): array => [200, ['data' => ['id' => 1]]]);

        $tester = $this->tester($api);
        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(1, $exit, $display);
        // Friendly: manifest-mapped labels + hint, and the camelCase duplicate is gone.
        self::assertStringContainsString('[runtime]', $display);
        self::assertStringContainsString("must be 'silicon' or 'graph'", $display);
        self::assertStringContainsString('library flag', $display);
        self::assertStringNotContainsString('isLibrary', $display);
        // Aggregation: the second (valid) function still synced despite the first failing.
        self::assertNotNull($this->findCall($api, 'POST', '#/functions/8/files$#'));
        self::assertStringContainsString('1 failed', $display);
    }

    public function testJsonOutputIncludesStructuredErrors(): void
    {
        $api = (new FakeLlmorApi())
            ->on('GET', '#/v1/vendors$#', static fn (): array => [200, ['data' => [['id' => 42, 'key' => 'acme-co']]]])
            ->on('GET', '#/functions$#', static fn (): array => [200, ['data' => []]])
            ->on('POST', '#/functions$#', static fn (): array => [400, [
                'message' => 'The given data is invalid.',
                'errors' => ['runtime' => ['in' => 'bad']],
            ]]);

        $tester = $this->tester($api);
        $exit = $tester->execute(['--json' => true]);

        self::assertSame(1, $exit);
        $payload = \json_decode(\trim($tester->getDisplay()), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['ok']);
        self::assertSame(1, $payload['summary']['failed']);
        self::assertSame('pjas_silicon_docs', $payload['errors'][0]['function_key']);
        self::assertSame('validation', $payload['errors'][0]['category']);
        self::assertArrayHasKey('runtime', $payload['errors'][0]['fields']);
    }

    private function tester(FakeLlmorApi $api): CommandTester
    {
        $config = new Configuration('https://api.test', 'admin@test.llmor', 'pw', 'acme-co', $this->projectDir);
        $services = new Services($config, $api->client());

        return new CommandTester(new SyncCommand($services->client, 'acme-co', $this->projectDir));
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
              [description] = 'A collection of documentation for PJAS Silicon.'
              [runtime]     = 'silicon'
              [srcdir]      = './main'
              [entry]       = 'main.lua'
            }
            SCSC;
    }

    private function copyManifest(): string
    {
        return <<<SCSC
            pjas_silicon_docs: Function {
              [name]        = 'PJAS Silicon Docs'
              [description] = 'A collection of documentation for PJAS Silicon.'
              [runtime]     = 'silicon'
              [srcdir]      = './main'
              [entry]       = 'main.lua'
              @path('docs/')
              [copy] = {
                './README.md',
              }
            }
            SCSC;
    }

    private function twoFunctionManifest(): string
    {
        return <<<SCSC
            bad_fn: Function {
              [name]        = 'Bad Function'
              [description] = 'demo'
              [runtime]     = 'silicon'
              [srcdir]      = './main'
              [entry]       = 'main.lua'
            }

            good_fn: Function {
              [name]        = 'Good Function'
              [description] = 'demo'
              [runtime]     = 'silicon'
              [srcdir]      = './main'
              [entry]       = 'main.lua'
            }
            SCSC;
    }
}
