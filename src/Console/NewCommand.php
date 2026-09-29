<?php

namespace PetarSpasic\Kanban\Console;

use PetarSpasic\Kanban\Policy\ReadyPolicy;
use PetarSpasic\Kanban\Store\BoardRef;
use PetarSpasic\Kanban\Store\Card;
use PetarSpasic\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\Kanban\Store\Exceptions\PolicyRefused;
use PetarSpasic\Kanban\Store\Rev;
use PetarSpasic\Kanban\Support\Clock;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'kanban:new')]
class NewCommand extends Command
{
    protected $signature = 'kanban:new
        {board : epic/board}
        {title : Card title}
        {--type= : feature|bug|chore|spike (work) or decision}
        {--priority= : urgent|high|normal|low}
        {--label=* : Label (repeatable)}
        {--accept=* : Acceptance criterion (repeatable)}
        {--depends=* : Card id it depends on (repeatable)}
        {--body= : Markdown body}
        {--body-file= : Read the body from a file (- = stdin)}
        {--why= : Why (decisions)}
        {--decided-on= : YYYY-MM-DD (decisions)}
        {--stage= : Initial stage: backlog|ready (work), proposed|decided (decisions)}';

    protected $description = 'Create a card';

    protected function perform(): int
    {
        $this->requireMainOrOwner('new');
        $store = $this->store();
        $snapshot = $store->snapshot();
        $ref = BoardRef::parse($this->argument('board'));
        $board = $snapshot->board($ref) ?? throw new NotFound("no board {$ref}");
        $decisions = $board->kind() === 'decisions';

        $fields = ['title' => $this->argument('title')];
        foreach (['type' => 'type', 'priority' => 'priority', 'why' => 'why', 'decided_on' => 'decided-on'] as $field => $option) {
            if ($this->option($option) !== null) {
                $fields[$field] = $this->option($option);
            }
        }
        $body = $this->option('body-file') !== null ? $this->readFile($this->option('body-file')) : $this->option('body');
        if ($body !== null) {
            $fields['body'] = $body;
        }
        if ($this->option('label') !== []) {
            $fields['labels'] = $this->option('label');
        }
        if (! $decisions) {
            $fields['acceptance'] = $this->option('accept');
            $fields['depends_on'] = array_map(fn (string $id) => $snapshot->resolve($id)->id(), $this->option('depends'));
        } elseif ($this->option('accept') !== [] || $this->option('depends') !== []) {
            throw new Invalid('decisions have no acceptance criteria or dependencies');
        }

        $stage = $this->option('stage');
        $allowed = $decisions ? ['proposed', 'decided'] : ['backlog', 'ready'];
        if ($stage !== null && ! in_array($stage, $allowed, true)) {
            throw new Invalid('--stage must be one of: '.implode(', ', $allowed));
        }
        if ($stage === 'decided') {
            $fields['stage'] = 'decided';
            $fields['decided_on'] ??= Clock::today();
        }
        if ($stage === 'ready') {
            $draft = new Card(array_merge(['id' => $snapshot->key().'-DRAFT', 'type' => 'feature', 'stage' => 'backlog', 'blocked' => null, 'body' => ''], $fields), $ref, '', new Rev(''));
            $refusals = (new ReadyPolicy)->refusals($draft, $snapshot, false);
            if ($refusals !== []) {
                throw new PolicyRefused('refused: '.implode('; ', $refusals), $refusals);
            }
            $fields['stage'] = 'ready';
        }

        $card = $store->create($ref, $fields, $this->actor());
        $this->say("created {$card->id()} {$card->board} {$card->stage()}");
        $this->reportPending();

        return self::SUCCESS;
    }

    private function readFile(string $file): string
    {
        $content = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
        if ($content === false) {
            throw new NotFound("cannot read {$file}");
        }

        return $content;
    }
}
