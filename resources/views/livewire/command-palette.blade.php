<div
    x-data="{
        open: false,
        active: 0,
        show() { this.open = true; this.active = 0; this.$nextTick(() => this.$refs.input.focus()) },
        move(step) {
            const count = this.$refs.list?.querySelectorAll('[data-item]').length ?? 0;
            if (count) this.active = (this.active + step + count) % count;
            this.$refs.list?.querySelectorAll('[data-item]')[this.active]?.scrollIntoView({ block: 'nearest' });
        },
    }"
    x-on:open-palette.window="show()"
    x-on:close-palette.window="open = false"
    x-on:keydown.escape.window="open = false"
>
    <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-start justify-center px-4 pt-[14vh]" role="dialog" aria-modal="true" aria-label="{{ __('Snelzoeker') }}">
        <div class="fixed inset-0 bg-[#07131f]/45 backdrop-blur-[2px]" x-on:click="open = false" x-show="open" x-transition.opacity.duration.100ms></div>

        <div class="relative w-full max-w-xl overflow-hidden rounded-xl border border-line bg-surface shadow-[0_30px_80px_-20px_rgb(7_19_31/0.55)]" x-show="open" x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="opacity-0 scale-[.98]" x-trap="open">
            <div class="flex items-center gap-3 border-b border-line px-4">
                <x-icon name="search" class="text-faint" />
                <input
                    x-ref="input"
                    type="text"
                    wire:model.live.debounce.120ms="query"
                    x-on:input="active = 0"
                    x-on:keydown.arrow-down.prevent="move(1)"
                    x-on:keydown.arrow-up.prevent="move(-1)"
                    x-on:keydown.enter.prevent="$wire.choose(active)"
                    placeholder="{{ __('Zoek een site of typ een pagina…') }}"
                    class="h-12 flex-1 bg-transparent text-[0.9375rem] outline-none placeholder:text-faint"
                    autocomplete="off"
                    spellcheck="false"
                    aria-label="{{ __('Zoeken') }}"
                >
                <kbd class="rounded border border-line px-1.5 text-2xs text-faint">Esc</kbd>
            </div>

            <ul class="max-h-[50vh] overflow-y-auto p-1.5" x-ref="list">
                @forelse ($this->results as $index => $item)
                    <li wire:key="palette-{{ $index }}-{{ md5($item['label'].$item['hint']) }}">
                        <button
                            type="button"
                            data-item
                            x-on:mouseenter="active = {{ $index }}"
                            wire:click="choose({{ $index }})"
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left"
                            :class="active === {{ $index }} ? 'bg-local-soft text-local-ink' : 'text-ink'"
                        >
                            <x-icon :name="$item['icon']" class="text-faint" />
                            <span class="flex-1 truncate text-sm font-medium">{{ $item['label'] }}</span>
                            <span class="truncate text-[0.8125rem] text-faint">{{ $item['hint'] }}</span>
                        </button>
                    </li>
                @empty
                    <li class="px-3 py-6 text-center text-muted">{{ __('Niets gevonden voor “:query”.', ['query' => $query]) }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
