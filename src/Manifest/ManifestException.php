<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use RuntimeException;

/**
 * Raised when an `llmor.scsc` manifest is missing, unparseable, or declares a
 * function with invalid/incomplete metadata.
 */
final class ManifestException extends RuntimeException
{
}
