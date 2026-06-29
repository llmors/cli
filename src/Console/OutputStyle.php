<?php

declare(strict_types=1);

namespace Llmor\Cli\Console;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI's house style: a modern, compact replacement for Symfony's heavy
 * default rendering. Status lines use colored Unicode glyphs (✓ ✗ ! ↳),
 * secondary text is muted, tables are borderless aligned columns, and headings
 * are quiet labels rather than underlined banners.
 *
 * It extends {@see SymfonyStyle} so it stays a drop-in: existing call sites
 * (success/error/table/...) keep working but render in the compact style, and
 * helpers that type-hint SymfonyStyle accept it unchanged. Color tags degrade
 * gracefully when output is not decorated (piped / --no-ansi / NO_COLOR).
 */
final class OutputStyle extends SymfonyStyle
{
    private readonly OutputInterface $output;

    public function __construct(InputInterface $input, OutputInterface $output)
    {
        parent::__construct($input, $output);
        $this->output = $output;

        $formatter = $output->getFormatter();
        $formatter->setStyle('ok', new OutputFormatterStyle('green'));
        $formatter->setStyle('bad', new OutputFormatterStyle('red'));
        $formatter->setStyle('warn', new OutputFormatterStyle('yellow'));
        // Use the terminal's default foreground for secondary text: ANSI "gray"
        // (bright-black) is invisible on themes that map it near the background,
        // and Symfony exposes no portable faint/dim attribute. Hierarchy comes
        // from glyphs and layout instead of a dim color.
        $formatter->setStyle('muted', new OutputFormatterStyle());
        $formatter->setStyle('accent', new OutputFormatterStyle('cyan'));
    }

    /**
     * @param string|array<int, string> $message
     */
    public function success(string|array $message): void
    {
        foreach ((array) $message as $line) {
            $this->writeln(\sprintf('<ok>✓</ok> %s', $line));
        }
        $this->newLine();
    }

    /**
     * @param string|array<int, string> $message
     */
    public function error(string|array $message): void
    {
        foreach ((array) $message as $line) {
            $this->writeln(\sprintf('<bad>✗</bad> %s', $line));
        }
        $this->newLine();
    }

    /**
     * @param string|array<int, string> $message
     */
    public function warning(string|array $message): void
    {
        foreach ((array) $message as $line) {
            $this->writeln(\sprintf('<warn>!</warn> %s', $line));
        }
    }

    /**
     * @param string|array<int, string> $message
     */
    public function caution(string|array $message): void
    {
        $this->error($message);
    }

    /**
     * @param string|array<int, string> $message
     */
    public function note(string|array $message): void
    {
        foreach ((array) $message as $line) {
            $this->writeln(\sprintf('<muted>%s</muted>', $line));
        }
    }

    /**
     * @param string|array<int, string> $message
     */
    public function info(string|array $message): void
    {
        $this->note($message);
    }

    /**
     * A dim, indented continuation hint (e.g. how to fix an error).
     */
    public function hint(string $message): void
    {
        $this->writeln(\sprintf('  <muted>↳ %s</muted>', $message));
    }

    public function title(string $message): void
    {
        $this->section($message);
    }

    public function section(string $message): void
    {
        $this->newLine();
        $this->writeln(\sprintf('<accent>%s</accent>', $message));
        $this->newLine();
    }

    /**
     * A left-aligned label/value block: labels are dim and padded to the widest
     * key, values follow after a two-space gutter.
     *
     * @param array<string, string> $pairs
     */
    public function kv(array $pairs): void
    {
        if ([] === $pairs) {
            return;
        }

        $width = 0;
        foreach (\array_keys($pairs) as $label) {
            $width = \max($width, \mb_strlen($label));
        }

        foreach ($pairs as $label => $value) {
            $this->writeln(\sprintf(
                '  <muted>%s</muted>  %s',
                \str_pad($label, $width),
                $value,
            ));
        }
    }

    /**
     * @param string|TableSeparator|array<string, string> ...$list
     */
    public function definitionList(string|array|TableSeparator ...$list): void
    {
        $pairs = [];
        foreach ($list as $entry) {
            if (\is_array($entry)) {
                foreach ($entry as $label => $value) {
                    $pairs[(string) $label] = (string) $value;
                }
            }
        }

        $this->kv($pairs);
    }

    /**
     * @param array<int, string>             $headers
     * @param array<int, array<int, string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $table = new Table($this->output);
        $table->setStyle($this->borderlessStyle());
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();

        $this->newLine();
    }

    /**
     * A dim trailing line for counts, pagination and "how to" hints.
     */
    public function meta(string $message): void
    {
        $this->newLine();
        $this->writeln(\sprintf('<muted>%s</muted>', $message));
    }

    /**
     * Human-readable byte size: 1234 → "1.2 KB".
     */
    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return \sprintf('%d B', $bytes);
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return \sprintf('%.1f %s', $value, $units[$unit]);
    }

    private function borderlessStyle(): TableStyle
    {
        return (new TableStyle())
            ->setHorizontalBorderChars('')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('', '', '', '', '', '', '', '', '')
            ->setCellHeaderFormat('<options=bold>%s</>')
            ->setCellRowFormat('%s');
    }
}
