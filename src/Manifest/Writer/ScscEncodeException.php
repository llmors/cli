<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

use RuntimeException;

/**
 * Raised when a value cannot be written as SchemaScript at all.
 *
 * Deliberately *not* a {@see \Llmor\Cli\Manifest\ManifestException}: this is a signal
 * to the caller that one leaf has to be dropped or degraded, not a failure of the
 * whole operation. {@see ParameterEmitter} catches it and records the path.
 */
final class ScscEncodeException extends RuntimeException
{
}
