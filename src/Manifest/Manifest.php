<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * The parsed result of an `llmor.scsc` manifest: the declarations it contains, in
 * source order, the project's own settings, plus the absolute path it was read from.
 */
final class Manifest
{
    /** The project's settings — the declared `: Config` block, or the defaults. */
    public readonly ConfigDefinition $config;

    /**
     * @param list<FunctionDefinition> $functions
     * @param list<AppDefinition>      $apps
     * @param ?ConfigDefinition        $config    null when the manifest declares no
     *                                            `: Config` block, which is the common
     *                                            case — the defaults stand in, so
     *                                            callers never branch on it
     */
    public function __construct(
        public readonly string $path,
        public readonly array $functions,
        public readonly array $apps = [],
        ?ConfigDefinition $config = null,
    ) {
        $this->config = $config ?? new ConfigDefinition();
    }

    public function getFunction(string $functionKey): ?FunctionDefinition
    {
        foreach ($this->functions as $function) {
            if ($function->functionKey === $functionKey) {
                return $function;
            }
        }

        return null;
    }

    public function getApp(string $declaration): ?AppDefinition
    {
        foreach ($this->apps as $app) {
            if ($app->declaration === $declaration) {
                return $app;
            }
        }

        return null;
    }

    /** The directory relative manifest paths resolve against. */
    public function directory(): string
    {
        return \dirname($this->path);
    }
}
