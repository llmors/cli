<?php

declare(strict_types=1);

namespace Llmor\Cli\Command;

use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\Exception\ValidationException;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Sync\ValidationErrorFormatter;
use Symfony\Component\Console\Command\Command;

/**
 * Shared helpers for CLI commands: consistent API-error rendering and small
 * value/JSON formatting utilities.
 */
abstract class AbstractCommand extends Command
{
    /**
     * Render an API error in a friendly way and return a failure exit code.
     */
    protected function renderApiError(OutputStyle $io, ApiException $e): int
    {
        $io->error($e->getMessage());

        if ($e instanceof ValidationException) {
            $pairs = [];
            foreach (ValidationErrorFormatter::clean($e->errors()) as $field => $messages) {
                $pairs[ValidationErrorFormatter::label($field)] = \implode('; ', $messages);
            }
            if ([] !== $pairs) {
                $io->kv($pairs);
            }
        }

        return Command::FAILURE;
    }

    /**
     * Pretty-print a value as JSON.
     */
    protected function encodeJson(mixed $value): string
    {
        return \json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * Render a scalar/array value as a compact, table-friendly string.
     */
    protected static function stringify(mixed $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            null === $value => '',
            \is_scalar($value) => (string) $value,
            default => (string) \json_encode($value, \JSON_UNESCAPED_SLASHES),
        };
    }
}
