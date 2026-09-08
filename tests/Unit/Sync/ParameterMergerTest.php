<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

use Llmor\Cli\Sync\ParameterMerger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ParameterMerger::class)]
final class ParameterMergerTest extends TestCase
{
    public function testDeclaredValuesWinAndUndeclaredRemoteValuesSurvive(): void
    {
        $remote = ['prompt' => 'old', 'temperature' => 0.7, 'set_in_console' => true];
        $merged = ParameterMerger::merge($remote, self::object(['prompt' => 'new']));

        self::assertSame('new', $merged->prompt);
        self::assertSame(0.7, $merged->temperature, 'A server default the manifest never mentions is kept.');
        self::assertTrue($merged->set_in_console);
    }

    public function testMapsMergeButListsReplace(): void
    {
        $remote = [
            'extra_body' => ['top_k' => 40, 'seed' => 1],
            'examples' => [['message' => 'a'], ['message' => 'b']],
        ];

        $merged = ParameterMerger::merge($remote, self::object([
            'extra_body' => self::object(['seed' => 7]),
            'examples' => [['message' => 'only']],
        ]));

        self::assertSame(40, $merged->extra_body->top_k, 'Merging a map keeps the keys you did not declare.');
        self::assertSame(7, $merged->extra_body->seed);
        self::assertCount(1, $merged->examples, 'A list replaces wholesale — merging by index means nothing.');
    }

    public function testMergeIsIdempotent(): void
    {
        $remote = ['prompt' => 'old', 'extra_body' => ['a' => 1], 'examples' => [1, 2]];
        $declared = self::object([
            'prompt' => 'new',
            'extra_body' => self::object(['b' => 2]),
            'examples' => [3],
        ]);

        $once = ParameterMerger::merge($remote, $declared);
        $twice = ParameterMerger::merge($once, $declared);

        self::assertTrue(ParameterMerger::equals($once, $twice), 'Re-applying the manifest must not keep changing the result.');
    }

    public function testEqualsToleratesJsonNumberDrift(): void
    {
        // A text-column bag round-trips through JSON, so what we sent as 0.2 or 1 can
        // come back as "0.2" or 1.0. Treating those as changes would PUT forever.
        self::assertTrue(ParameterMerger::equals(self::object(['t' => 0.2]), self::object(['t' => '0.2'])));
        self::assertTrue(ParameterMerger::equals(self::object(['n' => 1]), self::object(['n' => 1.0])));
        self::assertTrue(ParameterMerger::equals(self::object(['t' => 0.1 + 0.2]), self::object(['t' => 0.3])));

        self::assertFalse(ParameterMerger::equals(self::object(['t' => 0.2]), self::object(['t' => 0.3])));
    }

    public function testEqualsIsStrictAboutBooleansAndNull(): void
    {
        self::assertFalse(ParameterMerger::equals(self::object(['b' => true]), self::object(['b' => 1])));
        self::assertFalse(ParameterMerger::equals(self::object(['b' => null]), self::object(['b' => ''])));
        self::assertTrue(ParameterMerger::equals(self::object(['b' => null]), self::object(['b' => null])));
    }

    public function testEqualsIgnoresKeyOrderButNotKeySet(): void
    {
        self::assertTrue(ParameterMerger::equals(self::object(['a' => 1, 'b' => 2]), self::object(['b' => 2, 'a' => 1])));
        self::assertFalse(ParameterMerger::equals(self::object(['a' => 1]), self::object(['a' => 1, 'b' => 2])));
    }

    public function testEqualsComparesListsInOrder(): void
    {
        self::assertTrue(ParameterMerger::equals(self::object(['l' => [1, 2]]), self::object(['l' => [1, 2]])));
        self::assertFalse(ParameterMerger::equals(self::object(['l' => [1, 2]]), self::object(['l' => [2, 1]])));
    }

    public function testUnchangedParametersProduceNoDiff(): void
    {
        $remote = ['prompt' => 'same', 'temperature' => 0.2];
        $normalized = ParameterMerger::normalize($remote);
        $merged = ParameterMerger::merge($remote, self::object(['prompt' => 'same']));

        self::assertTrue(ParameterMerger::equals($normalized, $merged));
        self::assertSame([], ParameterMerger::changedPaths($normalized, $merged));
    }

    public function testChangedPathsNamesTheLeavesThatDiffer(): void
    {
        $remote = ['prompt' => 'old', 'extra_body' => ['top_k' => 40], 'keep' => 1];
        $normalized = ParameterMerger::normalize($remote);
        $merged = ParameterMerger::merge($remote, self::object([
            'prompt' => 'new',
            'extra_body' => self::object(['top_k' => 50]),
            'added' => true,
        ]));

        self::assertSame(['prompt', 'extra_body.top_k', 'added'], ParameterMerger::changedPaths($normalized, $merged));
    }

    public function testAnEmptyRemoteBagMatchesDeclaringNothing(): void
    {
        // An empty JSON object decodes to [] in PHP. If the two sides disagree about
        // that, an app with no [parameters] looks changed on every run.
        $remote = [];

        self::assertTrue(ParameterMerger::equals(
            ParameterMerger::bag($remote),
            ParameterMerger::merge($remote, new stdClass()),
        ));
        self::assertTrue(ParameterMerger::equals(ParameterMerger::bag(null), ParameterMerger::bag([])));
    }

    public function testNormalizeDistinguishesMapsFromLists(): void
    {
        $normalized = ParameterMerger::normalize(['map' => ['a' => 1], 'list' => [1, 2], 'empty' => []]);
        self::assertInstanceOf(stdClass::class, $normalized);

        self::assertInstanceOf(stdClass::class, $normalized->map);
        self::assertSame([1, 2], $normalized->list);
        self::assertSame([], $normalized->empty, 'An empty JSON array stays a list — only the manifest can mean {}.');
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function object(array $values): stdClass
    {
        return (object) $values;
    }
}
