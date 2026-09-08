<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Builder;

use ClanCats\SchemaScript\Node\ModelDefinitionNode;
use Llmor\Cli\Manifest\ConfigDefinition;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\MetadataValueReader;

/**
 * Builds the {@see ConfigDefinition} from a `name: Config { … }` declaration.
 */
final class ConfigDefinitionBuilder
{
    /**
     * Every key the block accepts. An unknown one is an error rather than ignored: a
     * mistyped `[prompts_dir]` would otherwise look exactly like the setting not working.
     *
     * @var list<string>
     */
    private const KEYS = ['prompt_dir'];

    /**
     * @throws ManifestException
     */
    public function build(ModelDefinitionNode $model, DeclarationContext $ctx): ConfigDefinition
    {
        $ctx->assertValidKey();

        /** @var array<string, string> $meta */
        $meta = [];

        foreach ($model->getMetadata() as $entry) {
            $key = $entry->getKey();

            if (!\in_array($key, self::KEYS, true)) {
                throw $ctx->invalid(\sprintf('[%s] is not a setting — this block accepts %s', $key, \implode(', ', \array_map(static fn (string $k): string => '['.$k.']', self::KEYS))));
            }

            $value = MetadataValueReader::asString($entry->getValue());
            if (null !== $value) {
                $meta[$key] = $value;
            }
        }

        return new ConfigDefinition(
            promptDir: isset($meta['prompt_dir'])
                ? $this->directory($meta['prompt_dir'], $ctx, 'prompt_dir')
                : ConfigDefinition::DEFAULT_PROMPT_DIR,
            declaration: $ctx->key,
        );
    }

    /**
     * Normalise a declared directory, and refuse one that cannot mean a place inside
     * the project.
     *
     * Both consumers need that: the path is joined onto the manifest directory when the
     * files are written, and it goes into an `@file('./…')` reference that is resolved
     * relative to the manifest again. An absolute path would produce a reference that
     * only works on the machine that imported, and `..` would write outside the project
     * while claiming otherwise.
     *
     * @throws ManifestException
     */
    private function directory(string $value, DeclarationContext $ctx, string $field): string
    {
        $path = \trim(\str_replace('\\', '/', $value));

        while (\str_starts_with($path, './')) {
            $path = \substr($path, 2);
        }

        $path = \trim($path, '/');

        if ('' === $path || '.' === $path) {
            throw $ctx->invalid(\sprintf('[%s] must name a directory, like \'./prompts\'', $field));
        }

        if (\str_starts_with($value, '/') || 1 === \preg_match('/^[A-Za-z]:[\\\\\/]/', $value) || \in_array('..', \explode('/', $path), true)) {
            throw $ctx->invalid(\sprintf('[%s] "%s" must be a relative directory inside the project — no ".." and no absolute path', $field, $value));
        }

        return $path;
    }
}
