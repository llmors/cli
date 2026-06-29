<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use Llmor\Cli\Client\LlmorClient;

/**
 * Resolves the configured vendor *key* to its numeric *id*.
 *
 * The key travels in the `X-Vendor` header, but function endpoints are pathed by
 * the numeric id (`/v1/vendors/{vendorId}/functions`), which the server reads as an
 * integer. `GET /v1/vendors` returns both, so we map one to the other here.
 */
final class VendorResolver
{
    private const PAGE_SIZE = 200;

    public function __construct(private readonly LlmorClient $client)
    {
    }

    public function resolveId(?string $vendorKey): int
    {
        if (null === $vendorKey || '' === \trim($vendorKey)) {
            throw new SyncException('No vendor configured. Set LLMOR_VENDOR to your vendor key.');
        }

        $page = 0;
        $collected = 0;
        do {
            $response = $this->client->get('/v1/vendors', [
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'count' => 1,
            ]);

            $items = $response->data();
            foreach ($items as $vendor) {
                if (\is_array($vendor) && isset($vendor['key'], $vendor['id']) && (string) $vendor['key'] === $vendorKey) {
                    return (int) $vendor['id'];
                }
            }

            $collected += \count($items);
            $meta = $response->meta();
            $total = isset($meta['total_count']) ? (int) $meta['total_count'] : $collected;
            ++$page;
        } while ([] !== $items && $collected < $total);

        throw new SyncException(\sprintf('Vendor "%s" not found or not accessible by this user.', $vendorKey));
    }
}
