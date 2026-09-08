<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

/**
 * The SchemaScript emission vocabulary: keys, strings, numbers, bare literals.
 *
 * The inverse of what {@see \Llmor\Cli\Manifest\ParameterTree} reads, and constrained
 * by the lexer rather than by taste. Three of those constraints are easy to get wrong
 * and each one silently corrupts a value:
 *
 * - **Numbers are `-?\d+(\.\d+)?`.** No exponent, no leading `+`, no bare `.5`. A
 *   `1.0e-5` emitted verbatim does not lex as a number at all.
 * - **There are exactly three string escapes** — `\\`, `\'` and `\"`
 *   (`ClanCats\SchemaScript\Util\StringEscapeParser`). `\n` is *not* one of them, so
 *   emitting it produces the two literal characters `\` and `n`. A real newline inside
 *   quotes, on the other hand, is legal and preserved.
 * - **Keys have no quoted form.** `[a-b]` is a lexer error, not a key called `a-b`, and
 *   there is no escape hatch — hence {@see isKeyExpressible()} and the drop policy in
 *   {@see ParameterEmitter}.
 */
final class ScscEncoder
{
    /** A `[bracket]` key, per the lexer's MetadataKey token. */
    public const BRACKET_KEY = '/^[A-Za-z0-9_:.]+$/';

    /**
     * A plain `key = value` key.
     *
     * Narrower than the lexer's Identifier token on purpose: the Number pattern is
     * matched *first*, so an all-digit key like `0` lexes as a number and the entry
     * fails to parse. Requiring a leading letter or underscore avoids that entirely.
     */
    public const PLAIN_KEY = '/^[A-Za-z_]\w*$/';

    /** The complete number grammar. */
    public const NUMBER = '/^-?\d+(\.\d+)?$/';

    /**
     * Refuse to spell out a number longer than this. A 300-digit expansion of a float
     * is unreadable, and quoting it (which is what the caller falls back to) compares
     * equal anyway — see {@see \Llmor\Cli\Sync\ParameterMerger::equals()}.
     */
    private const MAX_NUMBER_DIGITS = 48;

    public static function isKeyExpressible(string $key): bool
    {
        // `::` lexes as a MetadataKey, but reads as a namespace reference to every
        // human and to ParameterTree, so it is treated as inexpressible.
        return 1 === \preg_match(self::BRACKET_KEY, $key) && !\str_contains($key, '::');
    }

    /**
     * A key with its `=`, ready to prefix a value: `temperature = ` or `[foo.bar] = `.
     *
     * Plain when it can be, so generated blocks read like hand-written ones. Both forms
     * yield the identical key string — the lexer strips the brackets — so the choice is
     * cosmetic wherever both are legal, unlike at declaration level where the bracket
     * form is mandatory.
     *
     * @throws ScscEncodeException
     */
    public static function key(string $key): string
    {
        if (!self::isKeyExpressible($key)) {
            throw new ScscEncodeException(\sprintf('Key "%s" cannot be written in SchemaScript — keys may only use letters, digits, "_", "." and ":".', $key));
        }

        return 1 === \preg_match(self::PLAIN_KEY, $key) ? $key.' = ' : '['.$key.'] = ';
    }

    /** A declaration-level key, which must always use the bracket form. */
    public static function bracketKey(string $key): string
    {
        return '['.$key.'] = ';
    }

    /**
     * A quoted string literal.
     *
     * Single quotes by default, switching to double quotes when that removes the need
     * to escape anything — apostrophes in prose are common enough that `"it's"` beats
     * `'it\'s'` for readability.
     */
    public static function string(string $value): string
    {
        $quote = \str_contains($value, "'") && !\str_contains($value, '"') ? '"' : "'";

        return $quote.\str_replace(['\\', $quote], ['\\\\', '\\'.$quote], $value).$quote;
    }

    /**
     * A number literal in the only notation the lexer accepts.
     *
     * @throws ScscEncodeException when no such notation exists for this value
     */
    public static function number(int|float $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        if (!\is_finite($value)) {
            throw new ScscEncodeException(\sprintf('%s is not a JSON number.', \var_export($value, true)));
        }

        // json_encode gives the shortest representation that round-trips, which is the
        // right answer whenever it happens not to use exponent notation.
        $shortest = \json_encode($value);
        if (\is_string($shortest) && 1 === \preg_match(self::NUMBER, $shortest)) {
            return $shortest;
        }

        $decimal = self::expand($value);
        if (null !== $decimal) {
            return $decimal;
        }

        throw new ScscEncodeException(\sprintf('%.1E cannot be written without exponent notation.', $value));
    }

    /**
     * @throws ScscEncodeException
     */
    public static function scalar(bool|int|float|string|null $value): string
    {
        return match (true) {
            null === $value => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => self::string($value),
            default => self::number($value),
        };
    }

    /**
     * Spell a float out in plain decimal at the shortest precision that still round
     * trips, or null when that needs more digits than anyone wants to read.
     */
    private static function expand(float $value): ?string
    {
        for ($precision = 1; $precision <= 17; ++$precision) {
            $decimal = \rtrim(\rtrim(\sprintf('%.'.$precision.'F', $value), '0'), '.');

            if ((float) $decimal === $value) {
                return 1 === \preg_match(self::NUMBER, $decimal) && \strlen($decimal) <= self::MAX_NUMBER_DIGITS
                    ? $decimal
                    : null;
            }
        }

        return null;
    }
}
