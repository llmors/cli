<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Manifest\Writer;

use ClanCats\SchemaScript\Lexer;
use ClanCats\SchemaScript\TokenType;
use Llmor\Cli\Manifest\Writer\ScscEncodeException;
use Llmor\Cli\Manifest\Writer\ScscEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every assertion about a literal is made by feeding it back through the real
 * {@see Lexer}, not by comparing to the string this test expects. The whole class
 * exists because the grammar is easy to be wrong about, so a test that only checks
 * "what I thought it would emit" would be wrong in exactly the same places.
 */
#[CoversClass(ScscEncoder::class)]
final class ScscEncoderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function keys(): iterable
    {
        yield 'plain identifier' => ['prompt', true, 'prompt = '];
        yield 'underscored' => ['_internal', true, '_internal = '];
        yield 'digits inside' => ['top_5', true, 'top_5 = '];
        // A leading digit lexes as a Number, so the bracket form is required.
        yield 'all digits' => ['0', true, '[0] = '];
        yield 'leading digit' => ['0abc', true, '[0abc] = '];
        yield 'dotted' => ['extra.body', true, '[extra.body] = '];
        yield 'namespaced' => ['ns:key', true, '[ns:key] = '];
        yield 'dashed' => ['top-k', false, ''];
        yield 'spaced' => ['my key', false, ''];
        yield 'non-ascii' => ['tempérä', false, ''];
        yield 'empty' => ['', false, ''];
        yield 'double colon' => ['a::b', false, ''];
    }

    #[DataProvider('keys')]
    public function testKeyExpressibility(string $key, bool $expressible, string $expected): void
    {
        self::assertSame($expressible, ScscEncoder::isKeyExpressible($key));

        if (!$expressible) {
            $this->expectException(ScscEncodeException::class);
        }

        $encoded = ScscEncoder::key($key);
        self::assertSame($expected, $encoded);
        self::assertSame($key, self::lexKey($encoded), 'the key must lex back to itself');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function strings(): iterable
    {
        yield 'empty' => [''];
        yield 'plain' => ['hello'];
        yield 'apostrophe' => ["it's"];
        yield 'double quote' => ['he said "hi"'];
        yield 'both quotes' => ['it\'s a "thing"'];
        yield 'backslash' => ['C:\path\to'];
        yield 'trailing backslash' => ['ends\\'];
        yield 'backslash then quote' => ["a\\'b"];
        // Not an escape in SchemaScript — the two characters must survive as they are.
        yield 'literal backslash n' => ['a\nb'];
        yield 'real newline' => ["line1\nline2"];
        yield 'unicode' => ['grüße 🎉'];
        yield 'looks like a number' => ['0.2'];
        yield 'looks like a keyword' => ['true'];
        yield 'looks like a reference' => ['A::B'];
    }

    #[DataProvider('strings')]
    public function testStringsRoundTripThroughTheLexer(string $value): void
    {
        self::assertSame($value, self::lexValue(ScscEncoder::string($value)));
    }

    public function testApostrophesSwitchToDoubleQuotesRatherThanBeingEscaped(): void
    {
        self::assertSame('"it\'s"', ScscEncoder::string("it's"));
        self::assertSame("'he said \"hi\"'", ScscEncoder::string('he said "hi"'));
    }

    /**
     * @return iterable<string, array{int|float, string}>
     */
    public static function numbers(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'negative int' => [-7, '-7'];
        yield 'float' => [0.2, '0.2'];
        yield 'negative float' => [-0.5, '-0.5'];
        // A whole float loses its `.0` and reads back as an int. Harmless: the server
        // takes either, and ParameterMerger compares numbers numerically, so no sync
        // sees a change.
        yield 'whole float' => [2.0, '2'];
        yield 'round hundred' => [100.0, '100'];
        // Exponent notation is not in the grammar at all, so it has to be spelled out.
        yield 'small exponent' => [1.0e-5, '0.00001'];
        yield 'large exponent' => [1.0e20, '100000000000000000000'];
    }

    #[DataProvider('numbers')]
    public function testNumbersAreWrittenWithoutExponentNotation(int|float $value, string $expected): void
    {
        $encoded = ScscEncoder::number($value);

        self::assertSame($expected, $encoded);
        self::assertMatchesRegularExpression(ScscEncoder::NUMBER, $encoded);
        self::assertEqualsWithDelta($value, self::lexValue($encoded), \abs((float) $value) * 1.0e-12);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function unwritableNumbers(): iterable
    {
        yield 'huge' => [1.0e100];
        yield 'tiny' => [1.0e-100];
        yield 'nan' => [\NAN];
        yield 'infinite' => [\INF];
    }

    #[DataProvider('unwritableNumbers')]
    public function testANumberWithNoDecimalSpellingIsRefused(float $value): void
    {
        $this->expectException(ScscEncodeException::class);

        ScscEncoder::number($value);
    }

    public function testBareLiterals(): void
    {
        self::assertSame('true', ScscEncoder::scalar(true));
        self::assertSame('false', ScscEncoder::scalar(false));
        self::assertSame('null', ScscEncoder::scalar(null));
        self::assertSame("'x'", ScscEncoder::scalar('x'));
        self::assertSame('3', ScscEncoder::scalar(3));
    }

    /** The value of the first non-trivial token of an emitted literal. */
    private static function lexValue(string $literal): mixed
    {
        foreach ((new Lexer($literal, 'test.scsc'))->tokens() as $token) {
            if (!$token->isType(TokenType::Line) && !$token->isType(TokenType::Space)) {
                return $token->getValue();
            }
        }

        self::fail(\sprintf('"%s" produced no tokens.', $literal));
    }

    /** The key a `key = ` / `[key] = ` prefix lexes to. */
    private static function lexKey(string $prefix): mixed
    {
        return self::lexValue(\rtrim(\rtrim($prefix), '='));
    }
}
