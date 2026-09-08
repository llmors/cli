<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Support;

use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Config\Configuration;
use Llmor\Cli\Services;

/**
 * A signed {@see LlmorClient} wired to a {@see FakeLlmorApi}.
 *
 * Every functional and sync test needs the same three lines of bootstrap — a
 * {@see Configuration} pointing at the temp project, {@see Services} holding the mock
 * transport, and the client out of it. Keeping them here means a change to that wiring
 * is one edit rather than seven.
 */
final class TestClient
{
    public const VENDOR_KEY = 'acme-co';

    public static function forApi(FakeLlmorApi $api, string $projectDir, string $vendorKey = self::VENDOR_KEY): LlmorClient
    {
        $config = new Configuration('https://api.test', 'admin@test.llmor', 'pw', $vendorKey, $projectDir);

        return (new Services($config, $api->client()))->client;
    }
}
