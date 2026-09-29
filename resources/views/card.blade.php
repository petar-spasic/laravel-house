@extends('kanban::layout')

@section('title', $card->id().' '.$card->title())

@section('crumbs')
    <a href="{{ route('kanban.index') }}">Boards</a> <span aria-hidden="true">/</span>
    <a href="{{ route('kanban.board', ['epic' => $card->board->epic, 'board' => $card->board->board]) }}">{{ $board?->title() ?? $card->board }}</a>
@endsection

@section('main')
    @include('kanban::partials.card-detail')
@endsection
