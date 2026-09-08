<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Chat;

use Llmor\Cli\Manifest\AppDefinition;

/**
 * The app a chat session is pointed at, however it was named.
 *
 * `test` accepts three routes to the same place — a manifest declaration, a bare
 * `--app-id`, or a `--conversation` token that already knows its app — and the three
 * carry different amounts of context. This holds what all of them can supply, so the
 * rest of the command does not branch on which one was used.
 *
 * `$id` is 0 when a resumed conversation named no app: the id lives server-side and is
 * never needed again, since interacting only ever addresses the conversation token.
 */
final class AppTarget
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly ?AppDefinition $definition,
    ) {
    }
}
