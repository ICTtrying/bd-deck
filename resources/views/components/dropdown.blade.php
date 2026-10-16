@props(['align' => 'right', 'width' => 'w-60', 'label' => null])

{{-- Menu met knop: de knop-opmaak geef je mee als class, de menu-items staan in de standaardslot --}}
<div x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="relative">
    <button type="button" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open" @if ($label) title="{{ $label }}" @endif {{ $attributes }}>
        {{ $trigger }}
    </button>
    <div x-show="open" x-cloak x-on:click.outside="open = false" x-on:click="open = false" x-transition.opacity.duration.100ms role="menu"
        @class(['absolute top-full z-30 mt-1.5 rounded-xl border border-line bg-surface p-1 shadow-[0_16px_40px_-16px_rgb(7_19_31/0.4)]', $width, 'right-0' => $align === 'right', 'left-0' => $align === 'left'])>
        {{ $slot }}
    </div>
</div>
