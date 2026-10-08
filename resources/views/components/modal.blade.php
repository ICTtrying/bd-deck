@props(['name', 'title', 'width' => 'max-w-lg', 'tone' => null])

{{-- Modal op basis van Livewire-state of een Alpine-event: open-modal / close-modal met de naam --}}
<div
    x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail === '{{ $name }}' || $event.detail?.name === '{{ $name }}') open = true"
    x-on:close-modal.window="if ($event.detail === '{{ $name }}' || $event.detail?.name === '{{ $name }}') open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto px-4 pt-[12vh] pb-8"
    role="dialog"
    aria-modal="true"
    aria-labelledby="modal-{{ $name }}-title"
>
    <div x-show="open" x-transition.opacity.duration.150ms class="fixed inset-0 bg-[#07131f]/50 backdrop-blur-[2px]" x-on:click="open = false"></div>

    <div
        x-show="open"
        x-transition:enter="transition duration-150 ease-out"
        x-transition:enter-start="opacity-0 translate-y-1 scale-[.98]"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-trap.noscroll="open"
        {{ $attributes->merge(['class' => "relative w-full {$width} rounded-xl border border-line bg-surface shadow-[0_24px_60px_-20px_rgb(7_19_31/0.45)]"]) }}
    >
        <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <h2 id="modal-{{ $name }}-title" @class(['text-[0.9375rem] font-semibold', 'text-danger' => $tone === 'danger'])>{{ $title }}</h2>
            <button type="button" class="-m-1 rounded-md p-1 text-faint hover:text-ink" x-on:click="open = false">
                <x-icon name="x" />
                <span class="sr-only">{{ __('Sluiten') }}</span>
            </button>
        </div>
        <div class="px-5 py-4">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex items-center justify-end gap-2 rounded-b-xl border-t border-line bg-raised px-5 py-3">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
