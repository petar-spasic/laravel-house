<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Kanban</title>
<link rel="icon" href="{{ route('kanban.asset', ['asset' => 'kanban.svg', 'v' => \PetarSpasic\LaravelHouse\Kanban\Http\Ui::version('kanban.svg')]) }}" type="image/svg+xml">
@foreach (['400', '500', '600'] as $weight)
<link rel="preload" href="{{ route('kanban.asset', ['asset' => 'inter-2-'.$weight.'.woff2']) }}" as="font" type="font/woff2" crossorigin>
@endforeach
<link rel="stylesheet" href="{{ route('kanban.asset', ['asset' => 'kanban.css', 'v' => \PetarSpasic\LaravelHouse\Kanban\Http\Ui::version('kanban.css')]) }}">
<script src="{{ route('kanban.asset', ['asset' => 'kanban.js', 'v' => \PetarSpasic\LaravelHouse\Kanban\Http\Ui::version('kanban.js')]) }}" defer></script>
</head>
<body data-base="{{ $base }}" data-poll-ms="{{ $pollMs }}">
<div id="app"></div>
<noscript><p class="boot">The Kanban board needs JavaScript.</p></noscript>
</body>
</html>
