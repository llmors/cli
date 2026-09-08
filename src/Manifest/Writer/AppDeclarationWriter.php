<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

use Llmor\Cli\Manifest\AppDefinition;
use Llmor\Cli\Manifest\FunctionLink;
use Llmor\Cli\Manifest\SubagentDefinition;
use stdClass;

/**
 * Renders an {@see AppDefinition} back into the `name: App { … }` declaration it would
 * have been parsed from.
 *
 * Taking the same value object {@see \Llmor\Cli\Manifest\Builder\AppDefinitionBuilder}
 * produces — rather than a raw API payload — is what keeps this class free of HTTP and
 * makes the guarantee that matters testable in one line: emit a definition, parse the
 * result, and the two must be equal.
 *
 * Layout follows the hand-written declarations in `example/llmor.scsc`: aligned scalar
 * keys first, then a blank line before each structured block.
 */
final class AppDeclarationWriter
{
    private readonly ParameterEmitter $emitter;

    /**
     * @param ?ValueExtractor $extractor null keeps every value inline, which is what
     *                                   the round-trip tests want
     */
    public function __construct(private readonly ?ValueExtractor $extractor = null)
    {
        $this->emitter = new ParameterEmitter();
    }

    public function write(AppDefinition $app): WrittenDeclaration
    {
        $extracted = $this->extract($app);

        $withFiles = $this->render($app, $extracted);
        // The same declaration with the extracted values written out literally, so the
        // emission can be verified before the files it points at exist.
        $inline = [] === $extracted ? $withFiles : $this->render($app, []);

        $files = [];
        foreach ($extracted as $key => $path) {
            $files[$path] = self::stringParameter($app->parameters, $key);
        }

        return new WrittenDeclaration(
            declaration: $app->declaration,
            scsc: $withFiles['scsc'],
            inline: $inline['scsc'],
            files: $files,
            notes: $withFiles['notes'],
        );
    }

    /**
     * Top-level parameter keys whose value belongs in a file, key => relative path.
     *
     * @return array<string, string>
     */
    private function extract(AppDefinition $app): array
    {
        if (null === $this->extractor) {
            return [];
        }

        $paths = [];
        $used = [];

        foreach (\get_object_vars($app->parameters) as $key => $value) {
            $key = (string) $key;
            $path = $this->extractor->pathFor($app->declaration, $key, $value);
            if (null === $path) {
                continue;
            }

            // Two keys can sanitise to one filename (`top.p` and `top_p`), and silently
            // writing one over the other would corrupt both — so ask the extractor for
            // the next name rather than editing the one it just composed.
            for ($ordinal = 2; isset($used[$path]); ++$ordinal) {
                $path = (string) $this->extractor->pathFor($app->declaration, $key, $value, $ordinal);
            }

            $used[$path] = true;
            $paths[$key] = $path;
        }

        return $paths;
    }

    /**
     * @param array<string, string> $extracted
     *
     * @return array{scsc: string, notes: list<string>}
     */
    private function render(AppDefinition $app, array $extracted): array
    {
        $block = new ScscBlock(1);
        $notes = [];

        $this->writeScalars($block, $app);

        if (0 !== $app->parameterCount()) {
            $emitted = $this->parameters($app, $extracted);
            \array_push($notes, ...$emitted->notes);
            $block->blank()->entry(ScscEncoder::bracketKey('parameters'), $emitted->scsc ?? '{}');
        }

        if (null !== $app->functions) {
            $emitted = $this->functions($app->functions, $block->depth());
            \array_push($notes, ...$emitted->notes);
            $block->blank()->entry(ScscEncoder::bracketKey('functions'), $emitted->scsc ?? '{}');
        }

        if (null !== $app->subagents) {
            $block->blank()->entry(ScscEncoder::bracketKey('subagents'), $this->subagents($app->subagents, $block->depth()));
        }

        return [
            'scsc' => \sprintf('%s: App %s', $app->declaration, $block->braced()),
            'notes' => $notes,
        ];
    }

    /**
     * The scalar keys, aligned, in the order `example/llmor.scsc` writes them.
     */
    private function writeScalars(ScscBlock $block, AppDefinition $app): void
    {
        $values = ['app_key' => ScscEncoder::string($app->appKey)];

        foreach (['name' => $app->name, 'description' => $app->description, 'model' => $app->model] as $key => $value) {
            if (null !== $value) {
                $values[$key] = ScscEncoder::string($value);
            }
        }

        if (null !== $app->id) {
            $values['id'] = (string) $app->id;
        }

        self::writeAligned($block, $values);
    }

    /**
     * @param array<string, string> $extracted
     */
    private function parameters(AppDefinition $app, array $extracted): EmittedValue
    {
        $bag = new stdClass();
        $annotations = [];

        foreach (\get_object_vars($app->parameters) as $key => $value) {
            $key = (string) $key;
            $path = $extracted[$key] ?? null;

            if (null === $path) {
                $bag->{$key} = $value;
                continue;
            }

            // `@file` replaces whatever the entry declares inline, and an entry with no
            // value at all is rejected by ParameterTree — hence the empty string.
            $bag->{$key} = '';
            $annotations[$key] = './'.$path;
        }

        return $this->emitter->bag($bag, '[parameters]', 1, $annotations);
    }

    /**
     * `[functions]` in the uniform bracket shape.
     *
     * A `{ … }` block has to commit to one shape throughout — bare names or
     * `[name] = { … }` entries, never both — and the bracket form is the one that is
     * always legal, so it is the only one emitted.
     *
     * @param list<FunctionLink> $links
     */
    private function functions(array $links, int $depth): EmittedValue
    {
        if ([] === $links) {
            return new EmittedValue('{}');
        }

        $block = new ScscBlock($depth + 1);
        $notes = [];

        foreach ($links as $link) {
            $config = $this->emitter->bag($link->config, \sprintf('[functions] → %s', $link->name), $block->depth());
            \array_push($notes, ...$config->notes);

            $block->entry(ScscEncoder::bracketKey($link->name), $config->scsc ?? '{}');
        }

        return new EmittedValue($block->braced(), $notes);
    }

    /**
     * @param list<SubagentDefinition> $subagents
     */
    private function subagents(array $subagents, int $depth): string
    {
        if ([] === $subagents) {
            return '{}';
        }

        $block = new ScscBlock($depth + 1);

        foreach ($subagents as $subagent) {
            $block->entry(ScscEncoder::bracketKey($subagent->alias), $this->subagent($subagent, $block->depth()));
        }

        return $block->braced();
    }

    private function subagent(SubagentDefinition $subagent, int $depth): string
    {
        // A numeric target addresses an app outside this manifest; a name has to be a
        // bare identifier, which every declaration name already is.
        $fields = ['app' => null !== $subagent->targetId ? (string) $subagent->targetId : $subagent->target];

        if ('' !== $subagent->description) {
            $fields['description'] = ScscEncoder::string($subagent->description);
        }

        if ($subagent->exposeAsTool) {
            $fields['expose_as_tool'] = 'true';
        }

        // Only non-empty text fields are written. The API stores '' for an absent field
        // and AppSynchronizer sends '' for an undeclared one, so this is identical on
        // the wire — and leaves the declaration free of empty noise.
        foreach ([
            'tool_name' => $subagent->toolName,
            'tool_description' => $subagent->toolDescription,
            'input_description' => $subagent->inputDescription,
        ] as $key => $value) {
            if ('' !== $value) {
                $fields[$key] = ScscEncoder::string($value);
            }
        }

        $block = new ScscBlock($depth + 1);
        self::writeAligned($block, $fields);

        return $block->braced();
    }

    /**
     * Bracket-keyed entries with their `=` lined up, the way the hand-written
     * declarations in `example/llmor.scsc` read.
     *
     * @param array<string, string> $fields key => already-encoded value
     */
    private static function writeAligned(ScscBlock $block, array $fields): void
    {
        $width = 0;
        foreach (\array_keys($fields) as $key) {
            $width = \max($width, \strlen($key) + 2);
        }

        foreach ($fields as $key => $value) {
            $block->line(\sprintf('%s = %s', \str_pad('['.$key.']', $width), $value));
        }
    }

    private static function stringParameter(stdClass $bag, string $key): string
    {
        $value = \get_object_vars($bag)[$key] ?? null;

        return \is_string($value) ? $value : '';
    }
}
