<?php

declare(strict_types=1);

namespace Llmor\Cli\Client\Exception;

/**
 * Raised when authentication cannot be established: missing credentials,
 * rejected sign-in, or a session that remains invalid after re-authentication.
 */
final class AuthenticationException extends ApiException
{
    public static function missingCredentials(): self
    {
        return new self(
            'No credentials configured. Run "llmor auth:login" to sign in.',
            statusCode: 0,
        );
    }
}
