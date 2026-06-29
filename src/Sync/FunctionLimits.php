<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

/**
 * Server-side limits on a function's auxiliary file bundle, mirrored client-side
 * so we fail fast with a clear message instead of a 4xx
 * (see llmonrails `FunctionFilesystem/FunctionFilesystemLimits`).
 */
final class FunctionLimits
{
    /** Maximum size of a single file's content. */
    public const MAX_FILE_BYTES = 1_048_576;

    /** Maximum combined size of all files attached to a function. */
    public const MAX_BUNDLE_BYTES = 8_388_608;

    /** Maximum number of files attached to a function. */
    public const MAX_FILES = 256;
}
