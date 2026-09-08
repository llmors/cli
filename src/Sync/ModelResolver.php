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
    private const TYPE = 'chat_completion';

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
     * @return list<array<string, mixed>>
     */
    private function models(): array
    {
        return $this->models ??= PagedList::fetchAll(
            $this->client,
            \sprintf('/v1/vendors/%d/models', $this->vendorId),
            ['type' => self::TYPE],
        );
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
