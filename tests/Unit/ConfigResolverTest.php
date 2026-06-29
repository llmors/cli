<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit;

use Llmor\Cli\Config\ConfigResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigResolver::class)]
final class ConfigResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir().'/llmor-test-'.\uniqid('', true);
        \mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->root);
    }

    public function testPrefersProjectLocalDirectoryWalkingUp(): void
    {
        $project = $this->root.'/project';
        $nested = $project.'/src/deep';
        \mkdir($nested, 0o700, true);
        \mkdir($project.'/.llmor', 0o700, true);
        \file_put_contents($project.'/.llmor/.env', "LLMOR_HOST=https://example.test\nLLMOR_IDENTIFIER=a@b.c\nLLMOR_SECRET=pw\nLLMOR_VENDOR=acme-co\n");

        $resolver = new ConfigResolver($nested, $this->root.'/home');
        $config = $resolver->load();

        self::assertSame($project.'/.llmor', $config->directory);
        self::assertSame('https://example.test', $config->host);
        self::assertSame('a@b.c', $config->identifier);
        self::assertSame('acme-co', $config->vendor);
        self::assertTrue($config->hasCredentials());
    }

    public function testFallsBackToHomeDirectory(): void
    {
        $home = $this->root.'/home';
        \mkdir($home.'/.llmor', 0o700, true);
        \file_put_contents($home.'/.llmor/.env', "LLMOR_SECRET=pw\nLLMOR_IDENTIFIER=h@h.h\n");

        $resolver = new ConfigResolver($this->root.'/elsewhere', $home);
        $config = $resolver->load();

        self::assertSame($home.'/.llmor', $config->directory);
        self::assertSame(ConfigResolver::DEFAULT_HOST, $config->host, 'Host defaults when not configured.');
        self::assertSame('h@h.h', $config->identifier);
    }

    public function testProjectAndHomeValuesAreMergedPerKey(): void
    {
        // Credentials live globally in ~/.llmor; the project only overrides the vendor.
        $home = $this->root.'/home';
        \mkdir($home.'/.llmor', 0o700, true);
        \file_put_contents($home.'/.llmor/.env', "LLMOR_HOST=https://global.test\nLLMOR_IDENTIFIER=me@home\nLLMOR_SECRET=homepw\nLLMOR_VENDOR=home-vendor\n");

        $project = $this->root.'/project';
        \mkdir($project.'/.llmor', 0o700, true);
        // Empty LLMOR_SECRET must NOT shadow the home value; vendor overrides.
        \file_put_contents($project.'/.llmor/.env', "LLMOR_VENDOR=project-vendor\nLLMOR_SECRET=\n");

        $resolver = new ConfigResolver($project, $home);
        $config = $resolver->load();

        self::assertSame('https://global.test', $config->host, 'Host falls back to home.');
        self::assertSame('me@home', $config->identifier, 'Identifier falls back to home.');
        self::assertSame('homepw', $config->secret, 'Empty project secret falls back to home.');
        self::assertSame('project-vendor', $config->vendor, 'Vendor is overridden by the project.');
        self::assertSame($project.'/.llmor', $config->directory, 'Session is stored in the project directory.');
    }

    public function testEnvironmentVariablesOverrideFileValues(): void
    {
        $home = $this->root.'/home';
        \mkdir($home.'/.llmor', 0o700, true);
        \file_put_contents($home.'/.llmor/.env', "LLMOR_HOST=https://file.test\nLLMOR_IDENTIFIER=file@x.y\nLLMOR_SECRET=filepw\n");

        $resolver = new ConfigResolver($this->root.'/elsewhere', $home, [
            'LLMOR_HOST' => 'https://override.test',
        ]);
        $config = $resolver->load();

        self::assertSame('https://override.test', $config->host);
        self::assertSame('file@x.y', $config->identifier, 'Unset env keys keep file values.');
    }

    public function testMissingConfigYieldsDefaultsWithoutCredentials(): void
    {
        $resolver = new ConfigResolver($this->root.'/nowhere', $this->root.'/no-home');
        $config = $resolver->load();

        self::assertSame(ConfigResolver::DEFAULT_HOST, $config->host);
        self::assertFalse($config->hasCredentials());
    }

    private function removeRecursively(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $items = \scandir($path) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $full = $path.'/'.$item;
            \is_dir($full) ? $this->removeRecursively($full) : @\unlink($full);
        }
        @\rmdir($path);
    }
}
