<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title', 'Kanban')</title>
<link rel="stylesheet" href="{{ route('kanban.asset', ['asset' => 'kanban.css', 'v' => \PetarSpasic\Kanban\Http\Ui::version('kanban.css')]) }}">
<script src="{{ route('kanban.asset', ['asset' => 'kanban.js', 'v' => \PetarSpasic\Kanban\Http\Ui::version('kanban.js')]) }}" defer></script>
</head>
<body data-poll-ms="{{ (int) config('kanban.ui.poll_ms', 3000) }}">
<a class="skip" href="#main">Skip to content</a>
<header class="top">
    <div class="wrap top-in">
        <a class="logo" href="{{ route('kanban.index') }}">Kanban<i></i></a>
        <nav class="crumbs" aria-label="Breadcrumb">@yield('crumbs')</nav>
    </div>
</header>
<main id="main" class="wrap">
    <div class="notices" data-notice aria-live="polite">
        @if (session('kanban.notice'))
            @include('kanban::partials.notice', ['message' => session('kanban.notice'), 'details' => [], 'error' => false])
        @endif
        @if (isset($errors) && $errors->any())
            @include('kanban::partials.notice', ['message' => 'Not saved', 'details' => $errors->all(), 'error' => true])
        @endif
    </div>
    @yield('main')
</main>
</body>
</html>
