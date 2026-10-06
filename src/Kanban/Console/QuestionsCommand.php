<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:questions')]
class QuestionsCommand extends Command
{
    protected $signature = 'kanban:questions';

    protected $description = 'The questions waiting for the owner, blocking ones first, each with its options';

    protected function perform(): int
    {
        $this->gitStore()?->maybeSync();
        $pending = Questions::pending($this->store()->snapshot());
        if ($pending === []) {
            $this->say('no open questions');

            return self::SUCCESS;
        }
        $blocking = $provisional = [];
        foreach ($pending as ['card' => $card, 'question' => $question]) {
            if ($question['kind'] === Questions::OPEN) {
                $blocking[] = $this->lines($card, $question);
            } else {
                $provisional[] = $this->lines($card, $question);
            }
        }
        foreach (array_merge(...$blocking, ...$provisional) as $line) {
            $this->say($line);
        }
        $this->say(Questions::tally($pending).': `kanban answer <ID>#<n> <option> [--note=…]`');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    private function lines(Card $card, array $question): array
    {
        $handle = $card->id().($question['n'] === 0 ? '' : '#'.$question['n']);
        $free = $question['options'] === [];
        $lines = ["{$handle} ".mb_strtolower($question['kind']).": {$card->title()}".($free ? ' (free-form: answer with --note)' : '')];
        foreach (explode("\n", $free ? $question['text'] : $question['context']) as $line) {
            if (trim($line) !== '') {
                $lines[] = '  '.trim($line);
            }
        }
        foreach ($question['options'] as $n => $option) {
            $marks = array_filter([$n === $question['recommended'] ? 'recommended' : null, $n === $question['taken'] ? 'taken' : null]);
            $lines[] = "  {$n}. {$option}".($marks === [] ? '' : ' ('.implode(', ', $marks).')');
        }

        return $lines;
    }
}
