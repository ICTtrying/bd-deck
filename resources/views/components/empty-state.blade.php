@props(['icon' => 'info', 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-start gap-3 rounded-xl border border-dashed border-line-strong px-6 py-10']) }}>
    <span class="grid size-10 place-items-center rounded-lg bg-local-soft text-local-ink">
        <x-icon :name="$icon" :size="20" />
    </span>
    <div class="grid gap-1">
        <h3 class="text-[0.9375rem] font-semibold">{{ $title }}</h3>
        @if ($description)
            <p class="max-w-prose text-muted">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="mt-1 flex flex-wrap gap-2">{{ $slot }}</div>
    @endif
</div>
