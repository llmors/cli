<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use stdClass;

/**
 * A single `: App` declaration resolved from an `llmor.scsc` manifest.
 *
 * An llmor app is an *installed instance* of a registered app type: `[app_key]` names
 * the type (`llmor/generic`, `llmor/silicon`, …) and is deliberately not unique per
 * vendor, so the record's identity is its numeric id. The declaration name is
 * therefore a local handle — it binds to an id through `llmor.lock`
 * (see {@see \Llmor\Cli\Sync\AppLockFile}) rather than being sent to the API.
 *
 * `[name]`, `[description]` and `[model]` are optional: the server falls back to the
 * app type's own name and description, and picks the vendor's default completion
 * model. Anything the manifest doesn't declare is left as it is, which is what makes
 * a declaration safe to add to an app that already exists.
 *
 * @phpstan-import-type ParamValue from ParameterTree
 */
final class AppDefinition
{
    /**
     * @param string                    $declaration the declaration name — the local handle, not sent to the API
     * @param ?int                      $id          an explicit `[id]` pin, which overrides the lock file
     * @param stdClass                  $parameters  declared parameter *overrides*, merged over the remote bag
     * @param ?list<FunctionLink>       $functions   null when `[functions]` is absent — see {@see ownsFunctions()}
     * @param ?list<SubagentDefinition> $subagents   null when `[subagents]` is absent
     */
    public function __construct(
        public readonly string $declaration,
        public readonly string $appKey,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $model = null,
        public readonly ?int $id = null,
        public readonly stdClass $parameters = new stdClass(),
        public readonly ?array $functions = null,
        public readonly ?array $subagents = null,
    ) {
    }

    /**
     * Whether the manifest claims ownership of this app's installed functions.
     *
     * The API replaces the whole set whenever the `functions` field is present, so the
     * difference between "not declared" and "declared empty" is the difference between
     * leaving the console's links alone and unlinking every one of them.
     */
    public function ownsFunctions(): bool
    {
        return null !== $this->functions;
    }

    public function ownsSubagents(): bool
    {
        return null !== $this->subagents;
    }

    /** How many parameters the manifest declares. */
    public function parameterCount(): int
    {
        return \count((array) $this->parameters);
    }
}
