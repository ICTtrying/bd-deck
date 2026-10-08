<div class="grid gap-8">
    <div class="flex items-center gap-3">
        <img src="{{ asset('images/bd-mark.png') }}" alt="" class="size-11 dark:hidden"><img src="{{ asset('icon.png') }}" alt="" class="size-11 hidden dark:block">
        <div>
            <h1 class="text-xl font-semibold tracking-[-0.01em]">BD Deck</h1>
            <p class="text-[0.8125rem] text-muted">Borgman Digital</p>
        </div>
    </div>

    <form wire:submit="login" class="grid gap-4">
        <x-field :label="__('Hoofdwachtwoord')" for="password" error="password">
            <x-input id="password" type="password" wire:model="password" autocomplete="current-password" autofocus required :aria-invalid="$errors->has('password') ? 'true' : null" />
        </x-field>
        <x-button type="submit" variant="primary" size="lg" class="w-full" wire:loading.attr="disabled">
            <x-icon name="lock" wire:loading.remove wire:target="login" />
            <x-icon name="loader" class="animate-spin" wire:loading wire:target="login" />
            {{ __('Ontgrendelen') }}
        </x-button>
    </form>
</div>
