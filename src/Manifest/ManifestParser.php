<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use ClanCats\SchemaScript\Lexer;
use ClanCats\SchemaScript\Node\ModelDefinitionNode;
use ClanCats\SchemaScript\Node\ScopeNode;
use ClanCats\SchemaScript\Node\Type\GenericTypeNode;
use ClanCats\SchemaScript\Node\Type\SimpleTypeNode;
use ClanCats\SchemaScript\Parser\ScopeParser;
use Llmor\Cli\Manifest\Builder\AppDefinitionBuilder;
use Llmor\Cli\Manifest\Builder\DeclarationContext;
use Llmor\Cli\Manifest\Builder\FunctionDefinitionBuilder;
use Throwable;

/**
 * Parses an `llmor.scsc` manifest into typed declarations.
 *
 * Parsing stops at the AST level (Lexer → ScopeParser): the `name: Function` /
 * `name: App` parent-type tag — our discriminator — is resolved away and discarded by the
 * SchemaScript evaluator, but it is preserved on the raw {@see ModelDefinitionNode}.
 * Staying at the AST level also means a manifest never has to declare the
 * `Function`/`App` types or import a stdlib.
 *
 * This class only lexes, discriminates by parent type and dispatches; each kind of
 * declaration is built by its own builder under {@see Builder}.
 */
final class ManifestParser
{
    /** The parent type that marks a declaration as a syncable function. */
    public const FUNCTION_TYPE = 'Function';

    /** The parent type that marks a declaration as a syncable app. */
    public const APP_TYPE = 'App';

    /**
     * @throws ManifestException
     */
    public function parseFile(string $path): Manifest
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
    public function parse(string $code, string $path, string $baseDir): Manifest
    {
        $scope = $this->parseScope($code, $path);

        $functionBuilder = new FunctionDefinitionBuilder();
        $appBuilder = new AppDefinitionBuilder();

        $functions = [];
        $apps = [];
        $seen = [];

        foreach ($scope->getModels() as $model) {
            $kind = match (true) {
                self::hasParentType($model, self::FUNCTION_TYPE) => 'function',
                self::hasParentType($model, self::APP_TYPE) => 'app',
                default => null,
            };

            if (null === $kind) {
                continue;
            }

            $ctx = new DeclarationContext($kind, $model->getName(), $path, $baseDir);

            // Declaration names share one namespace: a function and an app cannot both
            // be called `support`, or a `[functions]` reference would be ambiguous.
            if (isset($seen[$model->getName()])) {
                throw new ManifestException(\sprintf('Duplicate declaration "%s" in manifest "%s".', $model->getName(), $path));
            }
            $seen[$model->getName()] = $kind;

            if ('function' === $kind) {
                $functions[] = $functionBuilder->build($model, $ctx);
            } else {
                $apps[] = $appBuilder->build($model, $ctx);
            }
        }

        $manifest = new Manifest($path, $functions, $apps);
        $this->linkDeclarations($manifest, $seen);

        return $manifest;
    }

    /**
     * Validate references between declarations, once every declaration is known.
     *
     * Doing this after the fact — the same way `[copy]` resolves only once all of its
     * blocks are collected — means a sub-agent may point at an app declared further
     * down the file, and a typo fails here rather than as a 400 halfway through a sync.
     *
     * @param array<string, string> $kinds declaration name => kind
     *
     * @throws ManifestException
     */
    private function linkDeclarations(Manifest $manifest, array $kinds): void
    {
        foreach ($manifest->apps as $app) {
            $ctx = new DeclarationContext('app', $app->declaration, $manifest->path, $manifest->directory());

            foreach ($app->functions ?? [] as $link) {
                // A key this manifest doesn't declare is legitimate — the function may
                // exist only remotely — but a key that names an *app* never is, and
                // finding out at sync time costs a vendor lookup and a function search
                // to arrive at advice ("sync without a filter first") that cannot help.
                if ('app' === ($kinds[$link->name] ?? null)) {
                    throw $ctx->invalid(\sprintf('[functions] → "%s" references an app, not a function', $link->name));
                }
            }

            foreach ($app->subagents ?? [] as $subagent) {
                // A numeric target addresses an app outside this manifest directly.
                if (null !== $subagent->targetId) {
                    continue;
                }

                $where = \sprintf('[subagents] → %s → [app]', $subagent->alias);

                if ($subagent->target === $app->declaration) {
                    throw $ctx->invalid(\sprintf('%s points at its own app — an app cannot delegate to itself', $where));
                }

                $kind = $kinds[$subagent->target] ?? null;

                if (null === $kind) {
                    throw $ctx->invalid(\sprintf('%s references undeclared app "%s"', $where, $subagent->target));
                }

                if ('app' !== $kind) {
                    throw $ctx->invalid(\sprintf('%s references "%s", which is a function, not an app', $where, $subagent->target));
                }
            }
        }
    }

    /**
     * @throws ManifestException
     */
    private function parseScope(string $code, string $path): ScopeNode
    {
        try {
            $tokens = (new Lexer($code, $path))->tokens();
            $scope = (new ScopeParser($tokens))->parse();
        } catch (Throwable $e) {
            throw new ManifestException(\sprintf('Failed to parse manifest "%s": %s%s', $path, $e->getMessage(), self::parseHint($e->getMessage())), 0, $e);
        }

        \assert($scope instanceof ScopeNode);

        return $scope;
    }

    /**
     * Turn one confusing SchemaScript error into an actionable one.
     *
     * A `{ … }` block has to commit to a single shape: either bare names, or
     * `[name] = { … }` entries. Mixing them makes the parser read the block as a list
     * and then trip over the first bracket key, which is a genuinely baffling message
     * for something people will write all the time.
     */
    private static function parseHint(string $message): string
    {
        if (!\str_contains($message, '(MetadataKey)') || !\str_contains($message, 'Unexpected token')) {
            return '';
        }

        return '. A "{ … }" block must use one shape throughout — either bare names'
            .' (newline- or comma-separated), or "[name] = { … }" entries, not both';
    }

    /**
     * Whether a declaration carries `: $type` as one of its parent types — the
     * discriminator that tells `foo: Function {…}` apart from any other model.
     */
    private static function hasParentType(ModelDefinitionNode $model, string $type): bool
    {
        foreach ($model->getParentTypes() as $parent) {
            if (($parent instanceof SimpleTypeNode || $parent instanceof GenericTypeNode)
                && $type === $parent->getName()) {
                return true;
            }
        }

        return false;
    }
}
