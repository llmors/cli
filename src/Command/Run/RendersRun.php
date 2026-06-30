<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\Run;

use Llmor\Cli\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Renders a single run/build result — status, timing, return value, console
 * output and any error. Shared by `run` (inline build) and `runs:show` (a
 * persisted run) so both present an execution identically.
 *
 * Methods rely only on {@see AbstractCommand::encodeJson()} and
 * {@see AbstractCommand::stringify()}, so the trait fits any command in that
 * hierarchy.
 */
trait RendersRun
{
    /** @var array<int, string> */
    private const STATUS_LABELS = [
        0 => 'pending',
        1 => 'success',
        -1 => 'error',
        -2 => 'timeout',
        -3 => 'memory limit exceeded',
        -4 => 'nothing returned',
        -5 => 'explicit failure',
        -6 => 'debug breakpoint',
    ];

    private function statusLabel(int $status): string
    {
        return self::STATUS_LABELS[$status] ?? \sprintf('unknown (%d)', $status);
    }

    /**
     * @param array<string, mixed> $run
     */
    private function renderRun(OutputStyle $io, string $name, array $run): void
    {
        $status = (int) ($run['status'] ?? 0);
        $label = $this->statusLabel($status);

        if (1 === $status) {
            $io->success(\sprintf('%s — %s', $name, $label));
        } else {
            $io->error(\sprintf('%s — %s', $name, $label));
        }

        $io->writeln(\sprintf(
            '<muted>status %s (%d) · %d ms · %s</muted>',
            $label,
            $status,
            (int) ($run['took'] ?? 0),
            OutputStyle::humanBytes((int) ($run['memory'] ?? 0)),
        ));

        if (\array_key_exists('result', $run)) {
            $io->section('Result');
            $io->writeln($this->renderResult($run['result']));
        }

        $console = $run['console'] ?? [];
        if (\is_array($console) && [] !== $console) {
            $io->section('Console');
            foreach ($console as $line) {
                $this->renderConsoleLine($io, $line);
            }
        }

        $error = $run['error'] ?? null;
        if (\is_array($error) && '' !== (string) ($error['message'] ?? '')) {
            $io->section('Error');
            $line = isset($error['line']) ? \sprintf(' (line %s)', self::stringify($error['line'])) : '';
            $io->writeln(self::stringify($error['message']).$line);
        }
    }

    /**
     * Format a run's return value for the human-readable Result pane. A string
     * is printed verbatim — real line breaks, no JSON quoting and no `\uXXXX`
     * escaping — with formatter tags escaped so arbitrary text (`<foo>`, the
     * `<error>` style, …) can't be consumed or recoloured by the console
     * formatter. Structured values fall back to pretty JSON, leaving unicode
     * and slashes intact for readability. The `--json` paths bypass this and
     * keep using {@see AbstractCommand::encodeJson()}.
     */
    private function renderResult(mixed $result): string
    {
        if (\is_string($result)) {
            return OutputFormatter::escape($result);
        }

        return (string) \json_encode(
            $result,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Render one console entry. The runtime emits `[level, message, timestamp]`
     * triples; print a dim timestamp gutter and the message with real line breaks,
     * indenting continuation lines so multi-line output stays aligned. Anything that
     * doesn't match the triple shape falls back to a compact stringified form.
     */
    private function renderConsoleLine(OutputStyle $io, mixed $line): void
    {
        if (!\is_array($line) || !\array_is_list($line) || \count($line) < 2) {
            $io->writeln(self::stringify($line));

            return;
        }

        $time = isset($line[2]) && \is_numeric($line[2]) ? \date('H:i:s', (int) $line[2]) : null;
        $gutter = null === $time ? '' : \sprintf('<muted>%s</muted>  ', $time);
        $indent = null === $time ? '' : \str_repeat(' ', \strlen($time) + 2);

        $lines = \explode("\n", self::stringify($line[1] ?? ''));
        foreach ($lines as $i => $text) {
            $io->writeln((0 === $i ? $gutter : $indent).$text);
        }
    }
}
