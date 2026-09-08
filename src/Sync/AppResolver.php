<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use Llmor\Cli\Manifest\AppDefinition;

/**
 * Decides which remote app a declaration means, before anything is written.
 *
 * The chain, in order:
 *
 * 1. an explicit `[id]` in the manifest — always wins, and is the escape hatch for
 *    everything below;
 * 2. an `llmor.lock` entry for this vendor;
 * 3. adoption: exactly one existing app with the same `[name]` and `[app_key]`. This
 *    is what stops a first sync from duplicating an app someone built in the console;
 * 4. otherwise it will be created.
 *
 * Resolution runs for *every* declared app even when `--app` narrows the writes,
 * because a selected app's sub-agents may target an unselected one. It costs no
 * requests beyond the single listing already fetched; the one thing it can write is a
 * stale lock entry it drops, and a dry run's lock file refuses that on its own.
 */
final class AppResolver
{
    /** @var array<int, string> id => the declaration that claimed it */
    private array $claimed = [];

    public function __construct(
        private readonly string $vendorKey,
        private readonly AppLockFile $lock,
        private readonly RemoteAppIndex $index,
    ) {
        foreach ($this->lock->claimedIds($vendorKey) as $declaration => $id) {
            $this->claimed[$id] = $declaration;
        }
    }

    /**
     * @throws SyncException when the declaration cannot be bound unambiguously
     */
    public function resolve(AppDefinition $app): ResolvedApp
    {
        $warnings = [];

        if (null !== $app->id) {
            return $this->bind($app, $app->id, ResolvedApp::ORIGIN_PIN, $warnings);
        }

        $locked = $this->lock->lookup($this->vendorKey, $app->declaration);
        if (null !== $locked) {
            if ($locked['app_key'] !== $app->appKey) {
                throw new SyncException(\sprintf('App "%s" is recorded in %s as %s but the manifest declares %s. An app\'s type cannot be changed after it is created — use a new declaration name, or pin the right app with [id].', $app->declaration, AppLockFile::FILE_NAME, $locked['app_key'], $app->appKey));
            }

            if (null === $this->index->byId($locked['id'])) {
                // The app was deleted in the console. Drop the stale binding and fall
                // through, so the next steps can adopt or re-create.
                $warnings[] = \sprintf('App #%d recorded in %s no longer exists — it will be recreated.', $locked['id'], AppLockFile::FILE_NAME);
                unset($this->claimed[$locked['id']]);
                $this->lock->forget($this->vendorKey, $app->declaration);
            } else {
                return $this->bind($app, $locked['id'], ResolvedApp::ORIGIN_LOCK, $warnings);
            }
        }

        return $this->adoptOrCreate($app, $warnings);
    }

    /**
     * Claim an id that was created during this run, so no later declaration adopts it.
     */
    public function claim(string $declaration, int $id): void
    {
        $this->claimed[$id] = $declaration;
    }

    /**
     * @param list<string> $warnings
     *
     * @throws SyncException
     */
    private function adoptOrCreate(AppDefinition $app, array $warnings): ResolvedApp
    {
        // Without a declared name there is nothing to match on, so never adopt.
        if (null === $app->name) {
            return new ResolvedApp($app, null, ResolvedApp::ORIGIN_NEW, $warnings);
        }

        $candidates = [];
        foreach ($this->index->matching($app->name, $app->appKey) as $record) {
            $id = Json::idOf($record['id'] ?? null);
            if (null !== $id && !isset($this->claimed[$id])) {
                $candidates[$id] = $record;
            }
        }

        if ([] === $candidates) {
            return new ResolvedApp($app, null, ResolvedApp::ORIGIN_NEW, $warnings);
        }

        if (\count($candidates) > 1) {
            throw new SyncException(\sprintf('App "%s" matches %d existing apps named "%s" (#%s). Pin the one you mean with [id] = <id>.', $app->declaration, \count($candidates), $app->name, \implode(', #', \array_keys($candidates))));
        }

        $id = (int) \array_key_first($candidates);
        // Say so out loud: this is the moment the CLI takes over a record a human made.
        $warnings[] = \sprintf('Adopted existing app #%d (matched by [name] and [app_key]).', $id);

        return $this->bind($app, $id, ResolvedApp::ORIGIN_ADOPTED, $warnings, $candidates[$id]);
    }

    /**
     * @param list<string>              $warnings
     * @param array<string, mixed>|null $record
     *
     * @throws SyncException
     */
    private function bind(AppDefinition $app, int $id, string $origin, array $warnings, ?array $record = null): ResolvedApp
    {
        $record ??= $this->index->byId($id);

        if (null === $record) {
            if (ResolvedApp::ORIGIN_PIN === $origin) {
                throw new SyncException(\sprintf('App "%s" pins [id] = %d, which does not exist for this vendor.', $app->declaration, $id));
            }

            return new ResolvedApp($app, null, ResolvedApp::ORIGIN_NEW, $warnings);
        }

        $remoteKey = Json::stringOf($record['app_key'] ?? null);
        if ('' !== $remoteKey && $remoteKey !== $app->appKey) {
            throw new SyncException(\sprintf('App "%s" resolves to #%d, which is a %s app, but the manifest declares %s. An app\'s type cannot be changed after it is created.', $app->declaration, $id, $remoteKey, $app->appKey));
        }

        $owner = $this->claimed[$id] ?? null;
        if (null !== $owner && $owner !== $app->declaration) {
            throw new SyncException(\sprintf('Apps "%s" and "%s" both resolve to #%d. Pin one of them with [id] = <id>.', $owner, $app->declaration, $id));
        }

        $this->claimed[$id] = $app->declaration;

        return new ResolvedApp($app, $id, $origin, $warnings);
    }
}
