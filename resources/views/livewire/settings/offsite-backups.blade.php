<x-panel id="offsite" :title="__('Back-ups in de cloud')" :description="__('Een kapotte of gestolen laptop kost je dan geen back-ups. Alles wordt op deze laptop versleuteld voordat het vertrekt; de opslagdienst kan niets lezen.')">
    <x-slot:actions>
        <x-button size="sm" icon="refresh" wire:click="syncNow" :disabled="! $enabled">{{ __('Nu synchroniseren') }}</x-button>
    </x-slot:actions>

    <form wire:submit="save" class="grid gap-5">
        <x-toggle wire:model.live="enabled" :label="__('Back-ups ook in de cloud bewaren')" :description="__('Elke tien minuten gaan nieuwe back-ups naar je eigen bucket bij Backblaze, Amazon, Cloudflare of Wasabi.')" />

        {{-- status alleen als er al iets ingesteld is --}}
        @if ($hasSecret || $cloudCount > 0)
            <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-lg border border-line bg-raised px-4 py-3 text-sm sm:grid-cols-4">
                <div class="grid">
                    <dt class="text-xs text-muted">{{ __('Laatste sync') }}</dt>
                    <dd class="font-medium">{{ $lastSyncAt?->diffForHumans() ?? __('Nog nooit') }}</dd>
                </div>
                <div class="grid">
                    <dt class="text-xs text-muted">{{ __('In de cloud') }}</dt>
                    <dd class="font-medium">{{ trans_choice(':count back-up|:count back-ups', $cloudCount) }}</dd>
                </div>
                <div class="grid">
                    <dt class="text-xs text-muted">{{ __('Totale grootte') }}</dt>
                    <dd class="font-medium">{{ \Illuminate\Support\Number::fileSize($cloudSize, precision: 1) }}</dd>
                </div>
                <div class="grid">
                    <dt class="text-xs text-muted">{{ __('Mislukte uploads') }}</dt>
                    <dd @class(['font-medium', 'text-danger' => $failed->isNotEmpty()])>{{ $failed->count() }}</dd>
                </div>
            </dl>
            @if ($busyCount > 0)
                <p class="flex items-center gap-2 text-[0.8125rem] text-muted"><x-icon name="loader" :size="14" class="animate-spin" />{{ trans_choice(':count back-up wordt geüpload|:count back-ups worden geüpload', $busyCount) }}</p>
            @endif
            @foreach ($failed->take(3) as $failure)
                <p class="flex items-start gap-2 text-[0.8125rem] text-danger"><x-icon name="alert" :size="14" class="mt-0.5 shrink-0" /><span><span class="font-medium">{{ $failure->site_name }}</span> · {{ $failure->backup_id }}: {{ $failure->error }}</span></p>
            @endforeach
            @if ($oldKeyCount > 0)
                <p class="flex items-start gap-2 text-[0.8125rem] text-warning"><x-icon name="key" :size="14" class="mt-0.5 shrink-0" />{{ trans_choice(':count back-up in de cloud is versleuteld met een eerder wachtwoord. Die kun je alleen terughalen met dat oude wachtwoord.|:count back-ups in de cloud zijn versleuteld met een eerder wachtwoord. Die kun je alleen terughalen met dat oude wachtwoord.', $oldKeyCount) }}</p>
            @endif
        @endif

        <div class="h-px bg-line"></div>

        <div class="grid gap-4">
            <h3 class="text-sm font-semibold">{{ __('Opslag') }}</h3>
            <x-field :label="__('Dienst')" for="offsite-provider" error="provider" :hint="$selectedProvider->hint()">
                <x-select id="offsite-provider" wire:model.live="provider">
                    @foreach ($providers as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-select>
            </x-field>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <x-field :label="__('Endpoint')" for="offsite-endpoint" error="endpoint">
                    <x-input id="offsite-endpoint" wire:model="endpoint" :placeholder="$selectedProvider->endpointPlaceholder()" mono />
                </x-field>
                <x-field :label="__('Regio')" for="offsite-region" error="region">
                    <x-input id="offsite-region" wire:model.blur="region" mono />
                </x-field>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field :label="__('Bucket')" for="offsite-bucket" error="bucket">
                    <x-input id="offsite-bucket" wire:model="bucket" mono autocomplete="off" />
                </x-field>
                <x-field :label="__('Map in de bucket')" for="offsite-prefix" error="prefix" :hint="__('Per site komt er een submap in.')">
                    <x-input id="offsite-prefix" wire:model="prefix" mono />
                </x-field>
                <x-field :label="__('Access key ID')" for="offsite-key" error="accessKeyId">
                    <x-input id="offsite-key" wire:model="accessKeyId" mono autocomplete="off" />
                </x-field>
                <x-field :label="__('Geheime sleutel')" for="offsite-secret" error="secretAccessKey" :hint="$hasSecret ? __('Opgeslagen. Laat leeg om hem te houden.') : null">
                    <x-input id="offsite-secret" type="password" wire:model="secretAccessKey" :placeholder="$hasSecret ? '••••••••••••' : ''" autocomplete="new-password" mono />
                </x-field>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-button size="sm" icon="zap" wire:click="testConnection">{{ __('Verbinding testen') }}</x-button>
                @if ($connectionResult)
                    <p @class(['flex items-start gap-1.5 text-[0.8125rem]', 'text-success' => $connectionResult['ok'], 'text-danger' => ! $connectionResult['ok']])>
                        <x-icon :name="$connectionResult['ok'] ? 'check' : 'alert'" :size="14" class="mt-0.5 shrink-0" />{{ $connectionResult['message'] }}
                    </p>
                @endif
            </div>
        </div>

        <div class="h-px bg-line"></div>

        <div class="grid gap-4">
            <div class="grid gap-1">
                <h3 class="text-sm font-semibold">{{ __('Versleuteling') }}</h3>
                <p class="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning-soft px-3 py-2 text-[0.8125rem] text-warning">
                    <x-icon name="lock" :size="14" class="mt-0.5 shrink-0" />
                    {{ __('Zonder dit wachtwoord kan niemand de back-ups terugzetten, ook jij niet. Bewaar het in je wachtwoordmanager.') }}
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field :label="$hasPassphrase ? __('Nieuw versleutelwachtwoord') : __('Versleutelwachtwoord')" for="offsite-passphrase" error="passphrase" :hint="$hasPassphrase ? __('Opgeslagen. Laat leeg om het te houden.') : __('Minstens 12 tekens.')">
                    <x-input id="offsite-passphrase" type="password" wire:model.live.debounce.400ms="passphrase" autocomplete="new-password" />
                </x-field>
                <x-field :label="__('Herhaal wachtwoord')" for="offsite-passphrase-confirm">
                    <x-input id="offsite-passphrase-confirm" type="password" wire:model="passphrase_confirmation" autocomplete="new-password" />
                </x-field>
            </div>
            @if ($hasPassphrase && $passphrase !== '')
                <p class="flex items-start gap-2 text-[0.8125rem] text-warning"><x-icon name="alert" :size="14" class="mt-0.5 shrink-0" />{{ __('Back-ups die al in de cloud staan houden het oude wachtwoord. Alleen nieuwe back-ups krijgen het nieuwe.') }}</p>
            @endif
        </div>

        <div class="h-px bg-line"></div>

        <div class="grid gap-4">
            <h3 class="text-sm font-semibold">{{ __('Hoeveel bewaren') }}</h3>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <x-field :label="__('Laatste')" for="keep-last" error="keepLast">
                    <x-input id="keep-last" type="number" min="1" max="100" wire:model.live.debounce.300ms="keepLast" />
                </x-field>
                <x-field :label="__('Dagen')" for="keep-daily" error="keepDaily">
                    <x-input id="keep-daily" type="number" min="0" max="365" wire:model.live.debounce.300ms="keepDaily" />
                </x-field>
                <x-field :label="__('Weken')" for="keep-weekly" error="keepWeekly">
                    <x-input id="keep-weekly" type="number" min="0" max="104" wire:model.live.debounce.300ms="keepWeekly" />
                </x-field>
                <x-field :label="__('Maanden')" for="keep-monthly" error="keepMonthly">
                    <x-input id="keep-monthly" type="number" min="0" max="120" wire:model.live.debounce.300ms="keepMonthly" />
                </x-field>
            </div>
            <p class="flex items-start gap-2 text-sm text-muted"><x-icon name="history" :size="14" class="mt-0.5 shrink-0" /><span>{{ $retentionSentence }} {{ __('Per site; de rest wordt uit de cloud verwijderd.') }}</span></p>
        </div>

        <div class="h-px bg-line"></div>

        <div class="grid gap-3">
            <x-toggle wire:model.live="cleanupLocal" :label="__('Lokale back-ups opruimen')" :description="__('Verwijdert lokale back-ups die ouder zijn dan het aantal dagen hieronder, maar alleen als ze aantoonbaar in de cloud staan. De nieuwste per site blijft altijd op de laptop.')" />
            @if ($cleanupLocal)
                <x-field :label="__('Ouder dan')" for="cleanup-days" error="cleanupLocalDays" class="max-w-48">
                    <div class="flex items-center gap-2">
                        <x-input id="cleanup-days" type="number" min="1" max="365" wire:model="cleanupLocalDays" class="w-24" />
                        <span class="text-muted">{{ __('dagen') }}</span>
                    </div>
                </x-field>
            @endif
        </div>

        <div class="flex justify-end">
            <x-button type="submit" variant="primary" icon="check">{{ __('Cloud-back-ups opslaan') }}</x-button>
        </div>
    </form>
</x-panel>
