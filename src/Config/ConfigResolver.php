<?php

declare(strict_types=1);

namespace Llmor\Cli\Config;

/**
 * Resolves the effective {@see Configuration} for a CLI run.
 *
 * Discovery order for the `.llmor` directory:
 *   1. Walk up from the current working directory looking for a `.llmor/` dir.
 *   2. Otherwise fall back to `~/.llmor/`.
 *
 * Values are then layered: defaults < `.llmor/.env` file < real `LLMOR_*`
 * environment variables (the latter win, so CI / ad-hoc overrides are easy).
 */
final class ConfigResolver
{
    public const DIR_NAME = '.llmor';
    public const DEFAULT_HOST = 'https://llmor.com';

    private const ENV_KEYS = ['LLMOR_HOST', 'LLMOR_IDENTIFIER', 'LLMOR_SECRET', 'LLMOR_VENDOR'];

    /**
     * @param array<string, string> $env captured `LLMOR_*` process environment
     */
    public function __construct(
        private readonly string $cwd,
        private readonly string $home,
        private readonly array $env = [],
    ) {
    }

    public static function fromEnvironment(): self
    {
        $cwd = \getcwd();
        if (false === $cwd) {
            $cwd = '.';
        }

        $env = [];
        foreach (self::ENV_KEYS as $key) {
            $value = \getenv($key);
            if (false !== $value) {
                $env[$key] = $value;
            }
        }

        return new self($cwd, self::detectHome(), $env);
    }

    /**
     * Locate the nearest project-local `.llmor` directory by walking up from the
     * current working directory. Returns null when none exists (the home
     * directory is handled separately as a fallback layer).
     */
    public function locateProject(): ?string
    {
        $dir = $this->cwd;
        while (true) {
            $candidate = $dir.\DIRECTORY_SEPARATOR.self::DIR_NAME;
            if (\is_dir($candidate)) {
                return $candidate;
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * The project-local `.llmor` directory (created by `auth:login` without `--global`).
     */
    public function projectDirectory(): string
    {
        return $this->cwd.\DIRECTORY_SEPARATOR.self::DIR_NAME;
    }

    /**
     * The user-global `~/.llmor` directory.
     */
    public function homeDirectory(): string
    {
        return $this->home.\DIRECTORY_SEPARATOR.self::DIR_NAME;
    }

    public function load(): Configuration
    {
        $projectDir = $this->locateProject();
        $homeDir = $this->homeDirectory();

        // Layer values per key: home `.llmor/.env` < project `.llmor/.env` <
        // real LLMOR_* env vars. This lets, e.g., credentials live globally in
        // ~/.llmor while a project overrides only the vendor. Empty values are
        // treated as "missing" so they fall back instead of shadowing a lower layer.
        $keep = static fn (string $v): bool => '' !== $v;
        $values = \array_merge(
            \array_filter(EnvFile::parse($homeDir.\DIRECTORY_SEPARATOR.'.env'), $keep),
            null !== $projectDir ? \array_filter(EnvFile::parse($projectDir.\DIRECTORY_SEPARATOR.'.env'), $keep) : [],
            \array_filter($this->env, $keep),
        );

        $host = $values['LLMOR_HOST'] ?? self::DEFAULT_HOST;

        return new Configuration(
            host: \rtrim($host, '/'),
            identifier: $values['LLMOR_IDENTIFIER'] ?? null,
            secret: $values['LLMOR_SECRET'] ?? null,
            vendor: $values['LLMOR_VENDOR'] ?? null,
            // The session lives in the most-specific directory: the project's
            // `.llmor` when present, otherwise the user's `~/.llmor`.
            directory: $projectDir ?? $homeDir,
        );
    }

    private static function detectHome(): string
    {
        $home = \getenv('HOME');
        if (\is_string($home) && '' !== $home) {
            return $home;
        }

        $profile = \getenv('USERPROFILE');
        if (\is_string($profile) && '' !== $profile) {
            return $profile;
        }

        return \sys_get_temp_dir();
    }
}
