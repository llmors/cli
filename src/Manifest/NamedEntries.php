<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use ClanCats\SchemaScript\Node\BaseNode;
use ClanCats\SchemaScript\Node\MetadataBlockNode;
use ClanCats\SchemaScript\Node\MetadataListNode;
use ClanCats\SchemaScript\Node\ValueNode;
use Llmor\Cli\Manifest\Builder\DeclarationContext;

/**
 * Reads a "named things, each with optional settings" block into `name => settings`.
 *
 * SchemaScript writes lists and objects with the same braces, and which one you get
 * depends on how many items there are and whether they're quoted:
 *
 *     [functions] = { }                        → an empty object
 *     [functions] = { weather }                → an object with one valueless entry
 *     [functions] = { weather, greeter }       → a list of two identifiers
 *     [functions] = { [weather] = { … } }      → an object of objects
 *
 * No one can be expected to predict that, so all four shapes — plus a bare
 * `[functions] = weather` — are accepted and normalised here. Entries keep their
 * nodes rather than their values so annotations (`@file`) still work downstream.
 */
final class NamedEntries
{
    /**
     * @return array<string, ?BaseNode> name => settings node (null when none were given)
     *
     * @throws ManifestException
     */
    public static function read(?BaseNode $node, DeclarationContext $ctx, string $field): array
    {
        if (null === $node) {
            return [];
        }

        $entries = [];

        if ($node instanceof MetadataBlockNode) {
            foreach ($node->getEntries() as $entry) {
                self::add($entries, $entry->getKey(), $entry->getValue(), $ctx, $field);
            }

            return $entries;
        }

        if ($node instanceof MetadataListNode) {
            foreach ($node->getItems() as $item) {
                self::add($entries, self::nameOf($item, $ctx, $field), null, $ctx, $field);
            }

            return $entries;
        }

        self::add($entries, self::nameOf($node, $ctx, $field), null, $ctx, $field);

        return $entries;
    }

    /**
     * @param array<string, ?BaseNode> &$entries
     *
     * @throws ManifestException
     */
    private static function add(array &$entries, string $name, ?BaseNode $settings, DeclarationContext $ctx, string $field): void
    {
        if ('' === $name) {
            throw $ctx->invalid(\sprintf('%s contains an entry with no name', $field));
        }

        // The parser keeps duplicate keys as separate entries, so silently dropping
        // one would quietly ignore whichever the user wrote second.
        if (\array_key_exists($name, $entries)) {
            throw $ctx->invalid(\sprintf('%s lists "%s" more than once', $field, $name));
        }

        $entries[$name] = $settings;
    }

    /**
     * @throws ManifestException
     */
    private static function nameOf(BaseNode $node, DeclarationContext $ctx, string $field): string
    {
        if ($node instanceof ValueNode) {
            $name = MetadataValueReader::asString($node);
            if (null !== $name && '' !== $name) {
                return $name;
            }
        }

        throw $ctx->invalid(\sprintf('%s must be a list of names or a block of "[name] = { … }" entries', $field));
    }
}
