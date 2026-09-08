<?php

declare(strict_types=1);

namespace Llmor\Cli\Command;

use JsonException;
use Llmor\Cli\Client\Exception\ApiException;
use Llmor\Cli\Client\Exception\ValidationException;
use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Sync\ValidationErrorFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

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
     * An option as a non-empty string, or null — the shape most options actually have.
     *
     * Symfony hands back `mixed`, and "absent" and "given as an empty string" mean the
     * same thing to every caller here, so this is the one place that decides it.
     */
    protected static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Merge `key=value` pairs over an optional JSON object, the pairs winning.
     *
     * The shape every command that takes structured input from the command line uses:
     * a `--*-json` option for the whole object, plus repeatable `key=value` pairs for
     * the one field you want to override without retyping the rest.
     *
     * @param array<int, string> $pairs
     *
     * @return array<string, mixed>
     *
     * @throws JsonException when the JSON is invalid or not an object, or a pair has no `=`
     */
    protected function mergeKeyValues(mixed $json, array $pairs): array
    {
        $base = [];
        if (\is_string($json) && '' !== $json) {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                throw new JsonException('Expected a JSON object.');
            }
            $base = $decoded;
        }

        foreach ($pairs as $pair) {
            $eq = \strpos($pair, '=');
            if (false === $eq) {
                throw new JsonException(\sprintf('Invalid key=value pair: "%s".', $pair));
            }
            $base[\substr($pair, 0, $eq)] = \substr($pair, $eq + 1);
        }

        return $base;
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
