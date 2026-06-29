<?php

declare(strict_types=1);

namespace Llmor\Cli\Client\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for any non-successful API interaction. Carries the HTTP
 * status code and the decoded response body for richer error reporting.
 *
 * Subclasses keep this constructor signature unchanged, so {@see fromResponse()}
 * can use `new static` to build the right type.
 *
 * @phpstan-consistent-constructor
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $body decoded response body
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $body = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromResponse(int $statusCode, array $body): static
    {
        $message = \is_string($body['message'] ?? null) && '' !== $body['message']
            ? $body['message']
            : \sprintf('Request failed with HTTP status %d.', $statusCode);

        // `new static` so ValidationException::fromResponse() yields a ValidationException.
        return new static($message, $statusCode, $body);
    }
}
