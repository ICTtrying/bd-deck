<div class="grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
    <x-panel :title="__('Bewaarde back-ups')" :description="__('Automatisch gemaakt voor elke push, migratie en database-import.')" :padding="false">
        <x-slot:actions>
            <x-button size="sm" variant="ghost" icon="folder" wire:click="openFolder">{{ __('Map openen') }}</x-button>
        </x-slot:actions>

        @if ($this->backups === [])
            <div class="p-5">
                <x-empty-state icon="archive" :title="__('Nog geen back-ups')" :description="__('Zodra je iets live zet of lokaal opnieuw opbouwt, bewaart BD Deck eerst een back-up. Je kunt er ook zelf een maken.')" />
            </div>
        @else
            <ul class="divide-y divide-line">
                @foreach ($this->backups as $backup)
                    @php $created = \Illuminate\Support\Carbon::parse($backup['created'] ?: now()); @endphp
                    <li class="flex flex-wrap items-center gap-3 px-5 py-3" wire:key="backup-{{ $backup['id'] }}">
                        <x-icon name="archive" class="text-faint" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">{{ $this->kindLabels()[$backup['kind']] ?? $backup['kind'] }} <span class="font-normal text-muted">{{ $created->isoFormat('D MMM, HH:mm') }}</span></p>
                            <p class="text-[0.8125rem] text-muted">
                                {{ collect($backup['parts'])->map(fn ($part) => $this->partLabels()[$part]['label'] ?? $part)->implode(', ') ?: __('Leeg') }}
                                <span class="text-faint">({{ \Illuminate\Support\Number::fileSize($backup['size'], precision: 1) }})</span>
                            </p>
                        </div>
                        @if (collect($backup['parts'])->contains(fn ($part) => $this->partLabels()[$part]['restore'] ?? null))
                            <x-button size="sm" icon="rotate-ccw" wire:click="startRestore('{{ $backup['id'] }}')">{{ __('Terugzetten') }}</x-button>
                        @endif
                        <x-button size="icon" variant="ghost" icon="trash" wire:click="delete('{{ $backup['id'] }}')" wire:confirm="{{ __('Deze back-up definitief verwijderen?') }}" :title="__('Verwijderen')" />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>

    <x-panel :title="__('Back-up maken')">
        <div class="grid gap-3">
            <x-toggle wire:model="includeLocal" :label="__('Lokale database en git-repo')" :disabled="! $site->isBuilt()" />
            <x-toggle wire:model="includeLiveDatabase" :label="__('Live database')" :description="$site->mode->hasShell() ? __('Via WP-CLI op de server.') : __('Via een tijdelijk exportbestand op de site.')" />
            <x-toggle wire:model="includeLiveFiles" :label="__('Live thema\'s en plugins')" :description="__('Alles in wp-content behalve uploads en caches.')" />
            <x-button variant="primary" icon="archive" wire:click="create" class="mt-1 justify-self-start">{{ __('Back-up maken') }}</x-button>
        </div>
    </x-panel>

    <x-modal name="restore" :title="__('Back-up terugzetten')">
        @php $touchesLive = array_intersect($restoreParts, ['live-db', 'live-files']) !== []; @endphp
        <div class="grid gap-4">
            <p class="text-muted">{{ __('Kies wat je wilt terugzetten. Van wat je overschrijft, wordt eerst opnieuw een back-up gemaakt.') }}</p>
            <div class="grid gap-1">
                @foreach ([
                    'local-db' => __('Lokale database'),
                    'live-files' => __('Live-bestanden: de push ongedaan maken'),
                    'live-db' => __('Live database'),
                ] as $part => $label)
                    @php
                        $file = ['local-db' => 'local-db.sql.gz', 'live-files' => 'live-files', 'live-db' => 'live-db.sql.gz'][$part];
                        $available = $restoring && collect(collect($this->backups)->firstWhere('id', $restoring)['parts'] ?? [])->contains($file) && ($this->partLabels()[$file]['restore'] ?? null);
                    @endphp
                    @if ($available)
                        <label class="flex items-center gap-2.5 rounded-lg px-1 py-1.5 text-sm">
                            <input type="checkbox" value="{{ $part }}" wire:model.live="restoreParts" class="size-4 rounded border-line-strong accent-[var(--local)]">
                            {{ $label }}
                        </label>
                    @endif
                @endforeach
            </div>
            @if ($touchesLive)
                <div class="grid gap-3 rounded-lg border border-danger/30 bg-danger-soft p-3">
                    <p class="flex items-start gap-2 text-sm text-danger"><x-icon name="alert" class="mt-0.5" />{{ __('Dit overschrijft live (:host).', ['host' => $site->data('live_host')]) }}</p>
                    <x-field :label="__('Typ :name om te bevestigen', ['name' => $site->name])" for="restore-confirm" error="confirmText">
                        <x-input id="restore-confirm" wire:model="confirmText" autocomplete="off" mono />
                    </x-field>
                </div>
            @endif
        </div>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button :variant="$touchesLive ? 'danger' : 'primary'" icon="rotate-ccw" wire:click="restore" :disabled="$restoreParts === []">{{ __('Terugzetten') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
