@props(['mono' => false])

<input {{ $attributes->merge(['type' => 'text', 'class' => 'h-9 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink placeholder:text-faint transition-colors hover:border-line-strong focus:border-live focus:outline-none focus:ring-3 focus:ring-live/15 aria-invalid:border-danger'.($mono ? ' font-mono text-[0.8125rem]' : '')]) }}>
