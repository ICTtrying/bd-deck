@php $unread = $this->unread; @endphp

<div class="inline-flex items-center" @if ($site->isRunning()) wire:poll.30s.visible @endif>
    <button type="button" wire:click="launch({{ $site->id }}, 'mailpit')" wire:loading.attr="disabled" wire:target="launch" title="{{ __('Mail van de lokale site komt hier binnen; er gaat niets echt de deur uit.') }}" class="inline-flex h-7 items-center gap-1.5 rounded-l-md bg-surface/70 px-2 text-[0.8125rem] font-medium transition-colors hover:bg-surface disabled:opacity-60">
        <x-icon name="mail" :size="14" />Mailpit
        @if ($unread)
            <span class="rounded-full bg-local px-1.5 text-2xs leading-4 font-semibold text-white dark:text-[#07131f]" title="{{ trans_choice(':count ongelezen mail|:count ongelezen mails', $unread) }}">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>
    <x-dropdown align="left" width="w-56" class="ml-px inline-flex h-7 items-center rounded-r-md bg-surface/70 px-1 transition-colors hover:bg-surface" :label="__('Meer voor Mailpit')">
        <x-slot:trigger>
            <x-icon name="loader" :size="12" class="animate-spin" wire:loading wire:target="sendTestMail" />
            <x-icon name="chevron-down" :size="12" wire:loading.remove wire:target="sendTestMail" />
        </x-slot:trigger>
        <x-menu-item icon="send" wire:click="sendTestMail" :description="__('Verstuurd vanaf de site zelf, naar een adres dat niet bestaat.')">{{ __('Testmail sturen') }}</x-menu-item>
    </x-dropdown>
</div>
