<?php

declare(strict_types=1);

namespace Llmor\Cli\Sync;

use Llmor\Cli\Client\LlmorClient;
use Llmor\Cli\Manifest\FunctionDefinition;

/**
 * Reconciles one declared {@see FunctionDefinition} with its remote state: creates
 * or updates the function record (entry file → `code`), then mirrors its `srcdir`
 * files. Files must be persisted before a `build` run can read them.
 */
final class FunctionSynchronizer
{
    public function __construct(
        private readonly LlmorClient $client,
        private readonly int $vendorId,
        private readonly LocalSourceCollector $collector = new LocalSourceCollector(),
    ) {
    }

    public function sync(FunctionDefinition $function, bool $prune = false, bool $dryRun = false): SyncResult
    {
        $result = new SyncResult($function->functionKey);
        $code = $function->readCode();
        $payload = [
            'name' => $function->name,
            'description' => $function->description,
            'function_key' => $function->functionKey,
            'runtime' => $function->runtime,
            'code' => $code,
        ];

        $existing = $this->findFunction($function->functionKey);

        if (null === $existing) {
            $result->functionAction = SyncOutcome::CREATED;
            if (!$dryRun) {
                // is_library is required by the API and not declared in the manifest.
                $created = $this->client->post($this->functionsPath(), $payload + ['is_library' => false])->data();
                $result->functionId = Json::idOf($created['id'] ?? null);
            }
        } else {
            $id = Json::idOf($existing['id'] ?? null);
            $result->functionId = $id;
            if (null !== $id && $this->functionChanged($existing, $function, $code)) {
                $result->functionAction = SyncOutcome::UPDATED;
                if (!$dryRun) {
                    // Preserve the existing library flag — the manifest doesn't own it.
                    $this->client->put(
                        $this->functionPath($id),
                        $payload + ['is_library' => (bool) ($existing['is_library'] ?? false)],
                    );
                }
            }
        }

        $this->syncFiles($function, $result, $prune, $dryRun);

        return $result;
    }

    /**
     * Find a function by its exact `function_key`, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function findFunction(string $functionKey): ?array
    {
        foreach (PagedList::fetchAll($this->client, $this->functionsPath(), ['search' => $functionKey]) as $item) {
            if (($item['function_key'] ?? null) === $functionKey) {
                return $item;
            }
        }

        return null;
    }

    private function syncFiles(FunctionDefinition $function, SyncResult $result, bool $prune, bool $dryRun): void
    {
        $collected = $this->collector->collect($function);
        $result->warnings = $collected->warnings;

        // A would-be-created function has no id yet (dry-run): everything is new.
        if (null === $result->functionId) {
            foreach ($collected->files as $file) {
                $result->filesCreated[] = $file->path;
            }

            return;
        }

        $remote = $this->listRemoteFiles($result->functionId);
        $localPaths = [];

        foreach ($collected->files as $file) {
            $localPaths[$file->path] = true;
            $existing = $remote[$file->path] ?? null;

            if (null === $existing) {
                if (!$dryRun) {
                    $this->client->post($this->filesPath($result->functionId), [
                        'path' => $file->path,
                        'content' => $file->content,
                    ]);
                }
                $result->filesCreated[] = $file->path;
            } elseif ($existing['hash'] !== $file->sha256) {
                if (!$dryRun) {
                    $this->client->put($this->filePath($result->functionId, $existing['id']), [
                        'path' => $file->path,
                        'content' => $file->content,
                    ]);
                }
                $result->filesUpdated[] = $file->path;
            } else {
                ++$result->filesUnchanged;
            }
        }

        foreach ($remote as $path => $info) {
            if (isset($localPaths[$path])) {
                continue;
            }
            if ($prune) {
                if (!$dryRun) {
                    $this->client->delete($this->filePath($result->functionId, $info['id']));
                }
                $result->filesDeleted[] = $path;
            } else {
                $result->warnings[] = \sprintf('Remote file "%s" has no local counterpart (use --prune to delete).', $path);
            }
        }
    }

    /**
     * @return array<string, array{id: int, hash: string}>
     */
    private function listRemoteFiles(int $functionId): array
    {
        $files = [];
        foreach (PagedList::fetchAll($this->client, $this->filesPath($functionId)) as $item) {
            if (!isset($item['path'])) {
                continue;
            }
            $files[Json::stringOf($item['path'])] = [
                'id' => Json::intOf($item['id'] ?? null),
                'hash' => Json::stringOf($item['content_hash'] ?? null),
            ];
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function functionChanged(array $existing, FunctionDefinition $function, string $code): bool
    {
        return Json::stringOf($existing['name'] ?? null) !== $function->name
            || Json::stringOf($existing['description'] ?? null) !== $function->description
            || Json::stringOf($existing['runtime'] ?? null) !== $function->runtime
            || Json::stringOf($existing['code'] ?? null) !== $code;
    }

    private function functionsPath(): string
    {
        return \sprintf('/v1/vendors/%d/functions', $this->vendorId);
    }

    private function functionPath(int $functionId): string
    {
        return $this->functionsPath().'/'.$functionId;
    }

    private function filesPath(int $functionId): string
    {
        return $this->functionPath($functionId).'/files';
    }

    private function filePath(int $functionId, int $fileId): string
    {
        return $this->filesPath($functionId).'/'.$fileId;
    }
}
