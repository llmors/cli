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
    public function __construct(private readonly LlmorClient $client)
    {
    }

    public function resolveId(?string $vendorKey): int
    {
        if (null === $vendorKey || '' === \trim($vendorKey)) {
            throw new SyncException('No vendor configured. Set LLMOR_VENDOR to your vendor key.');
        }

        foreach (PagedList::fetchAll($this->client, '/v1/vendors') as $vendor) {
            $id = Json::idOf($vendor['id'] ?? null);
            if (null !== $id && Json::stringOf($vendor['key'] ?? null) === $vendorKey) {
                return $id;
            }
        }

        throw new SyncException(\sprintf('Vendor "%s" not found or not accessible by this user.', $vendorKey));
    }
}
