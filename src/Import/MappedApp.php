<?php

declare(strict_types=1);

namespace Llmor\Cli\Import;

use Llmor\Cli\Manifest\AppDefinition;

/**
 * What {@see RemoteAppMapper} made of a remote record: the declaration it can express,
 * and everything it could not.
 */
final class MappedApp
{
    /**
     * @param list<string> $warnings things the manifest will not carry, and why
     * @param list<string> $skipped  console-managed fields this app actually has set
     */
    public function __construct(
        public readonly AppDefinition $definition,
        public readonly array $warnings = [],
        public readonly array $skipped = [],
    ) {
    }
}
