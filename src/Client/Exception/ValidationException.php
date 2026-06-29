<?php

declare(strict_types=1);

namespace Llmor\Cli\Client\Exception;

/**
 * Raised on a 422 validation error. Exposes the per-field `errors` map from
 * the API response body.
 */
final class ValidationException extends ApiException
{
    /**
     * @return array<string, mixed> field => list of error messages
     */
    public function errors(): array
    {
        $errors = $this->body['errors'] ?? [];

        return \is_array($errors) ? $errors : [];
    }
}
