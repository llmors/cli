<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use ClanCats\SchemaScript\Node\BaseNode;
use ClanCats\SchemaScript\Node\MetadataBlockNode;
use ClanCats\SchemaScript\Node\MetadataEntryNode;
use ClanCats\SchemaScript\Node\MetadataListNode;
use ClanCats\SchemaScript\Node\ReferenceNode;
use ClanCats\SchemaScript\Node\ValueNode;
use Llmor\Cli\Manifest\Builder\DeclarationContext;
use stdClass;

/**
 * Converts a metadata value node into the JSON-shaped PHP value the API expects.
 *
 * App `parameters` is an open-ended JSON bag, so unlike function metadata it has to
 * keep real types: `temperature = 0.2` must stay a float and `enable_ask_user = true`
 * a bool, or the server rejects them.
 *
 * Maps become {@see stdClass} and lists become PHP lists, decided by the node type
 * rather than by inspecting keys. That distinction is the whole reason this class
 * exists: SchemaScript writes both as `{ … }`, and `json_encode([])` is `[]`, so an
 * empty object would otherwise reach the API as an empty array.
 *
 * @phpstan-type ParamValue scalar|null|stdClass|list<mixed>
 */
final class ParameterTree
{
    public function __construct(private readonly DeclarationContext $ctx)
    {
    }

    /**
     * Convert one entry, honouring its `@file` annotation.
     *
     * @param string $path dotted path of the entry, for error messages
     *
     * @return ParamValue
     *
     * @throws ManifestException
     */
    public function fromEntry(MetadataEntryNode $entry, string $path): mixed
    {
        $fromFile = FileValueLoader::fromEntry($entry, $this->ctx, $path);
        if (null !== $fromFile) {
            return $fromFile;
        }

        $value = $entry->getValue();
        if (null === $value) {
            // A bare key parses as a null-valued entry, which is almost always a
            // forgotten value rather than an intentional JSON null.
            throw $this->ctx->invalid(\sprintf('%s has no value — write "%s = true" (or `null` for an explicit JSON null)', $path, $entry->getKey()));
        }

        return $this->fromNode($value, $path);
    }

    /**
     * @return ParamValue
     *
     * @throws ManifestException
     */
    public function fromNode(BaseNode $node, string $path): mixed
    {
        return match (true) {
            $node instanceof MetadataBlockNode => $this->fromBlock($node, $path),
            $node instanceof MetadataListNode => $this->fromList($node, $path),
            $node instanceof ValueNode => $this->fromValue($node, $path),
            $node instanceof ReferenceNode => throw $this->ctx->invalid(\sprintf('%s: "%s" looks like a namespace reference, which is not resolved in this context — use a quoted string', $path, \implode('::', $node->getParts()))),
            default => throw $this->ctx->invalid(\sprintf('%s has an unsupported value', $path)),
        };
    }

    /**
     * @throws ManifestException
     */
    private function fromBlock(MetadataBlockNode $node, string $path): stdClass
    {
        $map = new stdClass();
        foreach ($node->getEntries() as $entry) {
            $key = $entry->getKey();
            $map->{$key} = $this->fromEntry($entry, $path.' → '.$key);
        }

        return $map;
    }

    /**
     * @return list<mixed>
     *
     * @throws ManifestException
     */
    private function fromList(MetadataListNode $node, string $path): array
    {
        $items = [];
        foreach ($node->getItems() as $index => $item) {
            $items[] = $this->fromNode($item, \sprintf('%s[%d]', $path, $index));
        }

        return $items;
    }

    /**
     * @return scalar|null
     */
    private function fromValue(ValueNode $node, string $path): string|int|float|bool|null
    {
        $value = $node->getValue();

        if (ValueNode::TYPE_IDENTIFIER === $node->getType()) {
            // Bare identifiers are unquoted strings, except `null`, which the lexer
            // hands over as the identifier "null" rather than a value of its own.
            return 'null' === $value ? null : (\is_string($value) ? $value : null);
        }

        return match (true) {
            \is_string($value), \is_int($value), \is_float($value), \is_bool($value) => $value,
            default => throw $this->ctx->invalid(\sprintf('%s has an unsupported value', $path)),
        };
    }
}
