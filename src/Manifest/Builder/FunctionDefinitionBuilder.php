<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Builder;

use ClanCats\SchemaScript\Node\MetadataEntryNode;
use ClanCats\SchemaScript\Node\MetadataListNode;
use ClanCats\SchemaScript\Node\ModelDefinitionNode;
use Llmor\Cli\Manifest\CopyInstruction;
use Llmor\Cli\Manifest\CopyPatternExpander;
use Llmor\Cli\Manifest\FunctionDefinition;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\MetadataValueReader;

/**
 * Builds one {@see FunctionDefinition} from a `name: Function { … }` declaration.
 */
final class FunctionDefinitionBuilder
{
    /** @var list<string> */
    private const RUNTIMES = ['silicon', 'graph'];

    private const MAX_NAME_LENGTH = 144;
    private const MAX_DESCRIPTION_LENGTH = 1080;

    /**
     * @throws ManifestException
     */
    public function build(ModelDefinitionNode $model, DeclarationContext $ctx): FunctionDefinition
    {
        $ctx->assertValidKey();

        /** @var array<string, string> $meta */
        $meta = [];
        /** @var list<MetadataEntryNode> $copyEntries */
        $copyEntries = [];
        foreach ($model->getMetadata() as $entry) {
            if ('copy' === $entry->getKey()) {
                $copyEntries[] = $entry;
                continue;
            }
            $value = MetadataValueReader::asString($entry->getValue());
            if (null !== $value) {
                $meta[$entry->getKey()] = $value;
            }
        }

        $name = $ctx->require($meta, 'name');
        $description = $ctx->require($meta, 'description');
        $runtime = $ctx->require($meta, 'runtime');
        $srcdir = $ctx->require($meta, 'srcdir');
        $entry = $ctx->require($meta, 'entry');

        if (\mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw $ctx->invalid(\sprintf('[name] must be at most %d characters', self::MAX_NAME_LENGTH));
        }
        if (\mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw $ctx->invalid(\sprintf('[description] must be at most %d characters', self::MAX_DESCRIPTION_LENGTH));
        }
        if (!\in_array($runtime, self::RUNTIMES, true)) {
            throw $ctx->invalid(\sprintf('[runtime] must be one of %s', \implode(', ', self::RUNTIMES)));
        }

        $srcdirPath = $ctx->resolvePath($srcdir);
        if (!\is_dir($srcdirPath)) {
            throw $ctx->invalid(\sprintf('[srcdir] "%s" is not a directory', $srcdirPath));
        }

        $entryPath = $srcdirPath.\DIRECTORY_SEPARATOR.$entry;
        if (!\is_file($entryPath)) {
            throw $ctx->invalid(\sprintf('[entry] "%s" does not exist', $entryPath));
        }

        return new FunctionDefinition(
            functionKey: $ctx->key,
            name: $name,
            description: $description,
            runtime: $runtime,
            srcdir: $srcdir,
            entry: $entry,
            srcdirPath: $srcdirPath,
            entryPath: $entryPath,
            copies: $this->buildCopies($copyEntries, $ctx),
        );
    }

    /**
     * Resolve one or more `[copy]` directives into validated {@see CopyInstruction}s. A
     * function may declare several `[copy]` blocks, each with its own optional `@path('dir/')`
     * annotation giving that block's destination directory; each source's basename is appended
     * to it. A wildcard source appends the match's path relative to the pattern's fixed prefix
     * instead — see {@see CopyPatternExpander}. Sources resolve relative to the manifest
     * directory. Destinations are deduplicated across **all** blocks, so a collision between
     * two blocks is an error.
     *
     * @param list<MetadataEntryNode> $entries
     *
     * @return list<CopyInstruction>
     */
    private function buildCopies(array $entries, DeclarationContext $ctx): array
    {
        $copies = [];
        $seen = [];
        foreach ($entries as $entry) {
            $destDir = $this->copyDestDir($entry, $ctx);

            $value = $entry->getValue();
            $items = $value instanceof MetadataListNode ? $value->getItems() : [$value];

            foreach ($items as $item) {
                $source = MetadataValueReader::asString($item);
                if (null === $source || '' === $source) {
                    throw $ctx->invalid('[copy] must be a list of non-empty source path strings');
                }

                foreach ($this->resolveCopySource($source, $ctx) as $sourcePath => $suffix) {
                    $destination = ('' === $destDir ? '' : $destDir.'/').$suffix;
                    if (isset($seen[$destination])) {
                        throw $ctx->invalid(\sprintf('[copy] destination "%s" is declared more than once', $destination));
                    }
                    $seen[$destination] = true;

                    $copies[] = new CopyInstruction($sourcePath, $destination);
                }
            }
        }

        return $copies;
    }

    /**
     * Resolve one `[copy]` source into the files it names, mapped to the path each should
     * take under the block's `@path`.
     *
     * A literal source must exist and contributes its basename, so an explicit list keeps
     * behaving exactly as before. A wildcard source contributes every match's path relative
     * to the pattern's fixed prefix, preserving the source tree's shape. A pattern that
     * matches nothing is an error rather than a silent no-op: it is indistinguishable from a
     * typo, and letting it pass would quietly ship a bundle missing the docs it promised.
     *
     * @return array<string, string> absolute source path => path to append to `@path`
     *
     * @throws ManifestException
     */
    private function resolveCopySource(string $source, DeclarationContext $ctx): array
    {
        if (!CopyPatternExpander::isPattern($source)) {
            $sourcePath = $ctx->resolvePath($source);
            if (!\is_file($sourcePath)) {
                throw $ctx->invalid(\sprintf('[copy] source "%s" does not exist', $sourcePath));
            }

            return [$sourcePath => \basename($source)];
        }

        $matches = CopyPatternExpander::expand($ctx->baseDir, $source);
        if ([] === $matches) {
            throw $ctx->invalid(\sprintf('[copy] pattern "%s" matched no files', $source));
        }

        return $matches;
    }

    /**
     * Resolve a `[copy]` block's destination directory from its optional `@path('dir/')`
     * annotation, with surrounding slashes trimmed. Returns '' when no annotation is present
     * (copied files land at the function root).
     */
    private function copyDestDir(MetadataEntryNode $entry, DeclarationContext $ctx): string
    {
        $destDir = '';
        foreach ($entry->getAnnotations() as $annotation) {
            if ('path' !== $annotation->getName()) {
                continue;
            }
            $arguments = $annotation->getArguments();
            $destDir = MetadataValueReader::asString($arguments[0] ?? null);
            if (null === $destDir) {
                throw $ctx->invalid('@path(...) requires a single string directory argument');
            }
            break;
        }

        return \trim($destDir, '/');
    }
}
