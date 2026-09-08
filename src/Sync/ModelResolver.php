<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use Llmor\Cli\Client\LlmorClient;

/**
 * Resolves a completion model *name* to the numeric id an app record needs.
 *
 * The API binds `completion_vendor_model_id` and offers no name-based lookup, but a
 * manifest that hardcoded a numeric id would be unreadable and wouldn't survive being
 * pointed at a second vendor. Model names aren't unique, so an ambiguous name is an
 * error rather than a guess.
 *
 * The listing is fetched at most once per run, and only when a manifest actually
 * declares `[model]`.
 */
final class ModelResolver
{
    /** The model type an app's `[model]` is resolved against. */
    public const TYPE = 'chat_completion';

    /** @var list<array<string, mixed>>|null */
    private ?array $models = null;

    public function __construct(
        private readonly LlmorClient $client,
        private readonly int $vendorId,
    ) {
    }

    /**
     * @throws SyncException when the name matches no model, or more than one
     */
    public function resolveId(string $name): int
    {
        $exact = [];
        $insensitive = [];

        foreach ($this->models() as $model) {
            $id = Json::idOf($model['id'] ?? null);
            if (null === $id) {
                continue;
            }

            $candidate = Json::stringOf($model['name'] ?? null);
            if ($candidate === $name) {
                $exact[$id] = $candidate;
            } elseif (0 === \strcasecmp($candidate, $name)) {
                $insensitive[$id] = $candidate;
            }
        }

        $matches = [] !== $exact ? $exact : $insensitive;

        if ([] === $matches) {
            throw new SyncException(\sprintf('[model] "%s" is not a completion model of this vendor. Available: %s.', $name, $this->available()));
        }

        if (\count($matches) > 1) {
            throw new SyncException(\sprintf('[model] "%s" matches %d models (#%s) — model names are not unique, so rename one in the console.', $name, \count($matches), \implode(', #', \array_keys($matches))));
        }

        return (int) \array_key_first($matches);
    }

    /**
     * The name of a model by id, or null when this vendor has no such model.
     *
     * The reverse of {@see resolveId()}, for reading a record back out: an app carries
     * `completion_vendor_model_id`, but a manifest wants the name.
     */
    public function nameOf(int $id): ?string
    {
        foreach ($this->models() as $model) {
            if (Json::idOf($model['id'] ?? null) === $id) {
                $name = Json::stringOf($model['name'] ?? null);

                return '' === $name ? null : $name;
            }
        }

        return null;
    }

    /**
     * Read the vendor's models straight from the endpoint.
     *
     * The one place that knows where models live and how they are paged, so a command
     * that wants to *show* the catalogue reads the same list a manifest resolves
     * against — pass a null $type for every type.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $type = self::TYPE, ?string $search = null): array
    {
        return PagedList::fetchAll(
            $this->client,
            \sprintf('/v1/vendors/%d/models', $this->vendorId),
            [
                'type' => $type,
                'search' => $search,
                // The endpoint applies no ORDER BY of its own, so without this the
                // order — and with it the paging — is whatever the database felt like.
                'order' => 'name',
                'order_dir' => 'asc',
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function models(): array
    {
        return $this->models ??= $this->list();
    }

    private function available(): string
    {
        $names = [];
        foreach ($this->models() as $model) {
            $name = Json::stringOf($model['name'] ?? null);
            if ('' !== $name) {
                $names[$name] = true;
            }
        }

        if ([] === $names) {
            return 'none — install a completion model for this vendor first';
        }

        $listed = \array_slice(\array_keys($names), 0, 10);
        $suffix = \count($names) > 10 ? ', …' : '';

        return '"'.\implode('", "', $listed).'"'.$suffix;
    }
}
