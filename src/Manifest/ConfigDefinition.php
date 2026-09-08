<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * The project's own settings, from the optional `: Config` declaration.
 *
 * Unlike a `: Function` or `: App` declaration this describes nothing on the server —
 * it is how a manifest states preferences about the CLI's *local* behaviour, so a
 * project that keeps its prompts somewhere other than `./prompts` doesn't have to move
 * every file `apps:import` writes.
 *
 * Every manifest has one of these ({@see Manifest::$config} substitutes the defaults
 * when the block is absent), so callers never branch on whether it was declared.
 */
final class ConfigDefinition
{
    /** Where `apps:import` puts extracted values when the manifest doesn't say. */
    public const DEFAULT_PROMPT_DIR = 'prompts';

    /**
     * @param string $promptDir   manifest-relative POSIX directory, already normalised by
     *                            {@see Builder\ConfigDefinitionBuilder}: no leading `./`,
     *                            no trailing slash — the shape
     *                            {@see Writer\ValueExtractor} expects
     * @param string $declaration the name the block was declared under, or '' for the
     *                            defaults no manifest wrote — it holds a name in the
     *                            shared declaration namespace, so `apps:import` has to
     *                            know about it ({@see \Llmor\Cli\Import\DeclarationNamer})
     */
    public function __construct(
        public readonly string $promptDir = self::DEFAULT_PROMPT_DIR,
        public readonly string $declaration = '',
    ) {
    }
}
