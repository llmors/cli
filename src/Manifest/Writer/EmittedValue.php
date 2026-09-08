<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

/**
 * One emitted value, plus whether emitting it lost anything.
 *
 * `$lossy` is deliberately separate from "has notes": degrading a huge float to a
 * quoted numeric string produces a note but loses nothing that
 * {@see \Llmor\Cli\Sync\ParameterMerger::equals()} can see, whereas dropping a key
 * does. Only the latter may force an enclosing list to be dropped.
 */
final class EmittedValue
{
    /**
     * @param ?string      $scsc  the rendered value, or null when it had to be dropped
     * @param list<string> $notes human-readable, one per compromise made
     * @param bool         $lossy whether the emitted value no longer describes the input
     */
    public function __construct(
        public readonly ?string $scsc,
        public readonly array $notes = [],
        public readonly bool $lossy = false,
    ) {
    }

    public static function dropped(string $note): self
    {
        return new self(null, [$note], true);
    }
}
