<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * The parsed result of an `llmor.scsc` manifest: the declarations it contains, in
 * source order, plus the absolute path the manifest was read from.
 */
final class Manifest
{
    /**
     * @param list<FunctionDefinition> $functions
     * @param list<AppDefinition>      $apps
     */
    public function __construct(
        public readonly string $path,
        public readonly array $functions,
        public readonly array $apps = [],
    ) {
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
