<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Import;

use Llmor\Cli\Import\DeclarationNamer;
use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\ConfigDefinition;
use Llmor\Cli\Manifest\FunctionDefinition;
use Llmor\Cli\Manifest\Manifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclarationNamer::class)]
final class DeclarationNamerTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function names(): iterable
    {
        yield 'two words' => ['Support Bot', 'support_bot'];
        yield 'punctuation' => ['Mario\'s Helper!', 'mario_s_helper'];
        yield 'already a slug' => ['support_bot', 'support_bot'];
        yield 'runs of separators' => ['A  --  B', 'a_b'];
        yield 'surrounding junk' => ['  ***Bot***  ', 'bot'];
        // An identifier cannot start with a digit, so it is prefixed rather than dropped.
        yield 'leading digit' => ['2nd Line Support', 'app_2nd_line_support'];
        yield 'non-ascii only' => ['日本語', 'generic_app'];
        yield 'empty' => ['', 'generic_app'];
        yield 'absent' => [null, 'generic_app'];
    }

    #[DataProvider('names')]
    public function testDerivesADeclarationNameFromTheRemoteName(?string $remote, string $expected): void
    {
        $namer = new DeclarationNamer(new Manifest('/m/llmor.scsc', []));
        $suggested = $namer->suggest($remote, 'llmor/generic', 17);

        self::assertSame($expected, $suggested);
        self::assertTrue($namer->isValid($suggested));
    }

    public function testFallsBackToTheAppIdWhenNothingElseYieldsAName(): void
    {
        $namer = new DeclarationNamer(new Manifest('/m/llmor.scsc', []));

        self::assertSame('app_17', $namer->suggest(null, '///', 17));
    }

    public function testALongNameIsTruncatedToSomethingReadable(): void
    {
        $namer = new DeclarationNamer(new Manifest('/m/llmor.scsc', []));
        $suggested = $namer->suggest(\str_repeat('very long name ', 10), 'llmor/generic', 17);

        self::assertLessThanOrEqual(48, \strlen($suggested));
        self::assertTrue($namer->isValid($suggested), 'truncation must not leave a trailing underscore');
    }

    /** Functions and apps share one namespace, so both can block a name. */
    public function testFunctionsAndAppsBothClaimNames(): void
    {
        $namer = new DeclarationNamer($this->manifest());

        self::assertSame('function', $namer->takenBy('greeter'));
        self::assertSame('app', $namer->takenBy('support_bot'));
        self::assertNull($namer->takenBy('free'));

        self::assertSame('a function', $namer->describeTaken('greeter'));
        self::assertSame('an app', $namer->describeTaken('support_bot'));
    }

    /** The settings block sits in that namespace too, and it is not an app. */
    public function testTheConfigBlockClaimsItsNameAsWell(): void
    {
        $namer = new DeclarationNamer(new Manifest('/m/llmor.scsc', [], [], new ConfigDefinition('prompts', 'llmor')));

        self::assertSame('config block', $namer->takenBy('llmor'));
        self::assertSame('a config block', $namer->describeTaken('llmor'));
        self::assertSame('llmor_2', $namer->nextFree('llmor'));
    }

    public function testNextFreeSkipsWhatIsAlreadyDeclared(): void
    {
        $namer = new DeclarationNamer($this->manifest());

        self::assertSame('free', $namer->nextFree('free'));
        self::assertSame('support_bot_2', $namer->nextFree('support_bot'));
        self::assertSame('support_bot_3', (new DeclarationNamer(new Manifest('/m/llmor.scsc', [], [
            $this->app('support_bot'),
            $this->app('support_bot_2'),
        ])))->nextFree('support_bot'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'identifier' => ['support_bot', true];
        yield 'underscore first' => ['_bot', true];
        yield 'digits inside' => ['bot2', true];
        yield 'dashed' => ['support-bot', false];
        yield 'leading digit' => ['2bot', false];
        yield 'spaced' => ['support bot', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('candidates')]
    public function testValidatesAHandPickedName(string $name, bool $valid): void
    {
        self::assertSame($valid, (new DeclarationNamer(new Manifest('/m/llmor.scsc', [])))->isValid($name));
    }

    private function manifest(): Manifest
    {
        return new Manifest('/m/llmor.scsc', [
            new FunctionDefinition('greeter', 'G', 'D', 'silicon', './src', 'main.lua', '/m/src', '/m/src/main.lua'),
        ], [$this->app('support_bot')]);
    }

    private function app(string $declaration): AppDefinition
    {
        return new AppDefinition(declaration: $declaration, appType: 'llmor/generic');
    }
}
