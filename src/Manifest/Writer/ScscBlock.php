<?php

declare(strict_types=1);

namespace Llmor\Cli\Manifest\Writer;

/**
 * An indentation-aware line accumulator, so nothing else in the writer does string
 * arithmetic to line values up.
 *
 * Values arriving from {@see ParameterEmitter} may already be multi-line; only their
 * *first* line gets the entry's indent, because the rest were rendered at the right
 * depth already (and because a literal newline inside a string must never be touched).
 */
final class ScscBlock
{
    private const INDENT = '  ';

    /** @var list<string> */
    private array $lines = [];

    public function __construct(private readonly int $depth = 0)
    {
    }

    public function depth(): int
    {
        return $this->depth;
    }

    /** `key = value`, where `$key` already carries its own ` = `. */
    public function entry(string $key, string $value): self
    {
        return $this->line($key.$value);
    }

    /** `@file('./x.md')` — an annotation applying to the entry written after it. */
    public function annotation(string $name, string $argument): self
    {
        return $this->line(\sprintf('@%s(%s)', $name, ScscEncoder::string($argument)));
    }

    public function line(string $text): self
    {
        $this->lines[] = $this->indent().$text;

        return $this;
    }

    /** A raw line, already indented by whoever built it (list items, nested blocks). */
    public function raw(string $text): self
    {
        $this->lines[] = $text;

        return $this;
    }

    public function blank(): self
    {
        $this->lines[] = '';

        return $this;
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }

    /** The block's lines, without a trailing newline. */
    public function render(): string
    {
        return \implode("\n", $this->lines);
    }

    /**
     * This block wrapped in `{ … }` — opened on the parent's line, closed one level
     * shallower, which is the layout every block in the grammar uses.
     */
    public function braced(): string
    {
        return "{\n".$this->render()."\n".\str_repeat(self::INDENT, \max(0, $this->depth - 1)).'}';
    }

    public function indent(): string
    {
        return \str_repeat(self::INDENT, $this->depth);
    }
}
