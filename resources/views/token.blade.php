<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Kanban</title>
<link rel="icon" href="{{ route('kanban.asset', ['asset' => 'kanban.svg', 'v' => \PetarSpasic\Kanban\Http\Ui::version('kanban.svg')]) }}" type="image/svg+xml">
<link rel="stylesheet" href="{{ route('kanban.asset', ['asset' => 'kanban.css', 'v' => \PetarSpasic\Kanban\Http\Ui::version('kanban.css')]) }}">
</head>
<body>
<main class="gate">
<form class="gate-form" method="get">
<h1>Kanban</h1>
<p class="muted">This board needs its token: <span class="mono">KANBAN_UI_TOKEN</span> in the app's <span class="mono">.env</span>.</p>
<label for="token">Token</label>
<input id="token" class="field" type="password" name="token" autocomplete="current-password" required autofocus>
@if ($wrong)
<p class="gate-error" role="alert">That token did not work.</p>
@endif
@foreach ($keep as $name => $value)
<input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
<button class="btn primary" type="submit">Open the board</button>
</form>
</main>
</body>
</html>
