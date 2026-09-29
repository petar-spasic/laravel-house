@php($id = $card->id())
@php($work = $card->work() ?? [])
<article class="detail" data-detail-card="{{ $id }}" data-detail-url="{{ route('kanban.card', ['card' => $id, 'fragment' => 1]) }}">
    <div data-notice aria-live="polite">
        @if ($notice)
            @include('kanban::partials.notice', $notice)
        @endif
    </div>
    <p class="kick">{{ $id }} · <a href="{{ route('kanban.board', ['epic' => $card->board->epic, 'board' => $card->board->board]) }}">{{ $epic?->title() ?? $card->board->epic }} / {{ $board?->title() ?? $card->board->board }}</a></p>
    <h1 class="detail-t">{{ $card->title() }}</h1>
    <p class="tags">
        <span class="tag tag-s">{{ $card->stage() }}</span>
        <span class="tag tag-p">{{ $card->priority() }}</span>
        <span class="tag">{{ $card->type() }}</span>
        @foreach ($card->labels() as $label)
            <span class="lbl">{{ $label }}</span>
        @endforeach
    </p>

    <dl class="facts">
        <div><dt>In stage</dt><dd>{{ $tile['age'] }} <span class="mute">since {{ $card->stageSince() }}</span></dd></div>
        @if ($card->blocked() !== null)
            <div class="sig"><dt>Blocked</dt><dd>{{ $card->blocked() }}</dd></div>
        @endif
        @if ($card->dependsOn() !== [])
            <div><dt>Depends on</dt><dd>
                @foreach ($card->dependsOn() as $dep)
                    <a href="{{ route('kanban.card', $dep) }}" @class(['dep', 'dep-open' => ! $snapshot->isSatisfied($dep)])>{{ $dep }}</a>
                    <span class="mute">{{ $snapshot->card($dep)?->stage() ?? 'missing' }}</span>
                @endforeach
            </dd></div>
        @endif
        @if ($tile['agent'] !== null)
            <div><dt>Worker</dt><dd>{{ $tile['agent'] }}</dd></div>
        @endif
        @foreach (['branch' => 'Branch', 'worktree' => 'Worktree', 'merge' => 'Merge', 'parked_branch' => 'Parked branch'] as $key => $name)
            @if (! empty($work[$key]) && is_string($work[$key]))
                <div><dt>{{ $name }}</dt><dd><code>{{ $work[$key] }}</code></dd></div>
            @endif
        @endforeach
        @if ($tile['url'] !== null && preg_match('#^https?://#i', $tile['url']) === 1)
            <div><dt>Stack</dt><dd><a href="{{ $tile['url'] }}" target="_blank" rel="noopener noreferrer">{{ $tile['url'] }}</a></dd></div>
        @endif
        @if ($card->claim() !== null)
            <div><dt>Claim</dt><dd>{{ $card->claim()['by'] }} <span class="mute">{{ $card->claim()['at'] }}</span></dd></div>
        @endif
        @foreach (['decided_on' => 'Decided', 'superseded_by' => 'Superseded by', 'resolution' => 'Resolution'] as $key => $name)
            @if (! empty($card->data[$key]))
                <div><dt>{{ $name }}</dt><dd>{{ $card->data[$key] }}</dd></div>
            @endif
        @endforeach
        @if (! empty($card->data['supersedes']))
            <div><dt>Supersedes</dt><dd>{{ implode(', ', $card->data['supersedes']) }}</dd></div>
        @endif
        <div><dt>Created</dt><dd>{{ $card->created() }}</dd></div>
        <div><dt>Updated</dt><dd>{{ $card->updated() }}</dd></div>
    </dl>

    @if (trim($card->data['body'] ?? '') !== '')
        <div class="md">{!! $body !!}</div>
    @endif
    @if ($why !== null)
        <h2 class="sub">Why</h2>
        <div class="md">{!! $why !!}</div>
    @endif

    @if ($card->acceptance() !== [])
        <h2 class="sub">Acceptance</h2>
        <ul class="check">
            @foreach ($card->acceptance() as $item)
                <li @class(['is-done' => $item['done']])><span class="box" aria-hidden="true"></span><span class="vh">{{ $item['done'] ? 'Done:' : 'Open:' }}</span> <span class="mute">{{ $item['id'] }}</span> {{ $item['text'] }}</li>
            @endforeach
        </ul>
    @endif

    <h2 class="sub">Change</h2>
    <div class="forms">
        <form method="post" action="{{ route('kanban.card.stage', $id) }}" data-fetch>
            @csrf
            <input type="hidden" name="rev" value="{{ $card->rev }}">
            <label for="dst-{{ $id }}">Stage</label>
            <select id="dst-{{ $id }}" name="to">
                @unless (in_array($card->stage(), $targets, true))
                    <option value="{{ $card->stage() }}" selected disabled>{{ $card->stage() }}</option>
                @endunless
                @foreach ($targets as $stage)
                    <option value="{{ $stage }}" @selected($stage === $card->stage())>{{ $stage }}</option>
                @endforeach
            </select>
            <label for="drs-{{ $id }}">Reason <span class="mute">(dropping needs one)</span></label>
            <input id="drs-{{ $id }}" name="reason" maxlength="500">
            <button class="btn">Move</button>
        </form>
        <form method="post" action="{{ route('kanban.card.priority', $id) }}" data-fetch>
            @csrf
            <input type="hidden" name="rev" value="{{ $card->rev }}">
            <label for="dpr-{{ $id }}">Priority</label>
            <select id="dpr-{{ $id }}" name="priority">
                @foreach (\PetarSpasic\Kanban\Store\Priority::cases() as $priority)
                    <option value="{{ $priority->value }}" @selected($priority->value === $card->priority())>{{ $priority->value }}</option>
                @endforeach
            </select>
            <button class="btn">Set priority</button>
        </form>
        <form method="post" action="{{ route('kanban.card.blocked', $id) }}" data-fetch>
            @csrf
            <input type="hidden" name="rev" value="{{ $card->rev }}">
            <label for="dbl-{{ $id }}">Blocked <span class="mute">(empty unblocks)</span></label>
            <input id="dbl-{{ $id }}" name="reason" maxlength="500" value="{{ $card->blocked() }}">
            <button class="btn">Save</button>
        </form>
        <form method="post" action="{{ route('kanban.card.notes', $id) }}" data-fetch>
            @csrf
            <input type="hidden" name="rev" value="{{ $card->rev }}">
            <label for="dnt-{{ $id }}">Note</label>
            <textarea id="dnt-{{ $id }}" name="text" rows="3" maxlength="5000" required></textarea>
            <button class="btn">Add note</button>
        </form>
    </div>

    <h2 class="sub">Log <span class="mute">latest {{ count($log) }}</span></h2>
    <ol class="log">
        @foreach ($log as $entry)
            @php($extra = array_diff_key($entry, array_flip(['id', 'at', 'by', 'event'])))
            <li>
                <time datetime="{{ $entry['at'] ?? '' }}">{{ substr((string) ($entry['at'] ?? ''), 0, 16) }}</time>
                <b>{{ $entry['by'] ?? '' }}</b>
                <span>{{ $entry['event'] ?? '' }}</span>
                @if (($entry['event'] ?? null) === 'stage')
                    <span>{{ $entry['from'] ?? '' }} → {{ $entry['to'] ?? '' }}</span>
                    @if (isset($entry['via']))<span class="mute">via {{ $entry['via'] }}</span>@endif
                    @if (isset($entry['reason']))<q>{{ $entry['reason'] }}</q>@endif
                @elseif (($entry['event'] ?? null) === 'note')
                    <q>{{ $entry['text'] ?? '' }}</q>
                @else
                    @foreach ($extra as $key => $value)
                        <span class="mute">{{ $key }}: {{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</span>
                    @endforeach
                @endif
            </li>
        @endforeach
    </ol>
</article>
