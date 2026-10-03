<?php

use PetarSpasic\LaravelHouse\Kanban\Policy\Edits;
use PetarSpasic\LaravelHouse\Kanban\Store\Actor;
use PetarSpasic\LaravelHouse\Kanban\Store\BoardRef;
use PetarSpasic\LaravelHouse\Kanban\Store\Changes;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Snapshot;
use PetarSpasic\LaravelHouse\Kanban\Store\Store;
use PetarSpasic\LaravelHouse\Tests\Support\Sandbox;

beforeEach(function () {
    $this->sandbox = Sandbox::create();
    $this->sandbox->install('ACME');
    $this->sandbox->ok(['board', 'later', 'Later']);
});

/** @return array<string, string> every board file => its bytes */
function boardFiles(Sandbox $s): array
{
    $files = [];
    $root = $s->root.'/docs/kanban';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_contains($file->getPathname(), '/.git')) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
        }
    }
    ksort($files);

    return $files;
}

it('writes many cards, a move, a removal and a root Markdown file in one commit', function () {
    $s = $this->sandbox;
    $noted = $s->card('Gets a note');
    $moved = $s->card('Moves boards');
    $removed = $s->card('Goes away');
    $before = count($s->boardLog());

    $written = app(Store::class)->batch(fn (Snapshot $board) => (new Changes)
        ->put($board->card($noted), Edits::note($board->card($noted)->data, 'Folded in'))
        ->put($board->card($moved), $board->card($moved)->data, BoardRef::parse('later'))
        ->remove($board->card($removed)->path)
        ->text('decisions.md', "# Decisions\n"), new Actor('main', 's1'), 'Batch of three');

    expect(array_map(fn ($card) => $card->id(), $written))->toBe([$noted, $moved])
        ->and($s->boardLog())->toHaveCount($before + 1)
        ->and($s->boardLog()[0])->toBe('Batch of three [main]')
        ->and(explode("\n", trim($s->boardGit('show', '--name-status', '--format=', 'HEAD'))))->toEqualCanonicalizing([
            "M\twork/{$noted}.json", "R100\twork/{$moved}.json\tlater/{$moved}.json",
            "D\twork/{$removed}.json", "A\tdecisions.md",
        ])
        ->and(end($s->read($noted)['log']))->toMatchArray(['by' => 'main', 'event' => 'note', 'text' => 'Folded in'])
        ->and(file_get_contents($s->root.'/docs/kanban/decisions.md'))->toBe("# Decisions\n")
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('')
        ->and($s->ok('validate'))->toContain('ok: 2 cards');
});

it('writes nothing when any part of the batch is invalid', function (Closure $plan, string $message) {
    $s = $this->sandbox;
    $a = $s->card('First');
    $b = $s->card('Second', ["--depends={$a}"]);
    $files = boardFiles($s);
    $log = $s->boardLog();

    expect(fn () => app(Store::class)->batch(fn (Snapshot $board) => $plan($board, $a, $b), new Actor('main', 's1'), 'Refused'))
        ->toThrow(Invalid::class, $message);

    expect(boardFiles($s))->toBe($files)->and($s->boardLog())->toBe($log)
        ->and(trim($s->boardGit('status', '--porcelain')))->toBe('');
})->with([
    'an invalid card after a valid one' => [fn (Snapshot $board, string $a, string $b) => (new Changes)
        ->put($board->card($a), Edits::note($board->card($a)->data, 'Fine'))
        ->put($board->card($b), ['title' => ''] + $board->card($b)->data)
        ->text('decisions.md', "# Decisions\n"), 'title'],
    'removing a card another depends on' => [fn (Snapshot $board, string $a) => (new Changes)
        ->remove($board->card($a)->path), 'unknown card'],
    'a text outside the board root' => [fn () => (new Changes)->text('../escape.md', 'x'), 'only a Markdown file at the board root'],
]);
