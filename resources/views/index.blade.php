@extends('kanban::layout')

@section('title', ($key ?? 'Kanban').' · Boards')

@section('main')
    <div class="head">
        <p class="kick">{{ $key ?? 'Kanban' }}</p>
        <h1>Boards</h1>
    </div>
    @foreach ($notices as $message)
        @include('kanban::partials.notice', ['message' => $message, 'details' => [], 'error' => true])
    @endforeach
    @forelse ($epics as $group)
        <section class="epic">
            <div class="epic-h">
                <h2>{{ $group['epic']->title() }}</h2>
                @if (! empty($group['epic']->data['goal']))
                    <p class="mute">{{ $group['epic']->data['goal'] }}</p>
                @endif
            </div>
            <ul class="boards">
                @forelse ($group['boards'] as $item)
                    <li>
                        <a class="bd" href="{{ route('kanban.board', ['epic' => $item['board']->ref->epic, 'board' => $item['board']->ref->board]) }}">
                            <span class="bd-t">{{ $item['board']->title() }}</span>
                            <span class="kick mute">{{ $item['board']->kind() }}</span>
                            <dl class="counts">
                                @foreach ($item['counts'] as $stage => $count)
                                    <div @class(['zero' => $count === 0])><dt>{{ $stage }}</dt><dd>{{ $count }}</dd></div>
                                @endforeach
                            </dl>
                        </a>
                    </li>
                @empty
                    <li class="mute">No boards.</li>
                @endforelse
            </ul>
        </section>
    @empty
        @if ($notices === [])
            <p class="mute">No epics yet: `vendor/bin/kanban board epic/board "Title"` creates one.</p>
        @endif
    @endforelse
@endsection
