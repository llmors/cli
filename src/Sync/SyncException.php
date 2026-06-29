<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use RuntimeException;

/**
 * Raised for sync-time problems that are not API errors: an unresolvable vendor,
 * or local source files that exceed the server's bundle limits.
 */
final class SyncException extends RuntimeException
{
}
