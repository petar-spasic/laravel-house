<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Code\MergeCheck;
use PetarSpasic\LaravelHouse\Kanban\Code\Worktrees;
use PetarSpasic\LaravelHouse\Kanban\Policy\PullPolicy;
use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
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

        // only what `finish --ask` asked about: a Steering: line anywhere else approves nothing. Worked out before the answer
        // is written, so an approval that cannot be given here leaves the question open
        $steering = array_values(array_intersect(Questions::steering($question), MergeCheck::asked($card)));
        $approval = $steering !== [] && (int) $option === 1
            ? MergeCheck::approvalOf(new Worktrees($this->paths(), $this->config()), $this->paths()->main, $card, $steering) : null;
        $card = $this->store()->update($card->id(), function (array $data) use ($question, $answer) {
            $data['body'] = Questions::answer((string) ($data['body'] ?? ''), $question['n'], $answer);

            return $data;
        }, $this->actor());
        $this->say("{$handle} answered: ".strtok($answer, "\n"));

        if ($approval !== null) {
            $card = $this->store()->update($card->id(), function (array $data) use ($approval) {
                $data['log'][] = $approval;

                return $data;
            }, $this->actor());
            $this->say("{$card->id()}: the owner approved ".implode(', ', $steering).' as they are; the next finish merges it');
        }
        if ($question['kind'] === Questions::PROVISIONAL) {
            if ((int) $option !== $question['taken']) {
                $this->say("{$handle}: the agent took {$question['taken']}; a follow-up card makes the change");
            }

            return self::SUCCESS;
        }
        if ($card->asks() && array_filter(Questions::open($card), fn (array $q) => $q['kind'] === Questions::OPEN && $q['n'] > 0) === []) {
            $card = $this->store()->update($card->id(), function (array $data) {
                $data['blocked'] = null;

                return $data;
            }, $this->actor());
            $this->say("{$card->id()} unblocked");
            if ($steering !== [] && (int) $option === 2 && $card->stage() === 'review') {
                $this->transitions()->sendBack($card->id(), 'move', $this->actor(), 'the owner sent it back: revert the changes to '.implode(', ', $steering));
                $this->say("{$card->id()} review→doing: its worker reverts them");
            }
        }
        if ($card->stage() === 'backlog' && $card->blocked() === null) {
            try {
                $card = $this->transitions()->promote($card->id(), $this->actor());
                $this->say("promoted {$card->id()}".(PullPolicy::parked($card) ? ": its parked branch {$card->work()['parked_branch']} starts ahead of new cards, in a drain too" : ''));
            } catch (PolicyRefused $e) {
                $this->say($e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
