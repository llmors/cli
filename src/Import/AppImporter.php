<?php

declare(strict_types=1);

namespace Llmor\Cli\Import;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Manifest\Manifest;
use Llmor\Cli\Manifest\ManifestException;
use Llmor\Cli\Manifest\ManifestParser;
use Llmor\Cli\Manifest\Writer\AppDeclarationWriter;
use Llmor\Cli\Manifest\Writer\ManifestAppender;
use Llmor\Cli\Manifest\Writer\WrittenDeclaration;
use Llmor\Cli\Sync\AppLockFile;
use Llmor\Cli\Sync\ParameterMerger;
use Llmor\Cli\Sync\SyncException;
use stdClass;

/**
 * Plans and applies the import of one remote app: read → map → emit → verify → write.
 *
 * Free of `InputInterface`/`OutputInterface` on purpose, so the whole decision can be
 * tested — and previewed by `--dry-run` — without a console.
 *
 * The verification step is the reason this class exists rather than a handful of calls
 * in the command. Before anything touches disk, the *prospective whole manifest* is
 * parsed and the declaration read back out, which in one go proves that the emitted
 * text lexes, that no value was mangled on the way out, that the name does not collide,
 * that the sub-agent targets resolve, and that the declarations already in the file
 * still parse. An `apps:import` that succeeds cannot leave a manifest `sync` can't read.
 */
final class AppImporter
{
    public function __construct(
        private readonly RemoteAppReader $reader,
        private readonly RemoteAppMapper $mapper,
        private readonly AppDeclarationWriter $writer,
        private readonly ManifestAppender $appender,
        private readonly AppLockFile $lock,
        private readonly string $vendorKey,
    ) {
    }

    /**
     * @throws ApiException
     * @throws ImportException
     * @throws ManifestException
     */
    public function plan(int $appId, string $declaration): ImportPlan
    {
        $mapped = $this->mapper->toDefinition(
            $declaration,
            $appId,
            $this->reader->record($appId),
            $this->reader->subagents($appId),
        );

        $written = $this->writer->write($mapped->definition);
        $this->verify($written, $declaration, $mapped);

        return new ImportPlan(
            appId: $appId,
            definition: $mapped->definition,
            written: $written,
            manifestPath: $this->appender->path,
            lockPath: $this->lock->path,
            manifestExists: $this->appender->exists(),
            warnings: $mapped->warnings,
            skipped: $mapped->skipped,
        );
    }

    /**
     * @throws ImportException
     * @throws SyncException
     */
    public function apply(ImportPlan $plan): void
    {
        $this->writeFiles($plan->written);

        // Now that the `@file` sources exist, the real declaration has to parse too —
        // this is what catches a bad extraction path or an unreadable file.
        $this->parse($this->appender->compose($plan->written->scsc), 'the extracted files');

        $this->appender->append($plan->written->scsc);

        // Last, because it is the one write that is safe to repeat, and because an app
        // recorded against a manifest that failed to save would be misleading.
        $this->lock->record($this->vendorKey, $plan->declaration(), $plan->appId, $plan->definition->appKey);
    }

    /**
     * Parse the manifest this import *would* produce, and check the declaration comes
     * back as the one that went in.
     *
     * The inline variant is used because it needs nothing on disk, so a failure here
     * costs no cleanup.
     *
     * @throws ImportException
     */
    private function verify(WrittenDeclaration $written, string $declaration, MappedApp $mapped): void
    {
        $manifest = $this->parse($this->appender->compose($written->inline), 'the generated declaration');
        $back = $manifest->getApp($declaration);

        if (null === $back) {
            throw new ImportException(\sprintf('The generated declaration for "%s" did not parse back as an app. This is a bug in the CLI; nothing was written.', $declaration));
        }

        $expected = $mapped->definition;
        $same = $back->appKey === $expected->appKey
            && $back->name === $expected->name
            && $back->description === $expected->description
            && $back->model === $expected->model
            && $back->functions == $expected->functions
            && $back->subagents == $expected->subagents
            && self::parametersAgree($expected->parameters, $back->parameters);

        if (!$same) {
            throw new ImportException(\sprintf('The generated declaration for "%s" does not read back as the app it describes. This is a bug in the CLI; nothing was written.', $declaration));
        }
    }

    /**
     * Whether the emitted `[parameters]` say nothing the app does not already say.
     *
     * Not plain equality: a key the grammar cannot spell is deliberately left out, so
     * the re-parsed bag is allowed to be a *subset*. What must hold is the property that
     * makes leaving it out safe — merging the declared overrides over the app's own bag
     * changes nothing, which is exactly the comparison `sync` will make.
     */
    private static function parametersAgree(stdClass $expected, stdClass $emitted): bool
    {
        $bag = ParameterMerger::bag($expected);

        return ParameterMerger::equals($bag, ParameterMerger::merge($bag, $emitted));
    }

    /**
     * @throws ImportException
     */
    private function parse(string $code, string $subject): Manifest
    {
        try {
            return (new ManifestParser())->parse($code, $this->appender->path, $this->appender->directory());
        } catch (ManifestException $e) {
            throw new ImportException(\sprintf('Nothing was written: %s could not be parsed. %s', $subject, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @throws ImportException
     */
    private function writeFiles(WrittenDeclaration $written): void
    {
        foreach ($written->files as $relative => $contents) {
            $absolute = $this->appender->directory().\DIRECTORY_SEPARATOR.\str_replace('/', \DIRECTORY_SEPARATOR, $relative);

            // Re-importing must not rewrite a file whose content is already right, and
            // must never quietly replace one that isn't — it may be hand-edited.
            if (\is_file($absolute)) {
                if (@\file_get_contents($absolute) === $contents) {
                    continue;
                }

                throw new ImportException(\sprintf('"%s" already exists with different content — nothing was written. Move it aside, or import under another name with --as.', $relative));
            }

            $directory = \dirname($absolute);
            if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true) && !\is_dir($directory)) {
                throw new ImportException(\sprintf('Cannot create "%s".', $directory));
            }

            if (false === @\file_put_contents($absolute, $contents)) {
                throw new ImportException(\sprintf('Cannot write "%s".', $relative));
            }
        }
    }
}
