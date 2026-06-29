<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use JsonException;
use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\Exception\AuthenticationException;
use Llmor\Cli\Client\Exception\ValidationException;
use Llmor\Cli\Manifest\ManifestException;
use Throwable;

/**
 * Normalises any exception raised during a sync/run into a {@see SyncError}, so
 * the command layer collects one consistent shape regardless of the source.
 */
final class SyncErrorFactory
{
    public static function fromThrowable(Throwable $e, ?string $functionKey, string $scope): SyncError
    {
        return match (true) {
            $e instanceof ValidationException => self::fromValidation($e, $functionKey, $scope),
            $e instanceof AuthenticationException => new SyncError(
                scope: $scope,
                category: SyncError::CATEGORY_AUTH,
                summary: $e->getMessage(),
                functionKey: $functionKey,
                hint: 'run `llmor auth:login` to refresh your credentials',
                statusCode: $e->statusCode,
            ),
            $e instanceof ApiException => new SyncError(
                scope: $scope,
                category: SyncError::CATEGORY_API,
                summary: $e->getMessage(),
                functionKey: $functionKey,
                statusCode: $e->statusCode,
            ),
            $e instanceof ManifestException => new SyncError(
                scope: $scope,
                category: SyncError::CATEGORY_CONFIG,
                summary: $e->getMessage(),
                functionKey: $functionKey,
            ),
            $e instanceof JsonException => new SyncError(
                scope: SyncError::SCOPE_INPUT,
                category: SyncError::CATEGORY_INPUT,
                summary: $e->getMessage(),
                functionKey: $functionKey,
            ),
            default => new SyncError(
                scope: $scope,
                category: SyncError::CATEGORY_LOCAL,
                summary: $e->getMessage(),
                functionKey: $functionKey,
            ),
        };
    }

    private static function fromValidation(ValidationException $e, ?string $functionKey, string $scope): SyncError
    {
        $fields = ValidationErrorFormatter::clean($e->errors());

        return new SyncError(
            scope: $scope,
            category: SyncError::CATEGORY_VALIDATION,
            summary: 'the data was rejected by the API',
            functionKey: $functionKey,
            fields: $fields,
            hint: self::firstHint($fields),
            statusCode: $e->statusCode,
        );
    }

    /**
     * @param array<string, list<string>> $fields
     */
    private static function firstHint(array $fields): ?string
    {
        foreach ($fields as $field => $messages) {
            $hint = ValidationErrorFormatter::hint((string) $field, $messages);
            if (null !== $hint) {
                return $hint;
            }
        }

        return null;
    }
}
