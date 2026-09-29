@extends('kanban::layout')

@section('title', $board->title().' · '.($epic?->title() ?? $board->ref->epic))

@section('crumbs')
    <a href="{{ route('kanban.index') }}">Boards</a> <span aria-hidden="true">/</span> {{ $epic?->title() ?? $board->ref->epic }}
@endsection

@section('main')
    <div class="head">
        <p class="kick">{{ $epic?->title() ?? $board->ref->epic }} · {{ $board->kind() }}</p>
        <h1>{{ $board->title() }}</h1>
    </div>
    @fragment('columns')
        @include('kanban::partials.columns')
    @endfragment
    <dialog class="dlg" data-dialog aria-label="Card">
        <form method="dialog" class="dlg-x"><button class="btn-s" value="close">Close</button></form>
        <div data-dialog-body></div>
    </dialog>
@endsection
