<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use Llmor\Cli\Manifest\AppDefinition;

/**
 * A declared app paired with the remote record it turned out to mean.
 *
 * `id === null` means "does not exist yet"; the app is created on the write pass, or —
 * in a dry run — stays pending, which is also how a sub-agent target that hasn't been
 * created yet is represented.
 *
 * Non-fatal notes from resolution (an adoption, a stale lock entry) travel with the
 * resolution rather than through an out-parameter, so whoever reports on this app has
 * them without keeping a parallel map.
 */
final class ResolvedApp
{
    /** Bound through an `llmor.lock` entry. */
    public const ORIGIN_LOCK = 'lock';

    /** Bound through an explicit `[id]` in the manifest. */
    public const ORIGIN_PIN = 'pin';

    /** Matched an existing remote app by name + app_key. */
    public const ORIGIN_ADOPTED = 'adopted';

    /** No remote counterpart — will be created. */
    public const ORIGIN_NEW = 'new';

    /**
     * @param list<string> $warnings non-fatal notes raised while resolving
     */
    public function __construct(
        public readonly AppDefinition $definition,
        public readonly ?int $id,
        public readonly string $origin,
        public readonly array $warnings = [],
    ) {
    }

    /**
     * The same resolution with an id it didn't have before — used after a create, so
     * later passes (and other apps' sub-agents) can see the new app.
     */
    public function withId(int $id): self
    {
        return new self($this->definition, $id, $this->origin, $this->warnings);
    }
}
