<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="auto-lock-minutes" content="{{ $autoLockMinutes ?? 0 }}">
<title>{{ isset($title) ? $title.' — ' : '' }}BD Deck</title>
<link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
{{-- thema vóór de eerste paint zetten, anders flitst het donkere thema eerst wit --}}
<script>
    (() => {
        const el = document.documentElement;
        const pref = el.dataset.theme;
        el.classList.toggle('dark', pref === 'dark' || (pref === 'system' && matchMedia('(prefers-color-scheme: dark)').matches));
    })();
</script>
@fonts
@vite(['resources/css/app.css', 'resources/js/app.ts'])
