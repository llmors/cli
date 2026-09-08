<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use Llmor\Cli\Client\LlmorClient;

/**
 * Reads every page of a list endpoint.
 *
 * llmor's list endpoints are zero-indexed and cap `page_size` at 200, and report the
 * total under `meta.total_count` when asked with `count=1`.
 */
final class PagedList
{
    public const PAGE_SIZE = 200;

    /**
     * @param array<string, scalar|null> $query
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchAll(LlmorClient $client, string $path, array $query = []): array
    {
        $page = 0;
        $collected = [];

        do {
            $response = $client->get($path, $query + [
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'count' => 1,
            ]);

            $items = $response->data();
            foreach ($items as $item) {
                if (\is_array($item)) {
                    $collected[] = $item;
                }
            }

            $meta = $response->meta();
            $total = isset($meta['total_count']) ? (int) $meta['total_count'] : \count($collected);
            ++$page;
        } while ([] !== $items && \count($collected) < $total);

        return $collected;
    }
}
