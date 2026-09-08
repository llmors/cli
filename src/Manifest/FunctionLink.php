<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use stdClass;

/**
 * One function installed on an app, with its per-app config.
 *
 * The same function can be installed on several apps with different config, which is
 * why the config lives on the link rather than on the function. `$name` is a function
 * key — usually a `: Function` declared in the same manifest, but a function that only
 * exists remotely (made in the console) can be referenced by key just as well.
 */
final class FunctionLink
{
    public function __construct(
        public readonly string $name,
        public readonly stdClass $config = new stdClass(),
    ) {
    }
}
