<?php

declare(strict_types=1);

namespace Llmor\Cli\Tests\Unit\Manifest;

use Llmor\Cli\Manifest\Builder\AppDefinitionBuilder;
use Llmor\Cli\Manifest\FileValueLoader;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\ManifestParser;
use Llmor\Cli\Manifest\NamedEntries;
use Llmor\Cli\Manifest\ParameterTree;
use Llmor\Cli\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(AppDefinitionBuilder::class)]
#[CoversClass(ParameterTree::class)]
#[CoversClass(FileValueLoader::class)]
#[CoversClass(NamedEntries::class)]
final class AppDefinitionBuilderTest extends TestCase
{
    use TempProject;

    protected function setUp(): void
    {
        $this->makeProject();
    }

    protected function tearDown(): void
    {
        $this->removeProject();
    }

    public function testParsesAppDeclarationAlongsideFunctions(): void
    {
        $this->writeProjectFile('main/main.lua', "return success('hi')\n");

        $manifest = $this->parse(<<<'SCSC'
            greeter: Function {
              [name] = 'G'  [description] = 'D'  [runtime] = 'silicon'
              [srcdir] = './main'  [entry] = 'main.lua'
            }

            support_bot: App {
              [app_type]    = 'llmor/generic'
              [name]        = 'Support Bot'
              [description] = 'Answers questions.'
              [model]       = 'gpt-4o'
              [id]          = 17
            }
            SCSC);

        self::assertCount(1, $manifest->functions);
        self::assertCount(1, $manifest->apps);

        $app = $manifest->apps[0];
        self::assertSame('support_bot', $app->declaration);
        self::assertSame('llmor/generic', $app->appType);
        self::assertSame('Support Bot', $app->name);
        self::assertSame('Answers questions.', $app->description);
        self::assertSame('gpt-4o', $app->model);
        self::assertSame(17, $app->id);
        self::assertNotNull($manifest->getApp('support_bot'));
        self::assertNull($manifest->getApp('missing'));
    }

    public function testOnlyAppKeyIsRequired(): void
    {
        $app = $this->parseApp("a: App {\n  [app_type] = 'llmor/silicon'\n}");

        self::assertNull($app->name, 'The server falls back to the app type name.');
        self::assertNull($app->description);
        self::assertNull($app->model);
        self::assertNull($app->id);
        self::assertSame(0, $app->parameterCount());
    }

    public function testParametersKeepTheirJsonTypes(): void
    {
        $app = $this->parseApp(<<<'SCSC'
            a: App {
              [app_type] = 'llmor/generic'
              [parameters] = {
                prompt = 'You are helpful.'
                temperature = 0.2
                max_function_call_iterations = 8
                enable_ask_user = true
                reasoning_effort = high
                output_json_schema = null
                examples = { 'one', 'two' }
                extra_body = { top_k = 40  nested = { deep = true } }
              }
            }
            SCSC);

        $parameters = $app->parameters;

        self::assertSame('You are helpful.', $parameters->prompt);
        self::assertSame(0.2, $parameters->temperature, 'A decimal must not become a string.');
        self::assertSame(8, $parameters->max_function_call_iterations);
        self::assertTrue($parameters->enable_ask_user);
        self::assertSame('high', $parameters->reasoning_effort, 'A bare identifier is an unquoted string.');
        self::assertNull($parameters->output_json_schema);
        self::assertSame(['one', 'two'], $parameters->examples);
        self::assertInstanceOf(stdClass::class, $parameters->extra_body);
        self::assertSame(40, $parameters->extra_body->top_k);
        self::assertTrue($parameters->extra_body->nested->deep);
        self::assertSame(8, $app->parameterCount());
    }

    public function testEmptyBlockEncodesAsAJsonObjectNotAnArray(): void
    {
        $app = $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [parameters] = { extra_body = {} }\n}");

        self::assertInstanceOf(stdClass::class, $app->parameters->extra_body);
        self::assertSame('{"extra_body":{}}', \json_encode($app->parameters));
    }

    public function testFileAnnotationLoadsTheValueFromDisk(): void
    {
        $this->writeProjectFile('prompts/support.md', "You are a support agent.\n");

        $app = $this->parseApp(<<<'SCSC'
            a: App {
              [app_type] = 'llmor/generic'
              [parameters] = {
                @file('./prompts/support.md')
                prompt = ''
              }
            }
            SCSC);

        self::assertSame("You are a support agent.\n", $app->parameters->prompt);
    }

    public function testFileAnnotationWorksOnAScalarKeyToo(): void
    {
        $this->writeProjectFile('about.txt', 'Long description.');

        $app = $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  @file('./about.txt')\n  [description] = ''\n}");

        self::assertSame('Long description.', $app->description);
    }

    public function testMissingFileAnnotationSourceIsAnError(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/@file source .* does not exist/');

        $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [parameters] = {\n    @file('./nope.md')\n    prompt = ''\n  }\n}");
    }

    public function testNonUtf8FileAnnotationSourceIsRejected(): void
    {
        $this->writeProjectFile('bad.md', "\xff\xfe not utf8");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/not valid UTF-8/');

        $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [parameters] = {\n    @file('./bad.md')\n    prompt = ''\n  }\n}");
    }

    public function testValuelessParameterIsRejectedAsALikelyTypo(): void
    {
        // `{ some_flag }` parses as an entry with a null value, which is almost
        // always a forgotten `= true` rather than an intentional JSON null.
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/has no value/');

        $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [parameters] = { enable_ask_user }\n}");
    }

    public function testTheLegacyAppKeySpellingStillParses(): void
    {
        $app = $this->parseApp("a: App {\n  [app_key] = 'llmor/generic'\n}");

        self::assertSame('llmor/generic', $app->appType);
        self::assertTrue($app->legacyTypeKey, 'So sync can report the deprecation.');
    }

    public function testDeclaringBothTypeKeysIsRejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/declare only \[app_type\]/');

        $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [app_key] = 'llmor/silicon'\n}");
    }

    public function testUnknownAppTypeWithoutASlashIsRejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[app_type\]/');

        $this->parseApp("a: App {\n  [app_type] = 'generic'\n}");
    }

    public function testUnknownButNamespacedAppTypeIsAllowedThrough(): void
    {
        // A newly released app type must not need a CLI release to become usable.
        $app = $this->parseApp("a: App {\n  [app_type] = 'vendor/prospective/chatbot'\n}");

        self::assertSame('vendor/prospective/chatbot', $app->appType);
    }

    public function testRejectsNonNumericIdPin(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[id\]/');

        $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [id] = 'seventeen'\n}");
    }

    public function testRejectsTooShortName(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/\[name\]/');

        $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [name] = 'x'\n}");
    }

    public function testDeclarationNamesShareOneNamespaceWithFunctions(): void
    {
        $this->writeProjectFile('main/main.lua', "return success('hi')\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/Duplicate declaration "support"/');

        $this->parse(<<<'SCSC'
            support: Function {
              [name] = 'S'  [description] = 'D'  [runtime] = 'silicon'
              [srcdir] = './main'  [entry] = 'main.lua'
            }

            support: App {
              [app_type] = 'llmor/generic'
            }
            SCSC);
    }

    public function testAbsentFunctionsBlockMeansTheManifestDoesNotOwnTheLinks(): void
    {
        // The API replaces the whole link table when the field is sent, so "absent"
        // and "empty" have to mean different things.
        $app = $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n}");

        self::assertFalse($app->ownsFunctions());
        self::assertNull($app->functions);
    }

    public function testEmptyFunctionsBlockMeansUnlinkEverything(): void
    {
        $app = $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [functions] = {}\n}");

        self::assertTrue($app->ownsFunctions());
        self::assertSame([], $app->functions);
    }

    /**
     * SchemaScript writes lists and objects with the same braces, and which one the
     * parser produces depends on the item count and quoting. All of these have to work.
     */
    /**
     * @param list<string> $expected
     */
    #[DataProvider('functionBlockShapes')]
    public function testEveryReasonableFunctionsShapeIsAccepted(string $block, array $expected): void
    {
        $app = $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [functions] = $block\n}");

        self::assertSame($expected, \array_map(static fn ($link): string => $link->name, $app->functions ?? []));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function functionBlockShapes(): iterable
    {
        yield 'single bare identifier (parses as a valueless entry)' => ['{ weather }', ['weather']];
        yield 'comma-separated identifiers (parses as a list)' => ['{ weather, greeter }', ['weather', 'greeter']];
        yield 'newline-separated identifiers (also a list)' => ["{\n    weather\n    greeter\n  }", ['weather', 'greeter']];
        yield 'quoted name (a one-item list)' => ["{ 'weather' }", ['weather']];
        yield 'bracket entries with configs' => ['{ [weather] = { units = 1 }  [greeter] = {} }', ['weather', 'greeter']];
        yield 'a bare value, no braces at all' => ['weather', ['weather']];
    }

    public function testMixingBareNamesWithBracketEntriesExplainsItself(): void
    {
        // SchemaScript decides list-vs-object by lookahead, so a mixed block is read as
        // a list and trips over the first bracket key. The raw parser error is baffling
        // for something people will reach for, so the message has to say what to do.
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/must use one shape throughout/');

        $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [functions] = {\n    greeter\n    [weather] = {}\n  }\n}");
    }

    public function testFunctionConfigIsReadPerLink(): void
    {
        $app = $this->parseApp(<<<'SCSC'
            a: App {
              [app_type] = 'llmor/generic'
              [functions] = {
                [weather] = { units = 'metric'  retries = 2 }
                [greeter] = {}
              }
            }
            SCSC);

        $links = $app->functions ?? [];
        self::assertSame('metric', $links[0]->config->units);
        self::assertSame(2, $links[0]->config->retries);
        self::assertSame([], (array) $links[1]->config);
    }

    public function testDuplicateFunctionReferenceIsRejected(): void
    {
        // The parser keeps both entries, so dropping one would silently ignore it.
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/lists "weather" more than once/');

        $this->parseApp("a: App {\n  [app_type] = 'llmor/generic'\n  [functions] = { [weather] = {}  [weather] = {} }\n}");
    }

    public function testSubagentsReadEveryFieldTheApiOwns(): void
    {
        $manifest = $this->parse(<<<'SCSC'
            support: App {
              [app_type] = 'llmor/generic'
              [subagents] = {
                [triage] = {
                  [app]               = research
                  [description]       = 'Deep research helper'
                  [expose_as_tool]    = true
                  [tool_name]         = 'deep_research'
                  [tool_description]  = 'Run a research pass.'
                  [input_description] = 'The question.'
                }
              }
            }

            research: App {
              [app_type] = 'llmor/generic'
            }
            SCSC);

        $subagent = ($manifest->apps[0]->subagents ?? [])[0];

        self::assertSame('triage', $subagent->alias);
        self::assertSame('research', $subagent->target);
        self::assertNull($subagent->targetId);
        self::assertSame('Deep research helper', $subagent->description);
        self::assertTrue($subagent->exposeAsTool);
        self::assertSame('deep_research', $subagent->toolName);
        self::assertSame('deep_research', $subagent->effectiveToolName());
        self::assertSame('Run a research pass.', $subagent->toolDescription);
        self::assertSame('The question.', $subagent->inputDescription);
    }

    public function testBareSubagentAliasTargetsTheSameNamedApp(): void
    {
        $manifest = $this->parse(<<<'SCSC'
            support: App {
              [app_type] = 'llmor/generic'
              [subagents] = { research }
            }

            research: App {
              [app_type] = 'llmor/generic'
            }
            SCSC);

        $subagent = ($manifest->apps[0]->subagents ?? [])[0];
        self::assertSame('research', $subagent->alias);
        self::assertSame('research', $subagent->target);
        self::assertSame('research', $subagent->effectiveToolName(), 'Without [tool_name] the alias is the tool name.');
    }

    public function testSubagentCanTargetAnAppByNumericId(): void
    {
        $manifest = $this->parse(<<<'SCSC'
            support: App {
              [app_type] = 'llmor/generic'
              [subagents] = { [helper] = { [app] = 61 } }
            }
            SCSC);

        $subagent = ($manifest->apps[0]->subagents ?? [])[0];
        self::assertSame(61, $subagent->targetId, 'A numeric target addresses an app outside this manifest.');
    }

    public function testUnknownSubagentTargetIsRejectedBeforeAnyApiCall(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/references undeclared app "reserch"/');

        $this->parse("support: App {\n  [app_type] = 'llmor/generic'\n  [subagents] = { [triage] = { [app] = reserch } }\n}");
    }

    public function testSubagentTargetingAFunctionIsRejected(): void
    {
        $this->writeProjectFile('main/main.lua', "return success('hi')\n");

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/which is a function, not an app/');

        $this->parse(<<<'SCSC'
            greeter: Function {
              [name] = 'G'  [description] = 'D'  [runtime] = 'silicon'
              [srcdir] = './main'  [entry] = 'main.lua'
            }

            support: App {
              [app_type] = 'llmor/generic'
              [subagents] = { [triage] = { [app] = greeter } }
            }
            SCSC);
    }

    public function testSelfDelegationIsRejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/cannot delegate to itself/');

        $this->parse("support: App {\n  [app_type] = 'llmor/generic'\n  [subagents] = { [me] = { [app] = support } }\n}");
    }

    public function testInvalidAliasIsRejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/alias must be lowercase/');

        $this->parse("support: App {\n  [app_type] = 'llmor/generic'\n  [subagents] = { [Triage] = { [app] = 61 } }\n}");
    }

    public function testCollidingEffectiveToolNamesAreRejected(): void
    {
        // The server derives `tool_name ?: alias` and rejects the collision; catching
        // it here can name both aliases instead of just one field.
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/both expose the tool name "search"/');

        $this->parse(<<<'SCSC'
            support: App {
              [app_type] = 'llmor/generic'
              [subagents] = {
                [search] = { [app] = 61 }
                [other]  = { [app] = 62  [tool_name] = 'search' }
              }
            }
            SCSC);
    }

    private function parse(string $code): \Llmor\Cli\Manifest\Manifest
    {
        return (new ManifestParser())->parse($code, $this->projectPath('llmor.scsc'), $this->projectDir);
    }

    private function parseApp(string $code): \Llmor\Cli\Manifest\AppDefinition
    {
        $apps = $this->parse($code)->apps;
        self::assertCount(1, $apps);

        return $apps[0];
    }
}
