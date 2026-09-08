<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

use Llmor\Cli\Sync\SyncError;
use Llmor\Cli\Sync\ValidationErrorFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationErrorFormatter::class)]
final class ValidationErrorFormatterTest extends TestCase
{
    public function testCleanDropsEmptyFieldsAndDedupesCamelSnakeDuplicates(): void
    {
        // The exact shape the API returned on a real 400: empty arrays for valid
        // fields, a {rule: message} object for the failing one, and both camelCase
        // and snake_case keys for every field.
        $raw = [
            'name' => [],
            'description' => [],
            'functionKey' => [],
            'runtime' => [],
            'code' => [],
            'argumentSchema' => [],
            'configSchema' => [],
            'isLibrary' => ['boolType' => '"0" must be of type boolean'],
            'specificAppId' => [],
            'rules' => [],
            'specific_app_id' => [],
            'is_library' => ['boolType' => '"0" must be of type boolean'],
            'function_key' => [],
            'argument_schema' => [],
            'config_schema' => [],
        ];

        $clean = ValidationErrorFormatter::clean($raw);

        self::assertSame(['is_library'], \array_keys($clean), 'Only the failing field survives, deduped.');
        self::assertSame(['"0" must be of type boolean'], $clean['is_library']);
    }

    public function testLabelMapsFieldsBackToManifest(): void
    {
        self::assertSame('[runtime]', ValidationErrorFormatter::label('runtime'));
        self::assertSame('the [entry] file', ValidationErrorFormatter::label('code'));
        self::assertSame('library flag', ValidationErrorFormatter::label('isLibrary'), 'camelCase canonicalises to snake_case.');
        self::assertSame('unknown_field', ValidationErrorFormatter::label('unknown_field'));
    }

    public function testHintForKnownRules(): void
    {
        self::assertSame("must be 'silicon' or 'graph'", ValidationErrorFormatter::hint(SyncError::SCOPE_FUNCTION, 'runtime'));
        self::assertNull(ValidationErrorFormatter::hint(SyncError::SCOPE_APP, 'description'));
    }

    public function testAHintCanDifferByScope(): void
    {
        // A function's [name] has no lower bound; an app's does. Naming the wrong one
        // sends the reader looking for a problem that isn't there.
        self::assertSame('must be 2 to 144 characters', ValidationErrorFormatter::hint(SyncError::SCOPE_APP, 'name'));
        self::assertSame('must be at most 144 characters', ValidationErrorFormatter::hint(SyncError::SCOPE_FUNCTION, 'name'));
    }

    public function testRulesBucketDropsMessagesThatDuplicateAField(): void
    {
        $clean = ValidationErrorFormatter::clean([
            'path' => ['The file path is invalid.'],
            'rules' => ['The file path is invalid.'],
        ]);

        self::assertSame(['path'], \array_keys($clean), 'A rules message echoing a field is dropped.');
    }

    public function testRulesBucketKeepsGenuineCrossFieldMessages(): void
    {
        $clean = ValidationErrorFormatter::clean([
            'specificAppId' => [],
            'rules' => ['The specified vendor app does not belong to this vendor.'],
        ]);

        self::assertSame(['The specified vendor app does not belong to this vendor.'], $clean['rules']);
    }

    public function testNestedFieldMapsFlattenToDottedPaths(): void
    {
        // App validation nests one level down: the server rejects `parameters` as a
        // whole and puts the real messages under the individual parameter keys.
        // Reading only the top level would render an error with nothing under it.
        $clean = ValidationErrorFormatter::clean([
            'parameters' => [
                'temperature' => ['must be at most 2'],
                'prompt' => ['stringType' => 'must not be empty'],
                'enable_ask_user' => [],
            ],
            'name' => [],
        ]);

        self::assertSame(['parameters.temperature', 'parameters.prompt'], \array_keys($clean));
        self::assertSame(['must be at most 2'], $clean['parameters.temperature']);
        self::assertSame(['must not be empty'], $clean['parameters.prompt']);
        self::assertSame('[parameters] → temperature', ValidationErrorFormatter::label('parameters.temperature'));
        self::assertSame('the [entry] file → inner', ValidationErrorFormatter::label('code.inner'), 'Only the head segment is relabelled.');
    }

    public function testNestingStopsAtTheDepthCap(): void
    {
        $clean = ValidationErrorFormatter::clean([
            'a' => ['b' => ['c' => ['d' => ['e' => ['too deep']]]]],
        ]);

        self::assertSame([], $clean, 'A pathological response is dropped rather than printed.');
    }
}
