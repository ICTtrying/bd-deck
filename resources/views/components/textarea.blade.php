@props(['mono' => false])

<textarea {{ $attributes->merge(['rows' => 3, 'class' => 'w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-faint transition-colors hover:border-line-strong focus:border-live focus:outline-none focus:ring-3 focus:ring-live/15'.($mono ? ' font-mono text-[0.8125rem]' : '')]) }}></textarea>
