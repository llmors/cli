<?php

declare(strict_types=1);

namespace Llmor\Cli\Import;

use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\AppLimits;
use Llmor\Cli\Manifest\Builder\DeclarationContext;
use Llmor\Cli\Manifest\FunctionLink;
use Llmor\Cli\Manifest\SubagentDefinition;
use Llmor\Cli\Sync\Json;
use Llmor\Cli\Sync\ModelResolver;
use Llmor\Cli\Sync\ParameterMerger;
use Llmor\Cli\Sync\SyncException;

/**
 * Turns a remote app record into the {@see AppDefinition} a manifest would parse.
 *
 * Pure — arrays in, value object out — so the whole mapping is testable without HTTP.
 *
 * It reads an **allow-list** of fields rather than skipping a deny-list, so a field the
 * API grows next month is left alone instead of being guessed at. Everything it cannot
 * express is reported rather than approximated: the manifest is about to become the
 * source of truth for this app, and a declaration that quietly disagrees with the
 * server is worse than one that admits what it does not own.
 */
final class RemoteAppMapper
{
    /**
     * Fields the console owns. `sync` never sends them
     * ({@see \Llmor\Cli\Sync\AppSynchronizer} only writes what the manifest declares)
     * and there is no manifest syntax for them, so they are reported, not imported.
     */
    private const CONSOLE_MANAGED = ['embed_config', 'allowed_origins', 'conversation_expire_after'];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly SubagentTargetNamer $targets,
        private readonly ?ModelResolver $models = null,
    ) {
    }

    /**
     * @param array<string, mixed>       $record    a `?resolve=functions,completionVendorModel` read
     * @param list<array<string, mixed>> $subagents the app's sub-agent records
     *
     * @throws ImportException when the record is not an app this CLI can describe
     */
    public function toDefinition(string $declaration, int $appId, array $record, array $subagents): MappedApp
    {
        $this->warnings = [];

        $appType = Json::stringOf($record['app_key'] ?? null);
        if ('' === $appType) {
            throw new ImportException(\sprintf('App #%d has no app type, so there is nothing to declare.', $appId));
        }

        $definition = new AppDefinition(
            declaration: $declaration,
            appType: $appType,
            name: $this->name($record),
            description: $this->description($record),
            model: $this->model($record),
            id: null,
            parameters: ParameterMerger::bag($record['parameters'] ?? null),
            functions: $this->functions($record),
            subagents: $this->subagents($subagents, $appId),
        );

        return new MappedApp($definition, $this->warnings, self::consoleManaged($record));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function name(array $record): ?string
    {
        $name = Json::stringOf($record['name'] ?? null);
        if ('' === $name) {
            return null;
        }

        $length = \mb_strlen($name);
        if ($length < AppLimits::MIN_NAME_LENGTH || $length > AppLimits::MAX_NAME_LENGTH) {
            $this->warnings[] = \sprintf('[name] "%s" is outside the %d–%d character range the API accepts, so it is left out — the app keeps the name it has.', $name, AppLimits::MIN_NAME_LENGTH, AppLimits::MAX_NAME_LENGTH);

            return null;
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function description(array $record): ?string
    {
        $description = Json::stringOf($record['description'] ?? null);
        if ('' === $description) {
            return null;
        }

        if (\mb_strlen($description) > AppLimits::MAX_DESCRIPTION_LENGTH) {
            $this->warnings[] = \sprintf('[description] is longer than %d characters, so it is left out — the app keeps the description it has.', AppLimits::MAX_DESCRIPTION_LENGTH);

            return null;
        }

        return $description;
    }

    /**
     * The model's *name*, never its id: a numeric id is unreadable and does not survive
     * being pointed at a second vendor.
     *
     * @param array<string, mixed> $record
     */
    private function model(array $record): ?string
    {
        $resolved = Json::mapOf(Json::mapOf($record['completion_vendor_model'] ?? null)['data'] ?? null);
        $name = Json::stringOf($resolved['name'] ?? null);

        if ('' === $name) {
            $id = Json::idOf($record['completion_vendor_model_id'] ?? null);
            $name = null !== $id ? (string) $this->models?->nameOf($id) : '';
        }

        if ('' === $name) {
            return null;
        }

        // A name that matches two models would fail the next sync outright. Saying so
        // now is far kinder than letting it surface as a sync error later.
        try {
            $this->models?->resolveId($name);
        } catch (SyncException $e) {
            $this->warnings[] = \sprintf('[model] is kept as "%s", but %s', $name, \lcfirst($e->getMessage()));
        }

        return $name;
    }

    /**
     * The app's installed functions, or null to leave the console's links alone.
     *
     * **All or nothing.** The API replaces the whole link table whenever `functions` is
     * present, so a block missing one entry would silently unlink it — the same reason
     * {@see \Llmor\Cli\Sync\AppSynchronizer::resolveLinks()} refuses to send a subset.
     *
     * @param array<string, mixed> $record
     *
     * @return ?list<FunctionLink>
     */
    private function functions(array $record): ?array
    {
        if (!\array_key_exists('functions', $record)) {
            return null;
        }

        $links = [];
        foreach (Json::listOf(Json::mapOf($record['functions'] ?? null)['data'] ?? null) as $entry) {
            $function = Json::mapOf($entry);
            $key = Json::stringOf($function['function_key'] ?? null);

            if (1 !== \preg_match(DeclarationContext::KEY_PATTERN, $key)) {
                $this->warnings[] = \sprintf('An installed function has no usable key%s, so [functions] is left out entirely — the console keeps every link. Fix the key in the console and import again.', '' === $key ? '' : \sprintf(' ("%s")', $key));

                return null;
            }

            $links[] = new FunctionLink($key, ParameterMerger::bag($function['config'] ?? null));
        }

        // An empty block would mean "unlink everything", which is a claim of ownership
        // over links this app never had. Absent means "leave them alone".
        return [] === $links ? null : $links;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return ?list<SubagentDefinition>
     */
    private function subagents(array $records, int $appId): ?array
    {
        $subagents = [];

        foreach ($records as $record) {
            $subagent = $this->subagent($record, $appId);
            if (null !== $subagent) {
                $subagents[] = $subagent;
            }
        }

        return [] === $subagents ? null : $subagents;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function subagent(array $record, int $appId): ?SubagentDefinition
    {
        $alias = Json::stringOf($record['alias'] ?? null);
        if (1 !== \preg_match(AppLimits::ALIAS_PATTERN, $alias)) {
            $this->warnings[] = \sprintf('Sub-agent "%s" is left out: an alias must be lowercase, start with a letter and be 2 to 32 characters, so the manifest cannot name it.', $alias);

            return null;
        }

        $targetId = Json::idOf($record['target_vendor_app_id'] ?? null);
        if (null === $targetId) {
            $this->warnings[] = \sprintf('Sub-agent "%s" is left out: it has no target app.', $alias);

            return null;
        }

        $target = $this->declarationFor($targetId, $appId);
        if (null === $target) {
            $this->warnings[] = \sprintf('Sub-agent "%s" targets app #%d, which this manifest does not declare — kept as a numeric id. Run `llmor apps:import %d` to bring it in too.', $alias, $targetId, $targetId);
        }

        $toolName = Json::stringOf($record['tool_name'] ?? null);
        if ('' !== $toolName && 1 !== \preg_match(AppLimits::TOOL_NAME_PATTERN, $toolName)) {
            $this->warnings[] = \sprintf('Sub-agent "%s" keeps its tool name in the console: "%s" uses characters a manifest cannot write.', $alias, $toolName);
            $toolName = '';
        }

        return new SubagentDefinition(
            alias: $alias,
            target: $target ?? (string) $targetId,
            targetId: null === $target ? $targetId : null,
            description: Json::stringOf($record['description'] ?? null),
            exposeAsTool: self::boolOf($record['expose_as_tool'] ?? null),
            toolName: $toolName,
            toolDescription: Json::stringOf($record['tool_description'] ?? null),
            inputDescription: Json::stringOf($record['input_description'] ?? null),
        );
    }

    /**
     * The declaration name for a sub-agent target, or null when the numeric form has to
     * be used.
     *
     * An app delegating to *itself* takes the numeric form even though its name is
     * known: {@see \Llmor\Cli\Manifest\ManifestParser} rejects self-delegation by
     * name, so a named self-reference would produce a manifest that will not parse.
     */
    private function declarationFor(int $targetId, int $appId): ?string
    {
        return $targetId === $appId ? null : $this->targets->nameOf($targetId);
    }

    /** A JSON boolean that may have travelled as `1`, `"1"` or `"true"`. */
    private static function boolOf(mixed $value): bool
    {
        return \is_bool($value) ? $value : \in_array($value, [1, '1', 'true'], true);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    private static function consoleManaged(array $record): array
    {
        $present = [];
        foreach (self::CONSOLE_MANAGED as $field) {
            $value = $record[$field] ?? null;
            if (null !== $value && [] !== $value && '' !== $value) {
                $present[] = $field;
            }
        }

        return $present;
    }
}
