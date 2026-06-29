<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Sync;

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
        self::assertSame("must be 'silicon' or 'graph'", ValidationErrorFormatter::hint('runtime', ['bad']));
        self::assertNull(ValidationErrorFormatter::hint('name', ['too long']));
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
}
