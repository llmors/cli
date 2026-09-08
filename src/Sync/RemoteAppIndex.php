<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use Llmor\Cli\Client\LlmorClient;

/**
 * Every app a vendor has, fetched once per run.
 *
 * One paged listing answers all three questions the resolver asks — does this locked
 * id still exist, is there an app to adopt, and which ids are already taken — so no
 * amount of declared apps turns into a per-app round trip. Soft-deleted apps are
 * excluded server-side, so "absent from the index" means "gone".
 */
final class RemoteAppIndex
{
    /** @var array<int, array<string, mixed>> */
    private array $byId = [];

    /**
     * @param list<array<string, mixed>> $records
     */
    public function __construct(array $records)
    {
        foreach ($records as $record) {
            $id = Json::idOf($record['id'] ?? null);
            if (null !== $id) {
                $this->byId[$id] = $record;
            }
        }
    }

    public static function fetch(LlmorClient $client, int $vendorId): self
    {
        return new self(PagedList::fetchAll($client, \sprintf('/v1/vendors/%d/apps', $vendorId)));
    }

    /**
     * Every indexed app, in id order.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return \array_values($this->byId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byId(int $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * Apps matching a name and app type exactly.
     *
     * The server's `?search=` is a LIKE across name *or* `app_key` (its spelling of
     * the app type), so it over-matches badly in a vendor with many apps of one type;
     * the exact filtering happens here.
     * A record with no usable id could never be adopted anyway, so the id index is the
     * whole candidate pool.
     *
     * @return list<array<string, mixed>>
     */
    public function matching(string $name, string $appType): array
    {
        $matches = [];
        foreach ($this->byId as $record) {
            if (self::describes($record, $name, $appType)) {
                $matches[] = $record;
            }
        }

        return $matches;
    }

    /**
     * Would a declaration with this `[name]` and `[app_type]` adopt this remote record?
     *
     * The single expression of the adoption rule. `apps:import` warns by *predicting*
     * what `sync` would adopt, and a prediction that restates the rule in its own words
     * silently stops matching the moment a criterion is added to one of them. A
     * declaration with no `[name]` has nothing to match on and never adopts.
     *
     * @param array<string, mixed> $record
     */
    public static function describes(array $record, ?string $name, string $appType): bool
    {
        return null !== $name
            && Json::stringOf($record['name'] ?? null) === $name
            && Json::stringOf($record['app_key'] ?? null) === $appType;
    }
}
