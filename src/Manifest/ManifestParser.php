<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use ClanCats\SchemaScript\Lexer;
use ClanCats\SchemaScript\Node\MetadataEntryNode;
use ClanCats\SchemaScript\Node\MetadataListNode;
use ClanCats\SchemaScript\Node\ModelDefinitionNode;
use ClanCats\SchemaScript\Node\ScopeNode;
use ClanCats\SchemaScript\Node\Type\GenericTypeNode;
use ClanCats\SchemaScript\Node\Type\SimpleTypeNode;
use ClanCats\SchemaScript\Parser\ScopeParser;
use Throwable;

/**
 * Parses an `llmor.scsc` manifest into typed {@see FunctionDefinition}s.
 *
 * Parsing stops at the AST level (Lexer → ScopeParser): the `name: Function`
 * parent-type tag — our discriminator — is resolved away and discarded by the
 * SchemaScript evaluator, but it is preserved on the raw {@see ModelDefinitionNode}.
 * Staying at the AST level also means a manifest never has to declare the
 * `Function` type or import a stdlib.
 */
final class ManifestParser
{
    /** The parent type that marks a declaration as a syncable function. */
    public const FUNCTION_TYPE = 'Function';

    /** Mirrors the server-side `function_key` validation. */
    private const KEY_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /** @var list<string> */
    private const RUNTIMES = ['silicon', 'graph'];

    private const MAX_NAME_LENGTH = 144;
    private const MAX_DESCRIPTION_LENGTH = 1080;

    /**
     * @throws ManifestException
     */
    public function parseFile(string $path): FunctionManifest
    {
        $code = @\file_get_contents($path);
        if (false === $code) {
            throw new ManifestException(\sprintf('Cannot read manifest "%s".', $path));
        }

        return $this->parse($code, $path, \dirname($path));
    }

    /**
     * @param string $baseDir directory that relative `srcdir` paths resolve against
     *
     * @throws ManifestException
     */
    public function parse(string $code, string $path, string $baseDir): FunctionManifest
    {
        try {
            $tokens = (new Lexer($code, $path))->tokens();
            $scope = (new ScopeParser($tokens))->parse();
        } catch (Throwable $e) {
            throw new ManifestException(\sprintf('Failed to parse manifest "%s": %s', $path, $e->getMessage()), 0, $e);
        }

        \assert($scope instanceof ScopeNode);

        $functions = [];
        $seen = [];
        foreach ($scope->getModels() as $model) {
            if (!$this->isFunction($model)) {
                continue;
            }

            $function = $this->buildFunction($model, $path, $baseDir);

            if (isset($seen[$function->functionKey])) {
                throw new ManifestException(\sprintf('Duplicate function "%s" in manifest "%s".', $function->functionKey, $path));
            }
            $seen[$function->functionKey] = true;
            $functions[] = $function;
        }

        return new FunctionManifest($path, $functions);
    }

    private function isFunction(ModelDefinitionNode $model): bool
    {
        foreach ($model->getParentTypes() as $parent) {
            if (($parent instanceof SimpleTypeNode || $parent instanceof GenericTypeNode)
                && self::FUNCTION_TYPE === $parent->getName()) {
                return true;
            }
        }

        return false;
    }

    private function buildFunction(ModelDefinitionNode $model, string $path, string $baseDir): FunctionDefinition
    {
        $key = $model->getName();
        if (1 !== \preg_match(self::KEY_PATTERN, $key)) {
            throw $this->invalid($path, $key, 'the declaration name must match /^[a-zA-Z_][a-zA-Z0-9_]*$/');
        }

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

        $name = $this->require($meta, 'name', $path, $key);
        $description = $this->require($meta, 'description', $path, $key);
        $runtime = $this->require($meta, 'runtime', $path, $key);
        $srcdir = $this->require($meta, 'srcdir', $path, $key);
        $entry = $this->require($meta, 'entry', $path, $key);

        if (\mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw $this->invalid($path, $key, \sprintf('[name] must be at most %d characters', self::MAX_NAME_LENGTH));
        }
        if (\mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw $this->invalid($path, $key, \sprintf('[description] must be at most %d characters', self::MAX_DESCRIPTION_LENGTH));
        }
        if (!\in_array($runtime, self::RUNTIMES, true)) {
            throw $this->invalid($path, $key, \sprintf('[runtime] must be one of %s', \implode(', ', self::RUNTIMES)));
        }

        $srcdirPath = $this->resolvePath($baseDir, $srcdir);
        if (!\is_dir($srcdirPath)) {
            throw $this->invalid($path, $key, \sprintf('[srcdir] "%s" is not a directory', $srcdirPath));
        }

        $entryPath = $srcdirPath.\DIRECTORY_SEPARATOR.$entry;
        if (!\is_file($entryPath)) {
            throw $this->invalid($path, $key, \sprintf('[entry] "%s" does not exist', $entryPath));
        }

        $copies = $this->buildCopies($copyEntries, $path, $key, $baseDir);

        return new FunctionDefinition(
            functionKey: $key,
            name: $name,
            description: $description,
            runtime: $runtime,
            srcdir: $srcdir,
            entry: $entry,
            srcdirPath: $srcdirPath,
            entryPath: $entryPath,
            copies: $copies,
        );
    }

    /**
     * Resolve one or more `[copy]` directives into validated {@see CopyInstruction}s. A
     * function may declare several `[copy]` blocks, each with its own optional `@path('dir/')`
     * annotation giving that block's destination directory; each source's basename is appended
     * to it. Sources resolve relative to the manifest directory. Destinations are deduplicated
     * across **all** blocks, so a collision between two blocks is an error.
     *
     * @param list<MetadataEntryNode> $entries
     *
     * @return list<CopyInstruction>
     */
    private function buildCopies(array $entries, string $path, string $key, string $baseDir): array
    {
        $copies = [];
        $seen = [];
        foreach ($entries as $entry) {
            $destDir = $this->copyDestDir($entry, $path, $key);

            $value = $entry->getValue();
            $items = $value instanceof MetadataListNode ? $value->getItems() : [$value];

            foreach ($items as $item) {
                $source = MetadataValueReader::asString($item);
                if (null === $source || '' === $source) {
                    throw $this->invalid($path, $key, '[copy] must be a list of non-empty source path strings');
                }

                $sourcePath = $this->resolvePath($baseDir, $source);
                if (!\is_file($sourcePath)) {
                    throw $this->invalid($path, $key, \sprintf('[copy] source "%s" does not exist', $sourcePath));
                }

                $destination = ('' === $destDir ? '' : $destDir.'/').\basename($source);
                if (isset($seen[$destination])) {
                    throw $this->invalid($path, $key, \sprintf('[copy] destination "%s" is declared more than once', $destination));
                }
                $seen[$destination] = true;

                $copies[] = new CopyInstruction($sourcePath, $destination);
            }
        }

        return $copies;
    }

    /**
     * Resolve a `[copy]` block's destination directory from its optional `@path('dir/')`
     * annotation, with surrounding slashes trimmed. Returns '' when no annotation is present
     * (copied files land at the function root).
     */
    private function copyDestDir(MetadataEntryNode $entry, string $path, string $key): string
    {
        $destDir = '';
        foreach ($entry->getAnnotations() as $annotation) {
            if ('path' !== $annotation->getName()) {
                continue;
            }
            $arguments = $annotation->getArguments();
            $destDir = MetadataValueReader::asString($arguments[0] ?? null);
            if (null === $destDir) {
                throw $this->invalid($path, $key, '@path(...) requires a single string directory argument');
            }
            break;
        }

        return \trim($destDir, '/');
    }

    /**
     * @param array<string, string> $meta
     */
    private function require(array $meta, string $field, string $path, string $key): string
    {
        $value = $meta[$field] ?? '';
        if ('' === $value) {
            throw $this->invalid($path, $key, \sprintf('[%s] is required and must be a non-empty string', $field));
        }

        return $value;
    }

    private function resolvePath(string $baseDir, string $srcdir): string
    {
        $srcdir = \rtrim($srcdir, '/\\');

        if ('' !== $srcdir && ('/' === $srcdir[0] || 1 === \preg_match('/^[A-Za-z]:[\\\\\/]/', $srcdir))) {
            return $srcdir;
        }

        return \rtrim($baseDir, '/\\').\DIRECTORY_SEPARATOR.\ltrim($srcdir, '/\\');
    }

    private function invalid(string $path, string $key, string $reason): ManifestException
    {
        return new ManifestException(\sprintf('Invalid function "%s" in manifest "%s": %s.', $key, $path, $reason));
    }
}
