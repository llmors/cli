<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * One sub-agent's outcome, rendered as an indented line under its app rather than as
 * a report row of its own — a row per sub-agent would drown the report and make the
 * "N app(s)" count read wrong.
 */
final class SubagentChange
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    /** The target app exists only after a real sync — dry runs report this instead. */
    public const PENDING = 'pending';

    public function __construct(
        public readonly string $alias,
        public readonly string $action,
        public readonly string $target,
        public readonly ?int $targetId = null,
        public readonly ?string $toolName = null,
    ) {
    }

    public function describe(): string
    {
        $line = \sprintf('%s  %s  → %s', $this->alias, $this->action, $this->target);

        if (null !== $this->targetId) {
            $line .= \sprintf(' (#%d)', $this->targetId);
        } elseif (self::PENDING === $this->action) {
            $line .= ' (pending)';
        }

        if (null !== $this->toolName && '' !== $this->toolName) {
            $line .= \sprintf(' as tool "%s"', $this->toolName);
        }

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'alias' => $this->alias,
            'action' => $this->action,
            'target' => $this->target,
            'target_id' => $this->targetId,
            'tool_name' => $this->toolName,
        ];
    }
}
