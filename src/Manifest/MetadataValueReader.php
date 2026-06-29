<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use ClanCats\SchemaScript\Node\BaseNode;
use ClanCats\SchemaScript\Node\ValueNode;

/**
 * Converts SchemaScript AST value nodes into plain PHP scalars.
 *
 * We parse the manifest at the AST level (no {@see \ClanCats\SchemaScript\Schema\SchemaEvaluator}),
 * so metadata values arrive as {@see ValueNode}s rather than evaluated PHP. Function
 * metadata is always scalar (strings, the occasional number/bool), so this small reader
 * is all we need and it avoids depending on an evaluation context.
 */
final class MetadataValueReader
{
    /**
     * Return the scalar string value of a metadata node, or null when the node is
     * absent or not a scalar value (e.g. a nested object/list).
     */
    public static function asString(?BaseNode $node): ?string
    {
        if (!$node instanceof ValueNode) {
            return null;
        }

        $value = $node->getValue();

        return match (true) {
            \is_string($value) => $value,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value), \is_float($value) => (string) $value,
            default => null,
        };
    }
}
