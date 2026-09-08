<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * Maps function keys to ids for the apps that install them.
 *
 * Ids come from this run's own function results first, then from a lookup for
 * anything not synced here — a function excluded by `--function`, or one that only
 * exists remotely. Reading {@see SyncResult::$functionId} alone would not do: it is
 * null in a dry run and whenever a create response carried no id.
 */
final class FunctionIdResolver
{
    /** @var array<string, ?int> */
    private array $ids = [];

    public function __construct(private readonly FunctionSynchronizer $functions)
    {
    }

    /** Seed a key that was just synced, so no lookup is needed for it. */
    public function remember(string $functionKey, ?int $id): void
    {
        $this->ids[$functionKey] = $id;
    }

    /**
     * The function's id, or null when it does not exist remotely (yet).
     */
    public function resolve(string $functionKey): ?int
    {
        if (\array_key_exists($functionKey, $this->ids) && null !== $this->ids[$functionKey]) {
            return $this->ids[$functionKey];
        }

        $existing = $this->functions->findFunction($functionKey);

        return $this->ids[$functionKey] = null === $existing ? null : Json::idOf($existing['id'] ?? null);
    }
}
