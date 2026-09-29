<div class="cols" data-columns data-columns-url="{{ route('kanban.columns', ['epic' => $board->ref->epic, 'board' => $board->ref->board]) }}" data-etag="{{ $etag }}">
    @foreach ($columns as $column)
        @php($label = ucfirst($column['stage']))
        @php($drop = in_array($column['stage'], $targets, true) ? '1' : '0')
        @if ($column['collapsed'])
            <details class="col col-x" data-stage="{{ $column['stage'] }}" data-drop="{{ $drop }}">
                <summary class="col-h"><span>{{ $label }}</span> <b>{{ $column['total'] }}</b></summary>
                <ol class="tiles">
                    @foreach ($column['tiles'] as $tile)
                        @include('kanban::partials.card')
                    @endforeach
                </ol>
            </details>
        @else
            <section class="col" data-stage="{{ $column['stage'] }}" data-drop="{{ $drop }}" aria-label="{{ $label }}">
                <h2 class="col-h"><span>{{ $label }}</span> <b>{{ $column['total'] }}@if ($column['limit'] !== null)<small>/{{ $column['limit'] }}</small>@endif</b></h2>
                <ol class="tiles">
                    @foreach ($column['tiles'] as $tile)
                        @include('kanban::partials.card')
                    @endforeach
                </ol>
                @if ($column['total'] > count($column['tiles']))
                    <p class="more mute">{{ $column['total'] - count($column['tiles']) }} older not shown</p>
                @endif
            </section>
        @endif
    @endforeach
</div>
