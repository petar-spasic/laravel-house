<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Plan;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
use PetarSpasic\LaravelHouse\Kanban\Policy\Transitions;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:answer')]
class AnswerCommand extends Command
{
    protected $signature = 'kanban:answer
        {question : <ID>#<n> as `questions` lists it, or <ID> when the card has one open question}
        {option? : The chosen option\'s number}
        {--note= : The owner\'s own words, under the option (the whole answer for a free-form question)}';

    protected $description = 'Record the owner\'s answer under a question; an answered Open question unblocks and promotes its card';

    protected function perform(): int
    {
        $this->requireMainOrOwner('answer');
        [$ref, $n] = array_pad(explode('#', (string) $this->argument('question'), 2), 2, null);
        if ($n !== null && ! ctype_digit($n)) {
            throw new Invalid("'{$this->argument('question')}': use <ID>#<n>");
        }
        $card = $this->store()->card($ref);
        $question = Questions::find($card, $n === null ? null : (int) $n);
        $handle = $card->id().($question['n'] === 0 ? '' : '#'.$question['n']);
        $note = trim((string) $this->option('note'));
        $option = $this->argument('option');
        if ($question['options'] === []) {
            if ($note === '') {
                throw new Invalid("{$handle} is free-form: answer with --note=\"…\"");
            }
            $answer = $note;
        } else {
            if ($option === null) {
                throw new Invalid("{$handle}: give the option number (".implode(', ', array_keys($question['options'])).')');
            }
            if (! isset($question['options'][(int) $option]) || ! ctype_digit((string) $option)) {
                throw new Invalid("{$handle} has options 1-".count($question['options']));
            }
            $answer = "{$option}. {$question['options'][(int) $option]}".($note === '' ? '' : "\n\n{$note}");
        }

        // the answer, the block it clears and the replan it asks for: one write, so none lands without the others
        $asked = $card->asks();
        $overruled = $question['kind'] === Questions::PROVISIONAL && (int) $option !== $question['taken'];
        $before = $card->stage();
        $card = $this->store()->update($card->id(), function (array $data) use ($question, $answer, $overruled, $handle, $option) {
            $before = $data;
            $data['body'] = Questions::answer((string) ($data['body'] ?? ''), $question['n'], $answer);
            if ($question['kind'] === Questions::OPEN && str_starts_with((string) ($data['blocked'] ?? ''), Card::QUESTION) && Questions::unanswered($data['body']) === 0) {
                $data['blocked'] = null;
            }
            // a plan covers the owner's answers but a confirmation of what it took: no worker follows one built without them
            if ($data['stage'] === 'ready' && Plan::hash($data) !== Plan::hash($before)) {
                $data = Transitions::replan($data, $overruled ? "the owner chose {$option} for {$handle}, not {$question['taken']}" : "the owner answered {$handle}");
            }

            return $data;
        }, $this->actor());
        $this->say("{$handle} answered: ".strtok($answer, "\n"));
        if ($question['kind'] === Questions::PROVISIONAL) {
            if ($overruled && in_array($before, ['backlog', 'planning', 'ready'], true)) {
                $this->say("{$handle}: the agent took {$question['taken']}; the card is planned again for {$option}".($before === 'ready' ? " ({$before}→{$card->stage()})" : ''));
            } elseif ($overruled) {
                $this->say("{$handle}: the agent took {$question['taken']}; a follow-up card makes the change");
            } elseif ($before === 'ready' && $card->stage() === 'planning') {
                $this->say("{$card->id()}: planned again with your note (ready→planning)");
            }

            return self::SUCCESS;
        }
        if ($before === 'ready' && $card->stage() === 'planning') {
            $this->say("{$card->id()}: planned again with your answer (ready→planning)");
        }
        if ($asked && $card->blocked() === null) {
            $this->say("{$card->id()} unblocked");
        }
        if ($card->stage() === 'backlog' && $card->blocked() === null) {
            try {
                $card = $this->transitions()->promote($card->id(), $this->actor());
                $this->say("promoted {$card->id()} to {$card->stage()}".(PullPolicy::parked($card) ? ": its parked branch {$card->work()['parked_branch']} goes ahead of new cards, in a drain too" : ''));
            } catch (PolicyRefused $e) {
                $this->say($e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
