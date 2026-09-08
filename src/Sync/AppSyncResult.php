<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * What {@see AppSynchronizer} did (or, in dry-run, would do) to one declared app.
 *
 * The report shows the numeric id inline: it is the app's real identity, it's what
 * the console shows, and it's what an `[id]` pin needs.
 */
final class AppSyncResult implements SyncOutcome
{
    /** How many changed parameter names to name before summarising the rest. */
    private const NAMED_PARAMETERS = 3;

    public string $appAction = self::UNCHANGED;

    public ?int $appId = null;

    public string $origin = ResolvedApp::ORIGIN_NEW;

    /** @var list<string> changed record fields ('name', 'description', 'model') */
    public array $changedFields = [];

    /** @var list<string> dotted paths of changed parameters */
    public array $changedParameters = [];

    /** How many parameters the manifest declares (shown on create). */
    public int $parameterCount = 0;

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<SubagentChange> */
    public array $subagents = [];

    /** @var list<string> function keys installed on the app */
    public array $linkedFunctions = [];

    public bool $functionsChanged = false;

    public function __construct(
        public readonly string $declaration,
        public readonly string $appKey,
    ) {
    }

    public function kind(): string
    {
        return 'app';
    }

    public function subject(): string
    {
        return $this->declaration;
    }

    public function action(): string
    {
        return $this->appAction;
    }

    public function detail(): string
    {
        $parts = [null === $this->appId ? '(new)' : '#'.$this->appId];

        if (self::CREATED === $this->appAction) {
            $parts[] = $this->appKey;
            if ($this->parameterCount > 0) {
                $parts[] = \sprintf('params %d', $this->parameterCount);
            }
        } elseif ([] !== $this->changedParameters) {
            $parts[] = \sprintf('params ~%d (%s)', \count($this->changedParameters), self::names($this->changedParameters));
        }

        if ($this->functionsChanged) {
            $parts[] = \sprintf('fn %d', \count($this->linkedFunctions));
        }

        foreach ($this->changedFields as $field) {
            $parts[] = $field;
        }

        if (ResolvedApp::ORIGIN_ADOPTED === $this->origin) {
            $parts[] = 'adopted';
        }

        return \implode(' · ', $parts);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<string>
     */
    public function detailLines(): array
    {
        return \array_map(static fn (SubagentChange $change): string => $change->describe(), $this->subagents);
    }

    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    public function fileCounts(): array
    {
        return ['created' => 0, 'updated' => 0, 'deleted' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind(),
            'app' => $this->declaration,
            'app_key' => $this->appKey,
            'action' => $this->appAction,
            'app_id' => $this->appId,
            'origin' => $this->origin,
            'changed_fields' => $this->changedFields,
            'changed_parameters' => $this->changedParameters,
            'functions' => $this->linkedFunctions,
            'functions_changed' => $this->functionsChanged,
            'subagents' => \array_map(static fn (SubagentChange $c): array => $c->toArray(), $this->subagents),
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param list<string> $paths
     */
    private static function names(array $paths): string
    {
        $shown = \array_slice($paths, 0, self::NAMED_PARAMETERS);
        $rest = \count($paths) - \count($shown);

        return \implode(', ', $shown).($rest > 0 ? \sprintf(', +%d more', $rest) : '');
    }
}
