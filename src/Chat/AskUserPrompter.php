<?php

declare(strict_types=1);

namespace Llmor\Cli\Chat;

use Llmor\Cli\Console\OutputStyle;
use Llmor\Cli\Sync\Json;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Asks the human the structured questions the model raised, and shapes the answers the
 * way the interact endpoint expects.
 *
 * An app can call an ask-user tool, which suspends the turn rather than executing
 * anything: the response comes back with `pending_ask_user` and the conversation sits in
 * `waiting_for_ask_user` until the whole batch is answered. The batch is atomic — a
 * partial answer leaves the prompts pending — so every prompt is asked before anything
 * is sent back.
 */
final class AskUserPrompter
{
    public function __construct(private readonly OutputStyle $io)
    {
    }

    /**
     * Ask each prompt in turn.
     *
     * @param list<array<string, mixed>> $prompts
     *
     * @return list<array{id: string, result: mixed}>
     */
    public function ask(array $prompts): array
    {
        $answers = [];

        foreach ($prompts as $prompt) {
            $id = Json::stringOf($prompt['id'] ?? null);
            if ('' === $id) {
                continue;
            }

            $this->io->newLine();
            $this->io->writeln(\sprintf('<warn>?</warn> %s', OutputFormatter::escape(Json::stringOf($prompt['question'] ?? null))));

            $answers[] = ['id' => $id, 'result' => $this->askOne($prompt)];
        }

        return $answers;
    }

    /**
     * Print the pending questions without asking them — the non-interactive path, where
     * we have no way to answer and the conversation would otherwise look simply stuck.
     *
     * @param list<array<string, mixed>> $prompts
     */
    public function describe(array $prompts): void
    {
        $this->io->newLine();
        $this->io->warning('The app is waiting for answers to these questions:');

        foreach ($prompts as $prompt) {
            $this->io->writeln(\sprintf(
                '  <accent>%s</accent>  %s',
                OutputFormatter::escape(Json::stringOf($prompt['type'] ?? null)),
                OutputFormatter::escape(Json::stringOf($prompt['question'] ?? null)),
            ));
        }

        $this->io->hint('Run without a message argument to answer them in the REPL.');
    }

    /**
     * @param array<string, mixed> $prompt
     */
    private function askOne(array $prompt): mixed
    {
        $options = self::options($prompt);

        return match (Json::stringOf($prompt['type'] ?? null)) {
            'bool' => $this->io->askQuestion(new ConfirmationQuestion('  yes/no [yes] ', true)),
            'radiolist' => [] === $options
                ? $this->io->askQuestion(new Question('  '))
                : $this->io->askQuestion(new ChoiceQuestion('  choose', $options)),
            'checklist' => [] === $options
                ? $this->io->askQuestion(new Question('  '))
                : $this->askMultiple($options),
            default => (string) $this->io->askQuestion(new Question('  ')),
        };
    }

    /**
     * @param array<int|string, string> $options
     *
     * @return list<string>
     */
    private function askMultiple(array $options): array
    {
        $question = new ChoiceQuestion('  choose (comma-separated)', $options, '');
        $question->setMultiselect(true);

        $answer = $this->io->askQuestion($question);

        if (!\is_array($answer)) {
            return '' === (string) $answer ? [] : [(string) $answer];
        }

        return \array_values(\array_map(strval(...), $answer));
    }

    /**
     * The choices for a list-style prompt.
     *
     * Options arrive either as plain strings or as `{value, label}` pairs; the label is
     * what the user picks from, and it is also what goes back — the server matches on
     * the answer text, not on an index.
     *
     * @param array<string, mixed> $prompt
     *
     * @return list<string>
     */
    private static function options(array $prompt): array
    {
        $arguments = $prompt['arguments'] ?? null;
        if (!\is_array($arguments)) {
            return [];
        }

        $options = $arguments['options'] ?? $arguments['choices'] ?? null;
        if (!\is_array($options)) {
            return [];
        }

        $choices = [];
        foreach ($options as $option) {
            if (\is_string($option)) {
                $choices[] = $option;

                continue;
            }

            if (\is_array($option)) {
                /** @var array<string, mixed> $option */
                $label = Json::stringOf($option['label'] ?? null);
                $value = Json::stringOf($option['value'] ?? null);
                $choice = '' !== $label ? $label : $value;
                if ('' !== $choice) {
                    $choices[] = $choice;
                }
            }
        }

        return $choices;
    }
}
