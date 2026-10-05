<?php

namespace PetarSpasic\LaravelHouse\Kanban\Console;

use PetarSpasic\LaravelHouse\Kanban\Policy\Questions;
use PetarSpasic\LaravelHouse\Kanban\Protocol\Brief;
use PetarSpasic\LaravelHouse\Kanban\Store\Card;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:morning')]
class MorningCommand extends Command
{
    protected $signature = 'kanban:morning {--since=24h : Hours (24h), minutes (90m) or an ISO time}';

    protected $description = 'The owner\'s morning: the board, what merged since, what is blocked, how many questions wait';

    protected function perform(): int
    {
        $since = $this->since((string) $this->option('since'));
        $this->gitStore()?->maybeSync();
        foreach ((new Brief($this->store(), $this->paths(), $this->config()))->lines($this->actor()->session) as $line) {
            $this->say($line);
        }
        $snapshot = $this->store()->snapshot();
        $merged = $snapshot->cards(fn (Card $c) => $c->stage() === 'done' && array_filter($c->log(),
            fn (array $e) => ($e['event'] ?? null) === 'stage' && ($e['to'] ?? null) === 'done' && (string) ($e['at'] ?? '') >= $since) !== []);
        $blocked = $snapshot->cards(fn (Card $c) => $c->blocked() !== null && ! $c->asks() && ! in_array($c->stage(), ['done', 'dropped'], true));
        $questions = array_sum(array_map(fn (Card $c) => count(Questions::open($c)), $snapshot->cards(fn (Card $c) => $c->stage() !== 'dropped')));

        $this->say('since '.substr(str_replace('T', ' ', $since), 0, 16).'Z:');
        $this->say('merged '.count($merged).(count($merged) === 0 ? '' : ':'));
        foreach ($merged as $card) {
            $this->say("  {$card->id()} {$card->title()}");
        }
        $this->say('blocked '.count($blocked).(count($blocked) === 0 ? '' : ':'));
        foreach ($blocked as $card) {
            $this->say("  {$card->id()} {$card->title()}: ".mb_strimwidth((string) $card->blocked(), 0, 200, '…'));
        }
        $this->say("questions {$questions} open".($questions === 0 ? '' : ': `kanban questions`'));
        if (($runs = Brief::runs($this->paths(), $since)) !== []) {
            $tokens = array_sum(array_column($runs, 'tokens'));
            $cost = array_sum(array_column($runs, 'cost_usd'));
            $this->say('agents '.count($runs).' runs, '.Brief::tokens($tokens).' tokens, $'.number_format($cost, 2).' at list price'
                .($merged === [] ? '' : '; '.Brief::tokens($tokens / count($merged)).' tokens, $'.number_format($cost / count($merged), 2).' per merged card'));
        }

        return self::SUCCESS;
    }

    /** `24h`, `90m` or an ISO time → the UTC timestamp the log's `at` compares against. */
    private function since(string $since): string
    {
        if (preg_match('/^(\d+)([hm])$/', $since, $m) === 1) {
            $at = time() - (int) $m[1] * ($m[2] === 'h' ? 3600 : 60);
        } elseif (($at = strtotime($since)) === false) {
            throw new Invalid("--since={$since}: use 24h, 90m or an ISO time");
        }

        return gmdate('Y-m-d\TH:i:s', $at);
    }
}
