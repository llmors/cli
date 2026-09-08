<?php

declare(strict_types=1);

namespace Llmor\Cli\Import;

use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\Writer\WrittenDeclaration;

/**
 * Everything an import would do, decided before anything is written.
 *
 * The same object drives `--dry-run` and the real run, which is the only way to be sure
 * the preview and the write agree.
 */
final class ImportPlan
{
    /**
     * @param list<string> $warnings what the manifest will not carry, and why
     * @param list<string> $skipped  console-managed fields this app has set
     */
    public function __construct(
        public readonly int $appId,
        public readonly AppDefinition $definition,
        public readonly WrittenDeclaration $written,
        public readonly string $manifestPath,
        public readonly string $lockPath,
        public readonly bool $manifestExists,
        public readonly array $warnings = [],
        public readonly array $skipped = [],
    ) {
    }

    public function declaration(): string
    {
        return $this->definition->declaration;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $files = [];
        foreach ($this->written->files as $path => $contents) {
            $files[] = ['path' => $path, 'bytes' => \strlen($contents)];
        }

        return [
            'app_id' => $this->appId,
            'declaration' => $this->declaration(),
            'app_key' => $this->definition->appKey,
            'manifest' => $this->manifestPath,
            'manifest_created' => !$this->manifestExists,
            'lock' => $this->lockPath,
            'declaration_scsc' => $this->written->scsc,
            'files' => $files,
            'parameters' => $this->definition->parameterCount(),
            'functions' => \count($this->definition->functions ?? []),
            'subagents' => \count($this->definition->subagents ?? []),
            'warnings' => [...$this->warnings, ...$this->written->notes],
            'skipped_fields' => $this->skipped,
        ];
    }
}
