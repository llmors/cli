<?php

declare(strict_types=1);

namespace Llmor\Cli\Import;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Sync\Json;
use Llmor\Cli\Sync\PagedList;

/**
 * Reads everything about one remote app that a manifest can express.
 *
 * Two requests, for the same reason {@see \Llmor\Cli\Sync\AppSynchronizer} makes them:
 * the single-app read is the only endpoint that resolves the completion model and the
 * installed functions (the list endpoint's resolvers are `['creator']` only), and
 * sub-agents live on a sub-resource of their own.
 *
 * Both reads are memoised, because the command asks for the record twice — once to
 * derive a declaration name, once to build the declaration — and one import should not
 * cost two round trips for the same answer.
 */
final class RemoteAppReader
{
    private const RESOLVE = 'functions,completionVendorModel';

    /** @var array<int, array<string, mixed>> */
    private array $records = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $subagents = [];

    public function __construct(
        private readonly LlmorClient $client,
        private readonly int $vendorId,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function record(int $appId): array
    {
        return $this->records[$appId] ??= Json::mapOf(
            $this->client->get($this->appPath($appId), ['resolve' => self::RESOLVE])->data(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ApiException
     */
    public function subagents(int $appId): array
    {
        return $this->subagents[$appId] ??= PagedList::fetchAll($this->client, $this->appPath($appId).'/subagents');
    }

    private function appPath(int $appId): string
    {
        return \sprintf('/v1/vendors/%d/apps/%d', $this->vendorId, $appId);
    }
}
