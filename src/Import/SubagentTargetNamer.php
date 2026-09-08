<?php

declare(strict_types=1);

namespace Llmor\Cli\Import;

use Llmor\Cli\Manifest\Manifest;
use Llmor\Cli\Sync\AppLockFile;

/**
 * Maps a remote `target_vendor_app_id` back to something a manifest can say.
 *
 * A sub-agent's target is an app id remotely, but a declaration name locally, and only
 * `llmor.lock` (or an explicit `[id]` pin) knows which is which. When neither does, the
 * numeric form is used — `[app] = 21` is first-class syntax, not a fallback in disguise:
 * {@see \Llmor\Cli\Manifest\Builder\AppDefinitionBuilder} reads it as a `targetId` and
 * {@see \Llmor\Cli\Manifest\ManifestParser} skips the cross-reference check for it, so a
 * sub-agent pointing outside the manifest still syncs correctly.
 */
final class SubagentTargetNamer
{
    /** @var array<int, string> app id => declaration name */
    private array $names = [];

    public function __construct(string $vendorKey, Manifest $manifest, AppLockFile $lock)
    {
        foreach ($manifest->apps as $app) {
            // An `[id]` pin wins, being the more explicit of the two.
            if (null !== $app->id) {
                $this->names[$app->id] = $app->declaration;
                continue;
            }

            $entry = $lock->lookup($vendorKey, $app->declaration);
            if (null !== $entry) {
                $this->names[$entry['id']] = $app->declaration;
            }
        }
    }

    /** The declaration name bound to this id, or null when nothing here declares it. */
    public function nameOf(int $appId): ?string
    {
        return $this->names[$appId] ?? null;
    }
}
