<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use JsonException;

/**
 * Binds manifest app declarations to the numeric ids they own, in `llmor.lock`
 * next to the manifest.
 *
 * Functions reconcile by `function_key`, but an app has no such field: `app_type`
 * names the *type* and is deliberately not unique per vendor, so a record's only
 * identity is its id. This file is what makes `support_bot` in the manifest mean the
 * same app on every machine, and it is meant to be **committed**.
 *
 * Entries are keyed by vendor key first, so one manifest can be synced to a staging
 * and a production vendor without the two fighting over ids.
 *
 * Two properties this class exists to guarantee:
 *
 * - **Read-merge-write, never truncate.** A `--app x` run must leave every other
 *   app — and every other vendor's section — exactly as it found it, including keys
 *   written by a future version of the CLI.
 * - **Atomic and immediate.** A create is flushed the moment it happens, via a temp
 *   file and `rename()`. An app that exists remotely but not here is orphaned, and
 *   deleting an app needs super-admin, so a lost id cannot be cleaned up.
 *
 * A `--dry-run` constructs it read-only. That belongs here rather than at each call
 * site: resolution rewrites the file too (a stale entry is forgotten), so a `$dryRun`
 * flag threaded through the writers would still have left that path writing.
 *
 * @phpstan-type LockApp array{id: int, app_type: string}
 */
final class AppLockFile
{
    public const FILE_NAME = 'llmor.lock';

    /**
     * Bumped only for a format change this version could not read.
     *
     * The `app_key` → `app_type` rename did *not* bump it: both spellings are read, and
     * a file written here is still usable by an older CLI — it calls the entry malformed
     * and falls back to adopting by `[name]` + type, which recovers a named app (and may
     * recreate an unnamed one).
     */
    public const VERSION = 1;

    /** The pre-rename spelling of the type field, still read so committed locks resolve. */
    private const LEGACY_TYPE_KEY = 'app_key';

    /** @var array<string, mixed>|null the raw document, loaded lazily */
    private ?array $document = null;

    /** @var list<string> */
    public array $warnings = [];

    private bool $written = false;

    public function __construct(
        public readonly string $path,
        private readonly bool $readOnly = false,
    ) {
    }

    public static function besideManifest(string $manifestPath, bool $readOnly = false): self
    {
        return new self(\dirname($manifestPath).\DIRECTORY_SEPARATOR.self::FILE_NAME, $readOnly);
    }

    /** Whether this run changed the file (used to decide whether to mention it). */
    public function wasWritten(): bool
    {
        return $this->written;
    }

    /**
     * The recorded binding for one declaration, or null when it has never been synced
     * to this vendor. A malformed entry is dropped with a warning rather than failing
     * the run — it is a hand-editable file.
     *
     * An entry still spelling the type `app_key` is normalised, not malformed; it is
     * rewritten by the next {@see record()}.
     *
     * @return LockApp|null
     */
    public function lookup(string $vendorKey, string $declaration): ?array
    {
        $entry = Json::mapOf($this->apps($vendorKey)[$declaration] ?? null);

        $id = Json::idOf($entry['id'] ?? null);
        $appType = Json::stringOf($entry['app_type'] ?? $entry[self::LEGACY_TYPE_KEY] ?? null);

        if (null === $id || '' === $appType) {
            if ([] !== $entry) {
                $this->warnings[] = \sprintf('Ignoring malformed %s entry for app "%s".', self::FILE_NAME, $declaration);
            }

            return null;
        }

        return ['id' => $id, 'app_type' => $appType];
    }

    /**
     * Which declaration owns each id already bound for this vendor. Adoption must not
     * hand the same app to two declarations, or they would overwrite each other
     * forever — and naming the owner is what makes that collision reportable.
     *
     * @return array<string, int> declaration => id
     */
    public function claimedIds(string $vendorKey): array
    {
        $ids = [];
        foreach (\array_keys($this->apps($vendorKey)) as $declaration) {
            $entry = $this->lookup($vendorKey, $declaration);
            if (null !== $entry) {
                $ids[$declaration] = $entry['id'];
            }
        }

        return $ids;
    }

    /**
     * Bind a declaration to an id and flush immediately.
     *
     * @throws SyncException when the file cannot be written — an unrecorded app is an
     *                       unrecoverable leak, so this is fatal rather than a warning
     */
    public function record(string $vendorKey, string $declaration, int $id, string $appType): void
    {
        // A legacy-spelled entry reads back identical, so the up-to-date check has to
        // look at the raw shape — otherwise the file would keep `app_key` forever.
        $legacy = \array_key_exists(self::LEGACY_TYPE_KEY, Json::mapOf($this->apps($vendorKey)[$declaration] ?? null));

        $existing = $this->lookup($vendorKey, $declaration);
        if (!$legacy && null !== $existing && $existing['id'] === $id && $existing['app_type'] === $appType) {
            return;
        }

        $this->mutate($vendorKey, static function (array $apps) use ($declaration, $id, $appType): array {
            $apps[$declaration] = ['id' => $id, 'app_type' => $appType];

            return $apps;
        });
    }

    /**
     * Drop a binding whose app no longer exists remotely.
     *
     * Only ever called after the server said the app is gone. A declaration merely
     * removed from the manifest keeps its entry on purpose: the app still exists (and
     * can't be deleted), so re-adding the declaration should re-bind the same app
     * rather than create a duplicate.
     *
     * @throws SyncException
     */
    public function forget(string $vendorKey, string $declaration): void
    {
        if (null === ($this->apps($vendorKey)[$declaration] ?? null)) {
            return;
        }

        $this->mutate($vendorKey, static function (array $apps) use ($declaration): array {
            unset($apps[$declaration]);

            return $apps;
        });
    }

    /**
     * Re-read from disk, apply a change to one vendor's app map, write atomically.
     *
     * The re-read is what keeps two terminals from clobbering each other, and what
     * preserves sections and keys this version doesn't know about.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     *
     * @throws SyncException
     */
    private function mutate(string $vendorKey, callable $change): void
    {
        if ($this->readOnly) {
            return;
        }

        $document = $this->read();

        $vendors = Json::mapOf($document['vendors'] ?? null);
        $vendor = Json::mapOf($vendors[$vendorKey] ?? null);
        $vendor['apps'] = $change(Json::mapOf($vendor['apps'] ?? null));
        $vendors[$vendorKey] = $vendor;

        $document['version'] = self::VERSION;
        $document['vendors'] = $vendors;

        $this->write($document);
        $this->document = $document;
        $this->written = true;
    }

    /**
     * @return array<string, LockApp|mixed>
     */
    private function apps(string $vendorKey): array
    {
        $this->document ??= $this->read();

        $vendors = Json::mapOf($this->document['vendors'] ?? null);

        return Json::mapOf(Json::mapOf($vendors[$vendorKey] ?? null)['apps'] ?? null);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SyncException
     */
    private function read(): array
    {
        if (!\is_file($this->path)) {
            return [];
        }

        $raw = @\file_get_contents($this->path);
        if (false === $raw || '' === \trim($raw)) {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SyncException(\sprintf('%s is not valid JSON: %s', $this->path, $e->getMessage()), 0, $e);
        }

        $document = Json::mapOf($decoded);
        $version = Json::intOf($document['version'] ?? null, self::VERSION);

        if ($version > self::VERSION) {
            throw new SyncException(\sprintf('%s was written by a newer llmor (format version %d, this version reads %d) — upgrade with `llmor self-update`.', $this->path, $version, self::VERSION));
        }

        return $document;
    }

    /**
     * Write deterministically — sorted keys, pretty-printed, trailing newline — so a
     * committed file doesn't churn in diffs, and atomically so an interrupted run
     * cannot leave a half-written identity store.
     *
     * @param array<string, mixed> $document
     *
     * @throws SyncException
     */
    private function write(array $document): void
    {
        $document = self::sorted($document);

        try {
            $json = \json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SyncException(\sprintf('Cannot encode %s: %s', $this->path, $e->getMessage()), 0, $e);
        }

        // Same directory, so the rename stays on one filesystem and is atomic.
        $temp = $this->path.'.tmp';
        if (false === @\file_put_contents($temp, $json."\n")) {
            throw new SyncException(\sprintf('Cannot write %s — the app ids from this run could not be recorded.', $temp));
        }

        if (!@\rename($temp, $this->path)) {
            @\unlink($temp);

            throw new SyncException(\sprintf('Cannot write %s — the app ids from this run could not be recorded.', $this->path));
        }
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function sorted(array $document): array
    {
        $vendors = Json::mapOf($document['vendors'] ?? null);
        \ksort($vendors);

        foreach ($vendors as $key => $vendor) {
            $section = Json::mapOf($vendor);
            $apps = Json::mapOf($section['apps'] ?? null);
            \ksort($apps);
            $section['apps'] = $apps;
            \ksort($section);
            $vendors[$key] = $section;
        }

        $document['vendors'] = $vendors;
        \ksort($document);

        return $document;
    }
}
