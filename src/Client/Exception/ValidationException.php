<?php

declare(strict_types=1);

namespace Llmor\Cli\Client\Exception;

/**
 * Raised on a validation error (HTTP 422, or 400 carrying an `errors` map —
 * the API validator currently answers with 400). Exposes the per-field
 * `errors` map from the response body.
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
