<?php

declare(strict_types=1);

namespace Llmor\Cli\Command;

use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Sync\VendorResolver;

/**
 * Shared wiring for the vendor-scoped commands: the client, the configured vendor
 * key and the numeric id the `{vendorId}` path segments want.
 *
 * The two vendor identifiers are not interchangeable — the key travels as the
 * `X-Vendor` header, the id in the path — so every command that addresses a vendor
 * resource resolves one from the other exactly once, here.
 */
abstract class AbstractVendorCommand extends AbstractCommand
{
    public function __construct(
        protected readonly LlmorClient $client,
        protected readonly ?string $vendorKey,
    ) {
        parent::__construct();
    }

    protected function resolveVendorId(): int
    {
        return (new VendorResolver($this->client))->resolveId($this->vendorKey);
    }

    /**
     * The vendor key as the lock file and the resolvers want it — never null.
     *
     * They key their sections by it, so "no vendor configured" has to settle on one
     * spelling; leaving each call site to coalesce means one forgotten `?? ''` writes an
     * entry under a different key than the next run reads.
     */
    protected function vendorKey(): string
    {
        return $this->vendorKey ?? '';
    }
}
