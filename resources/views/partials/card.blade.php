@php($card = $tile['card'])
@php($id = $card->id())
<li @class(['tile', 'p-'.$card->priority(), 'is-blocked' => $card->blocked() !== null]) draggable="true" data-card="{{ $id }}" data-rev="{{ $card->rev }}" data-stage-url="{{ route('kanban.card.stage', $id) }}">
    <div class="tile-top">
        <a class="tile-id" href="{{ route('kanban.card', $id) }}" data-detail="{{ route('kanban.card', ['card' => $id, 'fragment' => 1]) }}" title="{{ $id }}">{{ $tile['short'] }}</a>
        <span class="tile-age" title="In {{ $card->stage() }} since {{ $card->stageSince() }}">{{ $tile['age'] }}</span>
    </div>
    <a class="tile-t" href="{{ route('kanban.card', $id) }}" data-detail="{{ route('kanban.card', ['card' => $id, 'fragment' => 1]) }}">{{ $card->title() }}</a>
    <p class="tags">
        <span class="tag tag-p">{{ $card->priority() }}</span>
        <span class="tag">{{ $card->type() }}</span>
        @if ($card->blocked() !== null)
            <span class="tag tag-s" title="{{ $card->blocked() }}">blocked</span>
        @endif
        @if ($tile['deps_open'] > 0)
            <span class="tag tag-d" title="{{ implode(', ', $card->dependsOn()) }}">deps {{ $tile['deps_open'] }}</span>
        @endif
        @foreach ($card->labels() as $label)
            <span class="lbl">{{ $label }}</span>
        @endforeach
    </p>
    @if ($tile['agent'] !== null)
        <p class="tile-w">{{ $tile['agent'] }}</p>
    @endif
    @if ($tile['url'] !== null && preg_match('#^https?://#i', $tile['url']) === 1)
        <a class="tile-u" href="{{ $tile['url'] }}" target="_blank" rel="noopener noreferrer">{{ preg_replace('#^https?://#i', '', $tile['url']) }}</a>
    @endif
    <div class="tile-f">
        <form method="post" action="{{ route('kanban.card.stage', $id) }}" data-fetch>
            @csrf
            <input type="hidden" name="rev" value="{{ $card->rev }}">
            <label class="vh" for="st-{{ $id }}">Stage of {{ $id }}</label>
            <select id="st-{{ $id }}" name="to" data-autosubmit>
                @unless (in_array($card->stage(), $targets, true))
                    <option value="{{ $card->stage() }}" selected disabled>{{ $card->stage() }}</option>
                @endunless
                @foreach ($targets as $stage)
                    <option value="{{ $stage }}" @selected($stage === $card->stage())>{{ $stage }}</option>
                @endforeach
            </select>
            <button class="btn-s nojs">Move</button>
        </form>
        <form method="post" action="{{ route('kanban.card.priority', $id) }}" data-fetch>
            @csrf
            <input type="hidden" name="rev" value="{{ $card->rev }}">
            <label class="vh" for="pr-{{ $id }}">Priority of {{ $id }}</label>
            <select id="pr-{{ $id }}" name="priority" data-autosubmit>
                @foreach (\PetarSpasic\Kanban\Store\Priority::cases() as $priority)
                    <option value="{{ $priority->value }}" @selected($priority->value === $card->priority())>{{ $priority->value }}</option>
                @endforeach
            </select>
            <button class="btn-s nojs">Set</button>
        </form>
    </div>
</li>
