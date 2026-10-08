@props(['label', 'description' => null])

<label class="flex cursor-pointer items-start justify-between gap-6 py-1">
    <span class="grid gap-0.5">
        <span class="text-sm font-medium text-ink">{{ $label }}</span>
        @if ($description)
            <span class="text-[0.8125rem] text-muted">{{ $description }}</span>
        @endif
    </span>
    <span class="relative mt-0.5 inline-flex shrink-0">
        <input type="checkbox" {{ $attributes->merge(['class' => 'peer sr-only']) }}>
        <span class="h-5 w-9 rounded-full bg-line-strong transition-colors peer-checked:bg-local peer-focus-visible:ring-3 peer-focus-visible:ring-live/30"></span>
        <span class="absolute left-0.5 top-0.5 size-4 rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-4"></span>
    </span>
</label>
