<?php

declare(strict_types=1);

namespace Llmor\Cli\Client;

/**
 * A decoded, successful API response.
 *
 * llmor list endpoints wrap results as `{data: [...], meta: {...}}` while
 * single-resource endpoints return either `{data: {...}}` or a bare object.
 * {@see data()} normalises this so commands don't need to care.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $body decoded response body (the full envelope)
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $body,
    ) {
    }

    /**
     * The payload: the `data` key when present, otherwise the whole body.
     *
     * @return array<array-key, mixed>
     */
    public function data(): array
    {
        if (\array_key_exists('data', $this->body) && \is_array($this->body['data'])) {
            return $this->body['data'];
        }

        return $this->body;
    }

    /**
     * Pagination / envelope metadata (`meta` key), if any.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = $this->body['meta'] ?? [];

        return \is_array($meta) ? $meta : [];
    }
}
