<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

use JsonException;
use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\Exception\AuthenticationException;
use Llmor\Cli\Client\Exception\ValidationException;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Sync\SyncError;
use Llmor\Cli\Sync\SyncErrorFactory;
use Llmor\Cli\Sync\SyncException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncErrorFactory::class)]
#[CoversClass(SyncError::class)]
final class SyncErrorFactoryTest extends TestCase
{
    public function testValidationExceptionBecomesValidationErrorWithFieldsAndHint(): void
    {
        $e = new ValidationException('The given data is invalid.', 400, ['errors' => [
            'runtime' => ['in' => '"python" is not allowed'],
        ]]);

        $error = SyncErrorFactory::fromThrowable($e, 'my_fn', SyncError::SCOPE_FUNCTION);

        self::assertSame(SyncError::CATEGORY_VALIDATION, $error->category);
        self::assertSame('my_fn', $error->functionKey);
        self::assertArrayHasKey('runtime', $error->fields);
        self::assertSame("must be 'silicon' or 'graph'", $error->hint);
        self::assertSame(400, $error->statusCode);
    }

    public function testAuthenticationExceptionSuggestsLogin(): void
    {
        $error = SyncErrorFactory::fromThrowable(
            new AuthenticationException('Authentication failed.', 401, []),
            null,
            SyncError::SCOPE_VENDOR,
        );

        self::assertSame(SyncError::CATEGORY_AUTH, $error->category);
        self::assertNotNull($error->hint);
        self::assertStringContainsString('auth:login', $error->hint);
    }

    public function testGenericApiExceptionKeepsStatusAndMessage(): void
    {
        $error = SyncErrorFactory::fromThrowable(
            new ApiException('Server exploded', 500, []),
            'my_fn',
            SyncError::SCOPE_FUNCTION,
        );

        self::assertSame(SyncError::CATEGORY_API, $error->category);
        self::assertSame('Server exploded', $error->summary);
        self::assertSame(500, $error->statusCode);
    }

    public function testManifestSyncAndJsonExceptionsAreCategorised(): void
    {
        $manifest = SyncErrorFactory::fromThrowable(new ManifestException('bad scsc'), 'f', SyncError::SCOPE_MANIFEST);
        self::assertSame(SyncError::CATEGORY_CONFIG, $manifest->category);

        $local = SyncErrorFactory::fromThrowable(new SyncException('too many files'), 'f', SyncError::SCOPE_FUNCTION);
        self::assertSame(SyncError::CATEGORY_LOCAL, $local->category);

        $input = SyncErrorFactory::fromThrowable(new JsonException('bad json'), 'f', SyncError::SCOPE_FUNCTION);
        self::assertSame(SyncError::CATEGORY_INPUT, $input->category);
        self::assertSame(SyncError::SCOPE_INPUT, $input->scope);
    }
}
