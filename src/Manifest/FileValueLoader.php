<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest;

use ClanCats\SchemaScript\Node\MetadataEntryNode;
use Llmor\Cli\Manifest\Builder\DeclarationContext;
use Llmor\Cli\Sync\FunctionLimits;

/**
 * Resolves the `@file('./path')` annotation on a metadata entry.
 *
 * App parameters carry things no one wants to paste into a manifest as an escaped
 * string — system prompts run to tens of kilobytes, and `code`/`output_json_schema`
 * want their own syntax highlighting. The `file` annotation lets any entry take its
 * value from a file next to the manifest instead, so those live in the repo as
 * ordinary, diffable files:
 *
 *     [parameters] = { @file('./prompts/support.md') prompt = '' }
 *
 * The declared inline value is ignored when the annotation is present (there is no
 * way to write "no value" for an entry — a bare key parses as null, which we reject
 * elsewhere as a likely typo).
 */
final class FileValueLoader
{
    public const ANNOTATION = 'file';

    /**
     * The annotated file's contents, or null when the entry carries no `@file`.
     *
     * @param string $path dotted path of the entry, for error messages
     *
     * @throws ManifestException when the annotation is malformed or the file is unusable
     */
    public static function fromEntry(MetadataEntryNode $entry, DeclarationContext $ctx, string $path): ?string
    {
        $source = null;
        foreach ($entry->getAnnotations() as $annotation) {
            if (self::ANNOTATION !== $annotation->getName()) {
                continue;
            }

            $arguments = $annotation->getArguments();
            $source = MetadataValueReader::asString($arguments[0] ?? null);
            if (null === $source || '' === $source || 1 !== \count($arguments)) {
                throw $ctx->invalid(\sprintf('%s: @file(...) requires a single string path argument', $path));
            }
            break;
        }

        if (null === $source) {
            return null;
        }

        return self::read($ctx->resolvePath($source), $source, $ctx, $path);
    }

    /**
     * @throws ManifestException
     */
    private static function read(string $absolute, string $declared, DeclarationContext $ctx, string $path): string
    {
        if (!\is_file($absolute)) {
            throw $ctx->invalid(\sprintf('%s: @file source "%s" does not exist', $path, $absolute));
        }

        $size = @\filesize($absolute);
        if (false !== $size && $size > FunctionLimits::MAX_FILE_BYTES) {
            throw $ctx->invalid(\sprintf('%s: @file source "%s" is %d bytes, over the %d byte limit', $path, $declared, $size, FunctionLimits::MAX_FILE_BYTES));
        }

        $content = @\file_get_contents($absolute);
        if (false === $content) {
            throw $ctx->invalid(\sprintf('%s: @file source "%s" cannot be read', $path, $absolute));
        }

        // Values travel as JSON strings, so anything that isn't valid UTF-8 would
        // fail to encode later with a far less useful message.
        if (!\mb_check_encoding($content, 'UTF-8')) {
            throw $ctx->invalid(\sprintf('%s: @file source "%s" is not valid UTF-8 text', $path, $declared));
        }

        return $content;
    }
}
