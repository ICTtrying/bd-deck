@php
    $key = $this->defaultKey;
    $step = fn (int $number, ?bool $done): string => $done ? 'bg-success-soft text-success' : 'bg-live-soft text-live-ink';
@endphp

<div class="grid max-w-3xl gap-6" x-data="{ host: 'wpmudev' }">
    <x-page-header :title="__('Aan de slag')" :description="__('In vier stappen klaar om je eigen WordPress-sites te beheren. Alles wat je hier instelt staat alleen op jouw computer: je sites, je sleutels en je kluis.')" />

    {{-- 1. programma's --}}
    <x-panel>
        <div class="grid gap-4">
            <div class="flex flex-wrap items-start gap-3">
                <span class="grid size-7 shrink-0 place-items-center rounded-full text-sm font-semibold {{ $step(1, $toolsReady) }}">@if ($toolsReady)<x-icon name="check" :size="14" />@else 1 @endif</span>
                <div class="grid min-w-0 flex-1 gap-0.5">
                    <h2 class="text-[0.9375rem] font-semibold">{{ __('Alles installeren') }}</h2>
                    <p class="text-[0.8125rem] text-muted">{{ __('Eén script voor Linux Mint installeert wat ontbreekt: git, rsync, ssh, lftp, Docker, DDEV, VS Code en de GitHub CLI. Het maakt ook je SSH-sleutel en zet BD Deck in je app-menu. Vraagt één keer je wachtwoord en is veilig om opnieuw te draaien.') }}</p>
                </div>
            </div>
            <div class="ml-10 flex flex-wrap gap-2">
                <x-button size="sm" variant="primary" icon="terminal" wire:click="runInstaller">{{ __('Installeren in een terminal') }}</x-button>
                <x-button size="sm" variant="ghost" icon="activity" wire:click="runChecks">{{ __('Controleren wat er al is') }}</x-button>
            </div>
            <div class="ml-10 grid gap-1.5 rounded-lg bg-raised p-3">
                <p class="text-[0.8125rem] text-muted">{{ __('Collega zonder BD Deck? Laat diegene deze regel in een terminal plakken (Linux Mint). Die installeert alles en de nieuwste versie van de app:') }}</p>
                @php $oneLiner = $this->installCommand(); @endphp
                <div class="flex items-center gap-2">
                    <code class="min-w-0 flex-1 font-mono text-xs text-ink select-all">{{ $oneLiner }}</code>
                    <x-copy-button :text="$oneLiner" />
                </div>
            </div>
            @if ($checks !== null)
                <ul class="grid gap-1.5 pl-10 text-sm">
                    @foreach ($checks as $check)
                        <li class="flex items-center gap-2">
                            <x-icon :name="match ($check['status']) { 'ok' => 'check', 'fail' => 'x', default => 'more' }" :size="14" @class(['text-success' => $check['status'] === 'ok', 'text-danger' => $check['status'] === 'fail', 'text-faint' => ! in_array($check['status'], ['ok', 'fail'])]) />
                            <span class="font-medium">{{ $check['label'] }}</span>
                            <span class="truncate text-[0.8125rem] text-muted">{{ $check['detail'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($toolsReady === false)
                <p class="ml-10 text-[0.8125rem] text-warning">{{ __('Er ontbreekt nog iets: kies Installeren in een terminal.') }}</p>
            @endif
        </div>
    </x-panel>

    {{-- 2. sleutel --}}
    <x-panel>
        <div class="grid gap-4">
            <div class="flex flex-wrap items-start gap-3">
                <span class="grid size-7 shrink-0 place-items-center rounded-full text-sm font-semibold {{ $step(2, $key !== null) }}">@if ($key)<x-icon name="check" :size="14" />@else 2 @endif</span>
                <div class="grid min-w-0 flex-1 gap-0.5">
                    <h2 class="text-[0.9375rem] font-semibold">{{ __('Jouw SSH-sleutel') }}</h2>
                    <p class="text-[0.8125rem] text-muted">{{ __('Hiermee log je zonder wachtwoord in op je servers. De publieke sleutel mag je delen; de private sleutel blijft altijd op je computer.') }}</p>
                </div>
                @unless ($key)
                    <x-button size="sm" variant="primary" icon="key" wire:click="createKey">{{ __('Sleutel maken') }}</x-button>
                @endunless
            </div>
            @if ($key)
                <div class="ml-10 flex items-start gap-2">
                    <code class="min-w-0 flex-1 rounded-lg bg-raised px-3 py-2 font-mono text-xs leading-5 break-all text-muted select-all">{{ $key['public_key'] }}</code>
                    <x-copy-button :text="$key['public_key']" />
                </div>
            @endif
        </div>
    </x-panel>

    {{-- 3. sleutel bij de host --}}
    <x-panel>
        <div class="grid gap-4">
            <div class="flex flex-wrap items-start gap-3">
                <span class="grid size-7 shrink-0 place-items-center rounded-full text-sm font-semibold {{ $step(3, null) }}">3</span>
                <div class="grid min-w-0 flex-1 gap-0.5">
                    <h2 class="text-[0.9375rem] font-semibold">{{ __('Sleutel bij je hosting zetten') }}</h2>
                    <p class="text-[0.8125rem] text-muted">{{ __('Eén keer per site of hostingaccount. Lukt het niet? Vul bij het toevoegen van de site het SSH-wachtwoord in, dan zet BD Deck de sleutel er zelf op.') }}</p>
                </div>
            </div>
            <div class="ml-10 grid gap-3">
                <div class="flex w-fit rounded-lg border border-line bg-surface p-0.5" role="tablist">
                    @foreach (['wpmudev' => 'WPMU DEV', 'hostinger' => 'Hostinger', 'other' => __('Andere host')] as $value => $label)
                        <button type="button" role="tab" x-on:click="host = '{{ $value }}'" x-bind:aria-selected="host === '{{ $value }}'" x-bind:class="host === '{{ $value }}' ? 'bg-local-soft font-medium text-local-ink' : 'text-muted hover:text-ink'" class="h-7 rounded-md px-2.5 text-[0.8125rem] transition-colors">{{ $label }}</button>
                    @endforeach
                </div>
                <ol x-show="host === 'wpmudev'" class="grid list-decimal gap-1.5 pl-5 text-sm">
                    <li>{{ __('Open de WPMU DEV Hub en kies de site.') }}</li>
                    <li>{{ __('Ga naar Hosting → SFTP/SSH en maak een gebruiker van het type SSH (een SFTP-gebruiker werkt ook, maar dan zonder WP-CLI).') }}</li>
                    <li>{{ __('Plak je publieke sleutel uit stap 2 bij de SSH-sleutels van die gebruiker.') }}</li>
                    <li>{{ __('Kopieer de verbinding (gebruiker@site.tempurl.host) en plak die bij Site toevoegen.') }}</li>
                </ol>
                <ol x-show="host === 'hostinger'" x-cloak class="grid list-decimal gap-1.5 pl-5 text-sm">
                    <li>{{ __('Open hPanel → Websites → Beheren bij de site.') }}</li>
                    <li>{{ __('Ga naar Geavanceerd → SSH-toegang en zet SSH aan.') }}</li>
                    <li>{{ __('Kies SSH-sleutels → SSH-sleutel toevoegen en plak je publieke sleutel uit stap 2.') }}</li>
                    <li>{{ __('Kopieer het SSH-commando dat erboven staat (ssh -p 65002 u123…@1.2.3.4) en plak het bij Site toevoegen.') }}</li>
                </ol>
                <ol x-show="host === 'other'" x-cloak class="grid list-decimal gap-1.5 pl-5 text-sm">
                    <li>{{ __('Zoek in het hostingpaneel naar SSH-toegang of SSH-sleutels.') }}</li>
                    <li>{{ __('Plak je publieke sleutel uit stap 2, of gebruik het SSH-wachtwoord bij het toevoegen.') }}</li>
                    <li>{{ __('Biedt de host alleen FTP? Dan kan BD Deck de site niet koppelen; verhuis hem naar een host met SSH of SFTP.') }}</li>
                </ol>
            </div>
        </div>
    </x-panel>

    {{-- 4. eerste site --}}
    <x-panel>
        <div class="flex flex-wrap items-start gap-3">
            <span class="grid size-7 shrink-0 place-items-center rounded-full text-sm font-semibold {{ $step(4, $this->siteCount > 0) }}">@if ($this->siteCount > 0)<x-icon name="check" :size="14" />@else 4 @endif</span>
            <div class="grid min-w-0 flex-1 gap-3">
                <div class="grid gap-0.5">
                    <h2 class="text-[0.9375rem] font-semibold">{{ __('Je eerste site') }}</h2>
                    <p class="text-[0.8125rem] text-muted">{{ __('Koppel een bestaande site, of begin lokaal en zet hem later met één knop live. Werkt *.ddev.site niet op jouw netwerk? Dan vraagt BD Deck één keer per site je computerwachtwoord om de naam lokaal te registreren.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-button variant="primary" icon="plus" :href="route('sites.create')" wire:navigate>{{ __('Bestaande site koppelen') }}</x-button>
                    <x-button icon="laptop" :href="route('sites.create', ['soort' => 'lokaal'])" wire:navigate>{{ __('Nieuwe lokale site') }}</x-button>
                    @if ($this->siteCount > 0)
                        <x-button variant="ghost" :href="route('dashboard')" wire:navigate iconRight="chevron-right">{{ __('Naar je sites') }}</x-button>
                    @endif
                </div>
            </div>
        </div>
    </x-panel>
</div>
