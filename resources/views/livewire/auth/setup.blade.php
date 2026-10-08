<div class="grid gap-8">
    <div class="grid gap-5">
        <img src="{{ asset('images/bd-mark.png') }}" alt="" class="size-12">
        <div class="grid gap-2">
            <h1 class="text-2xl font-semibold tracking-[-0.015em]">{{ __('Welkom bij BD Deck') }}</h1>
            <p class="text-muted">{{ __('Kies een hoofdwachtwoord. Daarmee open je de app en versleutel je bewaarde wachtwoorden en API-sleutels. Zonder dit wachtwoord zijn ze niet terug te halen.') }}</p>
        </div>
    </div>

    <form wire:submit="save" class="grid gap-4">
        <x-field :label="__('Hoofdwachtwoord')" for="password" error="password" :hint="__('Minstens 10 tekens.')">
            <x-input id="password" type="password" wire:model="password" autocomplete="new-password" autofocus required />
        </x-field>
        <x-field :label="__('Herhaal wachtwoord')" for="password_confirmation">
            <x-input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" required />
        </x-field>
        <x-button type="submit" variant="primary" size="lg" class="mt-2 w-full" wire:loading.attr="disabled">{{ __('App instellen') }}</x-button>
    </form>

    <p class="text-[0.8125rem] text-faint">Borgman Digital</p>
</div>
