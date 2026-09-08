<?php

declare(strict_types=1);

namespace Llmor\Cli\Import;

use RuntimeException;

/**
 * Raised when an app cannot be imported: the declaration name is taken, the emitted
 * declaration failed its own verification, or a file could not be written.
 */
final class ImportException extends RuntimeException
{
}
