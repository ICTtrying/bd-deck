@props(['label', 'for' => null, 'error' => null, 'hint' => null])

<div {{ $attributes->merge(['class' => 'grid gap-1.5']) }}>
    <label @if ($for) for="{{ $for }}" @endif class="text-[0.8125rem] font-medium text-ink">{{ $label }}</label>
    {{ $slot }}
    @if ($error && $errors->has($error))
        <p class="text-[0.8125rem] text-danger">{{ $errors->first($error) }}</p>
    @elseif ($hint)
        <p class="text-[0.8125rem] text-muted">{{ $hint }}</p>
    @endif
</div>
