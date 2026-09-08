<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * One sub-agent binding: an alias on this app that delegates to another app.
 *
 * Unlike `[parameters]`, which are overrides, every sub-agent field is owned by the
 * manifest. The API requires all of them on write and stores the text fields as empty
 * strings when they're absent, so omitting `[tool_description]` here genuinely clears
 * it remotely — there is no "leave that one alone".
 */
final class SubagentDefinition
{
    /**
     * @param string $target   the target app: a declaration name in this manifest,
     *                         or a numeric id as a string
     * @param ?int   $targetId set when the target was written as a numeric id
     */
    public function __construct(
        public readonly string $alias,
        public readonly string $target,
        public readonly ?int $targetId = null,
        public readonly string $description = '',
        public readonly bool $exposeAsTool = false,
        public readonly string $toolName = '',
        public readonly string $toolDescription = '',
        public readonly string $inputDescription = '',
    ) {
    }

    /**
     * The tool name the server will derive — `tool_name` when given, else the alias.
     * Mirrored here so a collision can be caught before the API rejects it.
     */
    public function effectiveToolName(): string
    {
        $name = \trim($this->toolName);

        return '' !== $name ? $name : $this->alias;
    }
}
