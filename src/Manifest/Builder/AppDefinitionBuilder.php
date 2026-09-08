<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Builder;

use ClanCats\SchemaScript\Node\BaseNode;
use ClanCats\SchemaScript\Node\MetadataBlockNode;
use ClanCats\SchemaScript\Node\MetadataEntryNode;
use ClanCats\SchemaScript\Node\ModelDefinitionNode;
use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\AppLimits;
use Llmor\Cli\Manifest\FileValueLoader;
use Llmor\Cli\Manifest\FunctionLink;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\MetadataValueReader;
use Llmor\Cli\Manifest\NamedEntries;
use Llmor\Cli\Manifest\ParameterTree;
use Llmor\Cli\Manifest\SubagentDefinition;
use stdClass;

/**
 * Builds one {@see AppDefinition} from a `name: App { … }` declaration.
 */
final class AppDefinitionBuilder
{
    /** Structured metadata handled separately from the plain scalar keys. */
    private const BLOCK_KEYS = ['parameters', 'functions', 'subagents'];

    /** A bare positive integer — an `[id]` pin, or a `[app]` that addresses an app directly. */
    private const POSITIVE_INT_PATTERN = '/^[1-9][0-9]*$/';

    /**
     * The app types the server ships today. Used only to phrase a better local error;
     * an unknown key is still sent, so a newly released app type doesn't need a CLI
     * release to become usable.
     *
     * @var list<string>
     */
    private const KNOWN_APP_KEYS = [
        'llmor/generic',
        'llmor/generic_embedded',
        'llmor/oneshot',
        'llmor/autopilot',
        'llmor/silicon',
    ];

    /**
     * @throws ManifestException
     */
    public function build(ModelDefinitionNode $model, DeclarationContext $ctx): AppDefinition
    {
        $ctx->assertValidKey();

        /** @var array<string, string> $meta */
        $meta = [];
        /** @var array<string, MetadataEntryNode> $blocks */
        $blocks = [];

        foreach ($model->getMetadata() as $entry) {
            $key = $entry->getKey();

            if (\in_array($key, self::BLOCK_KEYS, true)) {
                $blocks[$key] = $entry;
                continue;
            }

            // A scalar key may take its value from a file too — handy for [description].
            $fromFile = FileValueLoader::fromEntry($entry, $ctx, \sprintf('[%s]', $key));
            if (null !== $fromFile) {
                $meta[$key] = $fromFile;
                continue;
            }

            $value = MetadataValueReader::asString($entry->getValue());
            if (null !== $value) {
                $meta[$key] = $value;
            }
        }

        $appKey = $ctx->require($meta, 'app_key');
        if (!\in_array($appKey, self::KNOWN_APP_KEYS, true) && !\str_contains($appKey, '/')) {
            throw $ctx->invalid(\sprintf('[app_key] "%s" is not an app type — expected one of %s', $appKey, \implode(', ', self::KNOWN_APP_KEYS)));
        }

        $name = $this->optional($meta, 'name');
        if (null !== $name && (\mb_strlen($name) < AppLimits::MIN_NAME_LENGTH || \mb_strlen($name) > AppLimits::MAX_NAME_LENGTH)) {
            throw $ctx->invalid(\sprintf('[name] must be between %d and %d characters', AppLimits::MIN_NAME_LENGTH, AppLimits::MAX_NAME_LENGTH));
        }

        $description = $this->optional($meta, 'description');
        if (null !== $description && \mb_strlen($description) > AppLimits::MAX_DESCRIPTION_LENGTH) {
            throw $ctx->invalid(\sprintf('[description] must be at most %d characters', AppLimits::MAX_DESCRIPTION_LENGTH));
        }

        return new AppDefinition(
            declaration: $ctx->key,
            appKey: $appKey,
            name: $name,
            description: $description,
            model: $this->optional($meta, 'model'),
            id: $this->optionalId($meta, $ctx),
            parameters: $this->buildParameters($blocks['parameters'] ?? null, $ctx),
            functions: $this->buildFunctions($blocks['functions'] ?? null, $ctx),
            subagents: $this->buildSubagents($blocks['subagents'] ?? null, $ctx),
        );
    }

    /**
     * Read `[parameters]` into a JSON-shaped map. A declaration with no `[parameters]`
     * block declares no overrides at all, which is a valid (and common) thing to do.
     *
     * @throws ManifestException
     */
    private function buildParameters(?MetadataEntryNode $entry, DeclarationContext $ctx): stdClass
    {
        if (null === $entry) {
            return new stdClass();
        }

        $value = $entry->getValue();
        if (null === $value) {
            return new stdClass();
        }

        if (!$value instanceof MetadataBlockNode) {
            throw $ctx->invalid('[parameters] must be a block of "key = value" entries');
        }

        $parameters = (new ParameterTree($ctx))->fromNode($value, '[parameters]');
        \assert($parameters instanceof stdClass);

        return $parameters;
    }

    /**
     * Read `[functions]` into the set of functions installed on this app.
     *
     * Returns null when the block is absent, which is not the same as an empty one:
     * the API replaces the whole link table whenever the field is sent, so a missing
     * block must leave the console's links untouched while `[functions] = { }`
     * deliberately unlinks everything.
     *
     * @return ?list<FunctionLink>
     *
     * @throws ManifestException
     */
    private function buildFunctions(?MetadataEntryNode $entry, DeclarationContext $ctx): ?array
    {
        if (null === $entry) {
            return null;
        }

        $tree = new ParameterTree($ctx);
        $links = [];

        foreach (NamedEntries::read($entry->getValue(), $ctx, '[functions]') as $name => $config) {
            if (1 !== \preg_match(DeclarationContext::KEY_PATTERN, $name)) {
                throw $ctx->invalid(\sprintf('[functions] → "%s" is not a valid function key', $name));
            }

            $links[] = new FunctionLink($name, $this->buildConfig($config, $ctx, $name));
        }

        return $links;
    }

    /**
     * A function's per-app config, validated server-side against the function's own
     * `config_schema`.
     *
     * @throws ManifestException
     */
    private function buildConfig(?BaseNode $node, DeclarationContext $ctx, string $name): stdClass
    {
        if (null === $node) {
            return new stdClass();
        }

        if (!$node instanceof MetadataBlockNode) {
            throw $ctx->invalid(\sprintf('[functions] → "%s" config must be a block of "key = value" entries', $name));
        }

        $config = (new ParameterTree($ctx))->fromNode($node, \sprintf('[functions] → %s', $name));
        \assert($config instanceof stdClass);

        return $config;
    }

    /**
     * Read `[subagents]` into the aliases this app can delegate to.
     *
     * @return ?list<SubagentDefinition>
     *
     * @throws ManifestException
     */
    private function buildSubagents(?MetadataEntryNode $entry, DeclarationContext $ctx): ?array
    {
        if (null === $entry) {
            return null;
        }

        $subagents = [];
        $toolNames = [];

        foreach (NamedEntries::read($entry->getValue(), $ctx, '[subagents]') as $alias => $settings) {
            $subagent = $this->buildSubagent($alias, $settings, $ctx);

            // The server derives `tool_name ?: alias` and rejects a collision between
            // siblings; catching it here names both aliases instead of one field.
            $tool = $subagent->effectiveToolName();
            if (isset($toolNames[$tool])) {
                throw $ctx->invalid(\sprintf('[subagents] → "%s" and "%s" both expose the tool name "%s"', $toolNames[$tool], $alias, $tool));
            }
            $toolNames[$tool] = $alias;

            $subagents[] = $subagent;
        }

        return $subagents;
    }

    /**
     * @throws ManifestException
     */
    private function buildSubagent(string $alias, ?BaseNode $settings, DeclarationContext $ctx): SubagentDefinition
    {
        $where = \sprintf('[subagents] → %s', $alias);

        if (1 !== \preg_match(AppLimits::ALIAS_PATTERN, $alias)) {
            throw $ctx->invalid(\sprintf('%s: an alias must be lowercase, start with a letter and be 2 to 32 characters', $where));
        }

        // `[subagents] = { triage }` is shorthand for "delegate to the app declared
        // under the same name, with defaults".
        $meta = [];
        if (null !== $settings) {
            if (!$settings instanceof MetadataBlockNode) {
                throw $ctx->invalid(\sprintf('%s must be a block of "[key] = value" entries', $where));
            }

            foreach ($settings->getEntries() as $item) {
                $fromFile = FileValueLoader::fromEntry($item, $ctx, \sprintf('%s → [%s]', $where, $item->getKey()));
                $value = $fromFile ?? MetadataValueReader::asString($item->getValue());
                if (null !== $value) {
                    $meta[$item->getKey()] = $value;
                }
            }
        }

        $target = $meta['app'] ?? $alias;
        $targetId = 1 === \preg_match(self::POSITIVE_INT_PATTERN, $target) ? (int) $target : null;

        $description = $meta['description'] ?? '';
        if (\mb_strlen($description) > AppLimits::MAX_SUBAGENT_DESCRIPTION) {
            throw $ctx->invalid(\sprintf('%s: [description] must be at most %d characters', $where, AppLimits::MAX_SUBAGENT_DESCRIPTION));
        }

        $toolName = $meta['tool_name'] ?? '';
        if ('' !== $toolName && 1 !== \preg_match(AppLimits::TOOL_NAME_PATTERN, $toolName)) {
            throw $ctx->invalid(\sprintf('%s: [tool_name] may use letters, digits, "_" and "-", up to 64 characters', $where));
        }

        $toolDescription = $meta['tool_description'] ?? '';
        $inputDescription = $meta['input_description'] ?? '';
        foreach (['tool_description' => $toolDescription, 'input_description' => $inputDescription] as $field => $value) {
            if (\mb_strlen($value) > AppLimits::MAX_TOOL_DESCRIPTION) {
                throw $ctx->invalid(\sprintf('%s: [%s] must be at most %d characters', $where, $field, AppLimits::MAX_TOOL_DESCRIPTION));
            }
        }

        return new SubagentDefinition(
            alias: $alias,
            target: $target,
            targetId: $targetId,
            description: $description,
            exposeAsTool: 'true' === ($meta['expose_as_tool'] ?? 'false'),
            toolName: $toolName,
            toolDescription: $toolDescription,
            inputDescription: $inputDescription,
        );
    }

    /**
     * @param array<string, string> $meta
     */
    private function optional(array $meta, string $field): ?string
    {
        $value = $meta[$field] ?? '';

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, string> $meta
     *
     * @throws ManifestException
     */
    private function optionalId(array $meta, DeclarationContext $ctx): ?int
    {
        $value = $this->optional($meta, 'id');
        if (null === $value) {
            return null;
        }

        if (1 !== \preg_match(self::POSITIVE_INT_PATTERN, $value)) {
            throw $ctx->invalid('[id] must be a positive integer (the app id shown in the console)');
        }

        return (int) $value;
    }
}
