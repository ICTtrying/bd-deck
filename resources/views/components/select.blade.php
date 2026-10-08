<div class="relative">
    <select {{ $attributes->merge(['class' => 'h-9 w-full appearance-none rounded-lg border border-line bg-surface pl-3 pr-8 text-sm text-ink transition-colors hover:border-line-strong focus:border-live focus:outline-none focus:ring-3 focus:ring-live/15']) }}>
        {{ $slot }}
    </select>
    <x-icon name="chevron-down" :size="14" class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-faint" />
</div>
