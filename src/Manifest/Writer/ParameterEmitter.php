<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

use stdClass;

/**
 * Renders a JSON-shaped value — the {@see \Llmor\Cli\Manifest\ParameterTree} /
 * {@see \Llmor\Cli\Sync\ParameterMerger::normalize()} shape — as a SchemaScript value.
 *
 * The interesting part is what happens when something cannot be written at all. A
 * parameter key like `top-k` has no spelling in the grammar and no escape hatch, so it
 * has to be left out. That is safe *because* `[parameters]` are overrides: a key the
 * manifest omits keeps whatever the server has, and the declaration being written is a
 * snapshot of that same server state, so omitting it is a provable no-op for the next
 * `sync` rather than merely a tolerable one.
 *
 * That reasoning holds through **maps**, which merge recursively — and breaks for
 * **lists**, which replace wholesale. A list of maps whose element quietly lost a key
 * would be PUT back with that key missing, deleting remote data. So a list that cannot
 * be written faithfully is dropped whole, bubbling up to the nearest enclosing map
 * entry, where omission becomes a no-op again.
 *
 * Two shape rules from the lexer, both easy to trip over:
 * - a **list** always gets a trailing comma. It is what forces list shape regardless of
 *   item type, and without it `{ true }` reads as a block with a valueless key.
 * - a **block** never gets one. A comma in a block body is a parse error.
 */
final class ParameterEmitter
{
    /**
     * A parameter bag as a `{ … }` block, with optional annotations in front of
     * individual entries (that is how `@file` reaches the emitted text).
     *
     * @param stdClass              $bag         the map to render
     * @param string                $path        dotted path of the bag, for notes
     * @param int                   $depth       indent level of the line the block opens on
     * @param array<string, string> $annotations key => `@file` argument
     */
    public function bag(stdClass $bag, string $path, int $depth, array $annotations = []): EmittedValue
    {
        return $this->map($bag, $path, $depth, $annotations);
    }

    public function value(mixed $value, string $path, int $depth): EmittedValue
    {
        return match (true) {
            $value instanceof stdClass => $this->map($value, $path, $depth, []),
            \is_array($value) => \array_is_list($value) ? $this->list($value, $path, $depth) : $this->map((object) $value, $path, $depth, []),
            default => $this->scalar($value, $path),
        };
    }

    /**
     * @param array<string, string> $annotations
     */
    private function map(stdClass $bag, string $path, int $depth, array $annotations): EmittedValue
    {
        $block = new ScscBlock($depth + 1);
        $notes = [];
        $lossy = false;

        foreach (\get_object_vars($bag) as $key => $item) {
            $key = (string) $key;
            $child = '' === $path ? $key : $path.'.'.$key;

            if (!ScscEncoder::isKeyExpressible($key)) {
                $notes[] = \sprintf('%s: the key cannot be written in SchemaScript, so it stays console-managed', $child);
                $lossy = true;
                continue;
            }

            $emitted = $this->value($item, $child, $block->depth());
            \array_push($notes, ...$emitted->notes);

            if (null === $emitted->scsc) {
                $lossy = true;
                continue;
            }

            // A nested map that lost a key is still written, but its lossiness has to
            // reach any enclosing list, which cannot afford to be partial.
            $lossy = $lossy || $emitted->lossy;

            if (null !== ($annotation = $annotations[$key] ?? null)) {
                $block->annotation('file', $annotation);
            }

            $block->entry(ScscEncoder::key($key), $emitted->scsc);
        }

        // An emptied-out map still has to be a map: `{}` is a MetadataBlockNode, which
        // is what ParameterTree turns back into an object.
        $scsc = $block->isEmpty() ? '{}' : $block->braced();

        return new EmittedValue($scsc, $notes, $lossy);
    }

    /**
     * @param list<mixed> $items
     */
    private function list(array $items, string $path, int $depth): EmittedValue
    {
        // `{ , }` is how the grammar spells an empty list; `{}` would be an empty
        // *object*, which is a different JSON value.
        if ([] === $items) {
            return new EmittedValue('{ , }');
        }

        $block = new ScscBlock($depth + 1);
        $notes = [];

        foreach ($items as $index => $item) {
            $emitted = $this->value($item, \sprintf('%s[%d]', $path, $index), $block->depth());
            \array_push($notes, ...$emitted->notes);

            if (null === $emitted->scsc || $emitted->lossy) {
                // Anything less than the whole list would be sent as the whole list.
                $notes[] = \sprintf('%s: the list is left out entirely, because a list is replaced as a whole and a partial one would delete remote data', $path);

                return new EmittedValue(null, $notes, true);
            }

            $block->raw($block->indent().$emitted->scsc.',');
        }

        return new EmittedValue($block->braced(), $notes);
    }

    private function scalar(mixed $value, string $path): EmittedValue
    {
        if (!\is_scalar($value) && null !== $value) {
            return EmittedValue::dropped(\sprintf('%s: value of an unsupported type (%s)', $path, \get_debug_type($value)));
        }

        try {
            return new EmittedValue(ScscEncoder::scalar($value));
        } catch (ScscEncodeException) {
            // Only numbers get here. A quoted numeric string still compares equal to
            // the float it came from (ParameterMerger is lenient about JSON number
            // drift), so this is faithful where it matters.
            return new EmittedValue(
                ScscEncoder::string(\is_float($value) ? \sprintf('%.17G', $value) : (string) $value),
                [\sprintf('%s: written as a quoted string — SchemaScript numbers have no exponent notation', $path)],
            );
        }
    }
}
