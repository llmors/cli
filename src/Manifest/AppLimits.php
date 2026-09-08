<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

/**
 * The app-field limits the server enforces, mirrored once.
 *
 * They exist locally so a typo fails at parse time instead of as a 400, and they are
 * needed in *both* directions: {@see Builder\AppDefinitionBuilder} rejects a declaration
 * that breaks one, while {@see \Llmor\Cli\Import\RemoteAppMapper} leaves out a remote
 * value that would. Those two read the same rule to opposite ends, so a second copy
 * drifts silently — and the only thing that would catch it is an import verifying
 * against a parser that no longer agrees with it.
 */
final class AppLimits
{
    public const MIN_NAME_LENGTH = 2;
    public const MAX_NAME_LENGTH = 144;
    public const MAX_DESCRIPTION_LENGTH = 4096;

    /** Sub-agent fields. */
    public const ALIAS_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';
    public const TOOL_NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';
    public const MAX_SUBAGENT_DESCRIPTION = 512;
    public const MAX_TOOL_DESCRIPTION = 1024;
}
