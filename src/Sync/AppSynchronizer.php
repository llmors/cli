<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\SubagentDefinition;
use stdClass;

/**
 * Reconciles one resolved app with its remote record.
 *
 * Two things about the API shape this: `PUT` is a *partial* update (only the keys we
 * send are touched), and `app_key` is create-only. So the manifest owns exactly the
 * fields it declares and nothing else — `embed_config`, `allowed_origins` and the
 * rest stay whatever the console set them to, and are never echoed back.
 *
 * On create the declared parameters go out with the `POST`: the server merges them
 * over the app type's defaults and seeds the model's runtime settings in the same
 * request, so there is no second call and no need to mirror those default tables here.
 * `[model]` is included on create too, which also side-steps the fact that changing a
 * model later does not re-seed its runtime knobs.
 */
final class AppSynchronizer
{
    /** @var array<string, ResolvedApp> declaration => resolution, for sub-agent targets */
    private array $peers = [];

    public function __construct(
        private readonly LlmorClient $client,
        private readonly int $vendorId,
        private readonly string $vendorKey,
        private readonly AppLockFile $lock,
        private readonly ModelResolver $models,
        private readonly FunctionIdResolver $functions,
    ) {
    }

    /**
     * Tell the synchronizer about every resolved app, so a sub-agent can name its
     * target by declaration. Includes apps excluded by `--app`: their ids are what a
     * selected app's sub-agents point at.
     *
     * @param array<string, ResolvedApp> $peers
     */
    public function setPeers(array $peers): void
    {
        $this->peers = $peers;
    }

    /**
     * @throws SyncException
     * @throws ApiException
     */
    public function sync(ResolvedApp $resolved, bool $dryRun = false): AppSyncResult
    {
        $app = $resolved->definition;
        $result = new AppSyncResult($app->declaration, $app->appKey);
        $result->origin = $resolved->origin;
        $result->parameterCount = $app->parameterCount();
        // How this app was identified is part of what happened to it, so resolution's
        // notes are reported against the same subject as everything else.
        $result->warnings = $resolved->warnings;

        $id = $resolved->id;
        if (null === $id) {
            return $this->create($resolved, $result, $dryRun);
        }

        $result->appId = $id;

        // Persist the binding however it was resolved. An adopted or `[id]`-pinned app
        // that isn't recorded stays bound only while `[name]` keeps matching, which is
        // exactly the fragility the lock file exists to remove. A dry run's lock is
        // read-only, so there is nothing to guard here.
        $this->lock->record($this->vendorKey, $app->declaration, $id, $app->appKey);

        return $this->update($resolved, $id, $result, $dryRun);
    }

    /**
     * @throws SyncException
     */
    private function create(ResolvedApp $resolved, AppSyncResult $result, bool $dryRun): AppSyncResult
    {
        $app = $resolved->definition;
        $result->appAction = SyncOutcome::CREATED;

        // `parameters` is required on create, and `{}` is enough — the server fills in
        // the app type's defaults from there.
        $payload = ['app_key' => $app->appKey, 'parameters' => $app->parameters];

        if (null !== $app->name) {
            $payload['name'] = $app->name;
        }
        if (null !== $app->description) {
            $payload['description'] = $app->description;
        }
        if (null !== $app->model) {
            $payload['completion_vendor_model_id'] = $this->models->resolveId($app->model);
            $result->changedFields[] = 'model '.$app->model;
        }

        $links = $this->resolveLinks($app, $result);
        if (null !== $links) {
            $payload['functions'] = ['data' => $links];
            $result->functionsChanged = true;
        }

        if ($dryRun) {
            return $result;
        }

        $created = $this->client->post($this->appsPath(), $payload)->data();
        $id = Json::idOf($created['id'] ?? null);

        if (null === $id) {
            throw new SyncException(\sprintf('The API did not return an id for the created app "%s".', $app->declaration));
        }

        $result->appId = $id;

        // Record before anything else can fail: an app that exists remotely but not in
        // the lock file is orphaned, and deleting an app requires a super-admin.
        $this->lock->record($this->vendorKey, $app->declaration, $id, $app->appKey);

        return $result;
    }

    /**
     * @throws SyncException
     */
    private function update(ResolvedApp $resolved, int $id, AppSyncResult $result, bool $dryRun): AppSyncResult
    {
        $app = $resolved->definition;
        $remote = $this->fetch($id);
        $payload = [];

        if (null !== $app->name && Json::stringOf($remote['name'] ?? null) !== $app->name) {
            $payload['name'] = $app->name;
            $result->changedFields[] = 'name';
        }

        if (null !== $app->description && Json::stringOf($remote['description'] ?? null) !== $app->description) {
            $payload['description'] = $app->description;
            $result->changedFields[] = 'description';
        }

        if (null !== $app->model && !self::usesModel($remote, $app->model)) {
            $modelId = $this->models->resolveId($app->model);
            if (Json::idOf($remote['completion_vendor_model_id'] ?? null) !== $modelId) {
                $payload['completion_vendor_model_id'] = $modelId;
                $result->changedFields[] = 'model '.$app->model;
                // The server seeds a model's runtime defaults on create only, then
                // validates the stored knobs against whatever model is set now.
                $result->warnings[] = 'Changed [model] — the new model\'s runtime defaults are not re-seeded; check temperature and reasoning_effort.';
            }
        }

        $merged = ParameterMerger::merge($remote['parameters'] ?? null, $app->parameters);
        $normalizedRemote = ParameterMerger::bag($remote['parameters'] ?? null);

        if (!ParameterMerger::equals($normalizedRemote, $merged)) {
            $payload['parameters'] = $merged;
            $result->changedParameters = ParameterMerger::changedPaths($normalizedRemote, $merged);
        }

        $links = $this->resolveLinks($app, $result);
        if (null !== $links && !self::linksMatch($remote, $links)) {
            $payload['functions'] = ['data' => $links];
            $result->functionsChanged = true;
        }

        if ([] !== $payload) {
            $result->appAction = SyncOutcome::UPDATED;

            if (!$dryRun) {
                $this->client->put($this->appPath($id), $payload);
            }
        }

        return $result;
    }

    /**
     * The `functions.data` payload for a declared `[functions]` block, or null when the
     * manifest doesn't own this app's links.
     *
     * **Every referenced function must resolve, or nothing is sent.** The API replaces
     * the whole link table when the field is present, so shipping the subset that
     * happened to resolve would silently unlink a function the user never touched.
     *
     * @return ?list<array{id: int, config: stdClass}>
     *
     * @throws SyncException
     */
    private function resolveLinks(AppDefinition $app, AppSyncResult $result): ?array
    {
        if (!$app->ownsFunctions()) {
            return null;
        }

        $links = [];
        $missing = [];

        foreach ($app->functions ?? [] as $link) {
            $id = $this->functions->resolve($link->name);

            if (null === $id) {
                $missing[] = $link->name;
                continue;
            }

            $links[] = ['id' => $id, 'config' => $link->config];
            $result->linkedFunctions[] = $link->name;
        }

        if ([] !== $missing) {
            $result->linkedFunctions = [];

            throw new SyncException(\sprintf('App "%s" installs %s "%s", which %s not been synced yet. Nothing was changed — run `llmor sync` without a filter first.', $app->declaration, 1 === \count($missing) ? 'function' : 'functions', \implode('", "', $missing), 1 === \count($missing) ? 'has' : 'have'));
        }

        return $links;
    }

    /**
     * Whether the app's installed functions already match what the manifest declares.
     *
     * @param array<string, mixed>                   $remote
     * @param list<array{id: int, config: stdClass}> $links
     */
    private static function linksMatch(array $remote, array $links): bool
    {
        $current = [];
        foreach (Json::listOf(Json::mapOf($remote['functions'] ?? null)['data'] ?? null) as $function) {
            $record = Json::mapOf($function);
            $id = Json::idOf($record['id'] ?? null);
            if (null !== $id) {
                $current[$id] = ParameterMerger::normalize($record['config'] ?? null);
            }
        }

        if (\count($current) !== \count($links)) {
            return false;
        }

        foreach ($links as $link) {
            if (!\array_key_exists($link['id'], $current)) {
                return false;
            }

            // A function with no config comes back as null or {} interchangeably.
            if (!ParameterMerger::equals(self::configOrNull($current[$link['id']]), self::configOrNull($link['config']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Treat "no config" and "empty config" as the same thing, since the API returns
     * either depending on how the link was created.
     */
    private static function configOrNull(mixed $config): mixed
    {
        if ($config instanceof stdClass && [] === \get_object_vars($config)) {
            return null;
        }

        return [] === $config ? null : $config;
    }

    /**
     * Reconcile one app's sub-agents.
     *
     * This is a pass of its own, run once every app in the manifest has an id, because
     * a sub-agent's target may be declared *after* the app that delegates to it — and
     * whether your manifest happens to be in dependency order is not something you
     * should have to think about.
     *
     * @throws SyncException
     * @throws ApiException
     */
    public function reconcileSubagents(AppSyncResult $result, ResolvedApp $resolved, bool $dryRun): void
    {
        $app = $resolved->definition;

        if (!$app->ownsSubagents()) {
            return;
        }

        $id = $result->appId;

        if ($dryRun || null === $id) {
            $this->planSubagents($app, $result);

            return;
        }

        $this->syncSubagents($app, $id, $result);

        if (SyncOutcome::UNCHANGED === $result->appAction && $this->subagentsChanged($result)) {
            $result->appAction = SyncOutcome::UPDATED;
        }
    }

    /**
     * Reconcile the app's sub-agents: create the missing ones, update the changed ones,
     * and warn about any the manifest no longer declares.
     *
     * Every field is sent on every write. The API requires all of them and stores the
     * text fields as empty strings, so the manifest owns them outright — dropping
     * `[tool_description]` really does clear it.
     *
     * @throws SyncException
     */
    private function syncSubagents(AppDefinition $app, int $appId, AppSyncResult $result): void
    {
        $remote = [];
        foreach (PagedList::fetchAll($this->client, $this->subagentsPath($appId)) as $record) {
            $alias = Json::stringOf($record['alias'] ?? null);
            if ('' !== $alias) {
                $remote[$alias] = $record;
            }
        }

        foreach ($app->subagents ?? [] as $subagent) {
            $targetId = $this->targetId($app, $subagent);
            $payload = self::subagentPayload($subagent, $targetId);
            $existing = $remote[$subagent->alias] ?? null;
            unset($remote[$subagent->alias]);

            if (null === $existing) {
                $this->client->post($this->subagentsPath($appId), $payload);
                $result->subagents[] = $this->change($subagent, SubagentChange::CREATED, $targetId);
                continue;
            }

            $id = Json::idOf($existing['id'] ?? null);
            if (null !== $id && self::subagentChanged($existing, $payload)) {
                $this->client->put($this->subagentPath($appId, $id), $payload);
                $result->subagents[] = $this->change($subagent, SubagentChange::UPDATED, $targetId);
                continue;
            }

            $result->subagents[] = $this->change($subagent, SubagentChange::UNCHANGED, $targetId);
        }

        // Deleting is deliberately not automatic: the same caution the file sync
        // applies to orphaned files, for a binding that is harder to notice.
        foreach (\array_keys($remote) as $alias) {
            $result->warnings[] = \sprintf('Sub-agent "%s" exists remotely but is not declared — remove it in the console.', $alias);
        }
    }

    /**
     * Report what the sub-agents *would* do, for a dry run.
     *
     * A target that doesn't exist yet is reported as pending rather than as an error:
     * a dry run on a fresh project is exactly when a clean report matters most.
     */
    private function planSubagents(AppDefinition $app, AppSyncResult $result): void
    {
        foreach ($app->subagents ?? [] as $subagent) {
            $targetId = $subagent->targetId ?? ($this->peers[$subagent->target] ?? null)?->id;
            $result->subagents[] = $this->change(
                $subagent,
                null === $targetId ? SubagentChange::PENDING : SubagentChange::CREATED,
                $targetId,
            );
        }
    }

    /**
     * The numeric id of a sub-agent's target app.
     *
     * @throws SyncException when the target hasn't been created yet
     */
    private function targetId(AppDefinition $app, SubagentDefinition $subagent): int
    {
        if (null !== $subagent->targetId) {
            return $subagent->targetId;
        }

        $peer = $this->peers[$subagent->target] ?? null;
        $id = $peer?->id;

        if (null === $id) {
            throw new SyncException(\sprintf('App "%s" delegates to "%s" as "%s", but "%s" has not been synced yet — run `llmor sync` without --app, or sync "%s" first.', $app->declaration, $subagent->target, $subagent->alias, $subagent->target, $subagent->target));
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private static function subagentPayload(SubagentDefinition $subagent, int $targetId): array
    {
        return [
            'alias' => $subagent->alias,
            'target_vendor_app_id' => $targetId,
            'description' => $subagent->description,
            'expose_as_tool' => $subagent->exposeAsTool,
            'tool_name' => $subagent->toolName,
            'tool_description' => $subagent->toolDescription,
            'input_description' => $subagent->inputDescription,
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $payload
     */
    private static function subagentChanged(array $existing, array $payload): bool
    {
        foreach ($payload as $field => $value) {
            $current = $existing[$field] ?? null;

            $same = match (true) {
                \is_bool($value) => (bool) $current === $value,
                \is_int($value) => Json::intOf($current) === $value,
                default => Json::stringOf($current) === $value,
            };

            if (!$same) {
                return true;
            }
        }

        return false;
    }

    private function change(SubagentDefinition $subagent, string $action, ?int $targetId): SubagentChange
    {
        return new SubagentChange(
            alias: $subagent->alias,
            action: $action,
            target: $subagent->target,
            targetId: $targetId,
            toolName: $subagent->exposeAsTool ? $subagent->effectiveToolName() : null,
        );
    }

    private function subagentsChanged(AppSyncResult $result): bool
    {
        foreach ($result->subagents as $change) {
            if (SubagentChange::UNCHANGED !== $change->action) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the app already uses a model of this name.
     *
     * Asking the record first is what keeps an unchanged run from listing every model
     * the vendor has: the resolved record carries the model's name, so `[model]` only
     * costs a lookup when it actually differs.
     *
     * @param array<string, mixed> $remote
     */
    private static function usesModel(array $remote, string $name): bool
    {
        $current = Json::stringOf(Json::mapOf(Json::mapOf($remote['completion_vendor_model'] ?? null)['data'] ?? null)['name'] ?? null);

        return '' !== $current && 0 === \strcasecmp($current, $name);
    }

    /**
     * The full record. The list endpoint cannot resolve an app's model or its function
     * links, so the fields we diff against have to come from a single-app read.
     *
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        return Json::mapOf($this->client->get($this->appPath($id), [
            'resolve' => 'functions,completionVendorModel',
        ])->data());
    }

    private function appsPath(): string
    {
        return \sprintf('/v1/vendors/%d/apps', $this->vendorId);
    }

    private function appPath(int $id): string
    {
        return $this->appsPath().'/'.$id;
    }

    private function subagentsPath(int $appId): string
    {
        return $this->appPath($appId).'/subagents';
    }

    private function subagentPath(int $appId, int $subagentId): string
    {
        return $this->subagentsPath($appId).'/'.$subagentId;
    }
}
