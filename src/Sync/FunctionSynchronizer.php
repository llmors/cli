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
    private const PAGE_SIZE = 200;

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
            $result->functionAction = SyncResult::CREATED;
            if (!$dryRun) {
                // is_library is required by the API and not declared in the manifest.
                $created = $this->client->post($this->functionsPath(), $payload + ['is_library' => false])->data();
                $result->functionId = isset($created['id']) ? (int) $created['id'] : null;
            }
        } else {
            $result->functionId = (int) $existing['id'];
            if ($this->functionChanged($existing, $function, $code)) {
                $result->functionAction = SyncResult::UPDATED;
                if (!$dryRun) {
                    // Preserve the existing library flag — the manifest doesn't own it.
                    $this->client->put(
                        $this->functionPath($result->functionId),
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
        foreach ($this->fetchAll($this->functionsPath(), ['search' => $functionKey]) as $item) {
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
        foreach ($this->fetchAll($this->filesPath($functionId), []) as $item) {
            if (!isset($item['path'])) {
                continue;
            }
            $files[(string) $item['path']] = [
                'id' => (int) ($item['id'] ?? 0),
                'hash' => (string) ($item['content_hash'] ?? ''),
            ];
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function functionChanged(array $existing, FunctionDefinition $function, string $code): bool
    {
        return (string) ($existing['name'] ?? '') !== $function->name
            || (string) ($existing['description'] ?? '') !== $function->description
            || (string) ($existing['runtime'] ?? '') !== $function->runtime
            || (string) ($existing['code'] ?? '') !== $code;
    }

    /**
     * Fetch every page of a list endpoint (the server caps page size at 200).
     *
     * @param array<string, scalar|null> $query
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $path, array $query): array
    {
        $page = 0;
        $collected = [];
        do {
            $response = $this->client->get($path, $query + [
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'count' => 1,
            ]);

            $items = $response->data();
            foreach ($items as $item) {
                if (\is_array($item)) {
                    $collected[] = $item;
                }
            }

            $meta = $response->meta();
            $total = isset($meta['total_count']) ? (int) $meta['total_count'] : \count($collected);
            ++$page;
        } while ([] !== $items && \count($collected) < $total);

        return $collected;
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
