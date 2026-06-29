<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * The parsed result of an `llmor.scsc` manifest: the function declarations it
 * contains, in source order, plus the absolute path the manifest was read from.
 */
final class FunctionManifest
{
    /**
     * @param list<FunctionDefinition> $functions
     */
    public function __construct(
        public readonly string $path,
        public readonly array $functions,
    ) {
    }

    public function get(string $functionKey): ?FunctionDefinition
    {
        foreach ($this->functions as $function) {
            if ($function->functionKey === $functionKey) {
                return $function;
            }
        }

        return null;
    }
}
