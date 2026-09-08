<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Sync\ModelResolver;
use Llmor\Cli\Sync\SyncException;
use Llmor\Cli\Tests\Support\FakeLlmorApi;
use Llmor\Cli\Tests\Support\TempProject;
use Llmor\Cli\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelResolver::class)]
final class ModelResolverTest extends TestCase
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

    public function testResolvesAModelNameToItsId(): void
    {
        $resolver = $this->resolver([
            ['id' => 3, 'name' => 'claude-sonnet-5', 'type' => 'chat_completion'],
            ['id' => 4, 'name' => 'gpt-4o', 'type' => 'chat_completion'],
        ]);

        self::assertSame(4, $resolver->resolveId('gpt-4o'));
        self::assertSame(3, $resolver->resolveId('claude-sonnet-5'));
    }

    public function testMatchesCaseInsensitivelyAsAFallback(): void
    {
        $resolver = $this->resolver([['id' => 4, 'name' => 'GPT-4o', 'type' => 'chat_completion']]);

        self::assertSame(4, $resolver->resolveId('gpt-4o'));
    }

    public function testPrefersAnExactMatchOverACaseInsensitiveOne(): void
    {
        $resolver = $this->resolver([
            ['id' => 4, 'name' => 'GPT-4o', 'type' => 'chat_completion'],
            ['id' => 5, 'name' => 'gpt-4o', 'type' => 'chat_completion'],
        ]);

        self::assertSame(5, $resolver->resolveId('gpt-4o'));
    }

    public function testAmbiguousNameIsAnError(): void
    {
        // Model names are not unique server-side, so guessing would be worse.
        $resolver = $this->resolver([
            ['id' => 4, 'name' => 'gpt-4o', 'type' => 'chat_completion'],
            ['id' => 5, 'name' => 'gpt-4o', 'type' => 'chat_completion'],
        ]);

        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/matches 2 models/');

        $resolver->resolveId('gpt-4o');
    }

    public function testUnknownModelListsWhatIsAvailable(): void
    {
        $resolver = $this->resolver([['id' => 4, 'name' => 'gpt-4o', 'type' => 'chat_completion']]);

        $this->expectException(SyncException::class);
        $this->expectExceptionMessageMatches('/Available: "gpt-4o"/');

        $resolver->resolveId('gpt-5');
    }

    public function testTheModelListIsFetchedOnlyOnce(): void
    {
        $api = $this->api([
            ['id' => 4, 'name' => 'gpt-4o', 'type' => 'chat_completion'],
            ['id' => 5, 'name' => 'other', 'type' => 'chat_completion'],
        ]);
        $resolver = new ModelResolver($this->client($api), 42);

        $resolver->resolveId('gpt-4o');
        $resolver->resolveId('other');

        $listings = \array_filter($api->calls, static fn (array $call): bool => \str_contains($call['path'], '/models'));
        self::assertCount(1, $listings, 'One listing serves the whole run.');
    }

    /**
     * @param list<array<string, mixed>> $models
     */
    private function resolver(array $models): ModelResolver
    {
        return new ModelResolver($this->client($this->api($models)), 42);
    }

    /**
     * @param list<array<string, mixed>> $models
     */
    private function api(array $models): FakeLlmorApi
    {
        return (new FakeLlmorApi())->on('GET', '#/models#', static fn (): array => [200, ['data' => $models]]);
    }

    private function client(FakeLlmorApi $api): LlmorClient
    {
        return TestClient::forApi($api, $this->projectDir);
    }
}
