<div @class(['notice', 'notice-e' => $error ?? false]) role="{{ ($error ?? false) ? 'alert' : 'status' }}">
    <p>{{ ($details ?? []) !== [] && str_contains($message, ':') ? \Illuminate\Support\Str::before($message, ':') : $message }}</p>
    @if (($details ?? []) !== [])
        <ul>
            @foreach ($details as $detail)
                <li>{{ $detail }}</li>
            @endforeach
        </ul>
    @endif
</div>
