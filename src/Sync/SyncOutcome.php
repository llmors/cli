<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * What one sync did (or, in dry-run, would do) to one declared thing.
 *
 * A manifest declares more than one kind of thing, and each kind has its own notion
 * of "what changed" — a function counts files, an app counts parameters and links.
 * Rather than teach the renderer about every kind, each outcome pre-formats its own
 * one-line {@see detail()}, so {@see \Llmor\Cli\Command\AbstractManifestCommand::renderReport()}
 * stays a single loop with no type checks in it.
 */
interface SyncOutcome
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    /** The kind of declaration this outcome is about ('function', 'app'). */
    public function kind(): string;

    /** The declaration name, as written in the manifest. */
    public function subject(): string;

    /** One of {@see CREATED}, {@see UPDATED}, {@see UNCHANGED}. */
    public function action(): string;

    /** A compact, kind-specific summary of what changed, e.g. `+2 ~1 -0 =3`. */
    public function detail(): string;

    /**
     * Non-fatal problems worth showing the user.
     *
     * @return list<string>
     */
    public function warnings(): array;

    /**
     * Extra lines to render indented under this outcome (sub-resources).
     *
     * @return list<string>
     */
    public function detailLines(): array;

    /**
     * How many sub-resource files this outcome created, updated and deleted, for the
     * report's aggregate `summary.files`. A kind that owns no files reports zeroes.
     *
     * @return array{created: int, updated: int, deleted: int}
     */
    public function fileCounts(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
