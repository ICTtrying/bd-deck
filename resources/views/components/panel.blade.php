@props(['title' => null, 'description' => null, 'padding' => true])

<section {{ $attributes->merge(['class' => 'rounded-xl border border-line bg-surface']) }}>
    @if ($title)
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-3.5">
            <div class="grid gap-0.5">
                <h2 class="text-[0.9375rem] font-semibold">{{ $title }}</h2>
                @if ($description)
                    <p class="text-[0.8125rem] text-muted">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div @class(['px-5 py-4' => $padding])>{{ $slot }}</div>
</section>
