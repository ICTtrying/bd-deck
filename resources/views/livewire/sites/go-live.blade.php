@php
    /** @var \App\Models\Site $site */
    $checks = $site->goLiveChecks();
    $active = $this->activeRun;
    $recentMove = $this->recentMove;
    $issues = $site->goLiveIssues();
    $highlight = $recentMove !== null || $issues > 0;
    $hints = $this->hints();
    $fixes = $this->fixes();
@endphp

<div @if ($active) wire:poll.2s="poll" @endif>
    <x-panel :title="__('Go-live-checklist')" :class="$highlight ? 'ring-2 ring-warning/40' : ''" :description="$site->go_live_checked_at
        ? __('Gecontroleerd :time · :ok van :total in orde', ['time' => $site->go_live_checked_at->diffForHumans(), 'ok' => $site->goLivePassed(), 'total' => $site->goLiveApplicable()])
        : __('Nog niet gecontroleerd')">
        <x-slot:actions>
            @if ($active)
                <x-badge tone="info" dot>{{ __('Bezig met controleren…') }}</x-badge>
            @elseif ($checks !== [])
                <x-button size="sm" icon="refresh" wire:click="check">{{ __('Opnieuw controleren') }}</x-button>
            @endif
        </x-slot:actions>

        <div class="grid gap-3">
            @if ($recentMove)
                <p class="flex flex-wrap items-center gap-2 text-[0.8125rem] text-muted">
                    <x-badge tone="warning">{{ $recentMove->action === \App\Enums\WpOpenAction::Migrate ? __('Net verhuisd') : __('Nieuw domein') }}</x-badge>
                    {{ __(':time. Loop deze punten na voordat je de klant laat weten dat de site live staat.', ['time' => \Illuminate\Support\Str::ucfirst($recentMove->finished_at->diffForHumans())]) }}
                </p>
            @endif

            @if ($active)
                <p class="flex items-center gap-2 text-[0.8125rem] text-muted"><x-icon name="loader" :size="14" class="animate-spin" />{{ __('BD Deck loopt live na; dat duurt meestal een halve minuut.') }}</p>
            @endif

            @if ($checks === [])
                @unless ($active)
                    <div class="grid justify-items-start gap-3">
                        <p class="text-muted">{{ $site->isLaravel()
                            ? __('Controleert of de debugmodus uit staat, HTTPS werkt, www doorverwijst en er een sitemap is. Gebeurt vanzelf na verhuizen of domein omzetten.')
                            : __('Controleert of zoekmachines welkom zijn, WP_DEBUG uit staat, mail werkt, er geen lokale adressen zijn achtergebleven, HTTPS werkt, www doorverwijst en er een sitemap is. Gebeurt vanzelf na verhuizen of domein omzetten.') }}</p>
                        <x-button size="sm" variant="primary" icon="flag" wire:click="check">{{ __('Controleren') }}</x-button>
                    </div>
                @endunless
            @else
                <ul class="-my-1 divide-y divide-line">
                    @foreach ($checks as $check)
                        @php
                            $fix = $this->fixFor($check);
                            $open = in_array($check['status'], ['fail', 'warn'], true);
                        @endphp
                        <li wire:key="go-live-{{ $check['key'] }}" class="grid gap-2 py-2.5">
                            <div class="flex items-start gap-2.5">
                                <span @class([
                                    'mt-1 grid size-4 shrink-0 place-items-center rounded-full',
                                    'bg-success-soft text-success' => $check['status'] === 'ok',
                                    'bg-warning-soft text-warning' => $check['status'] === 'warn',
                                    'bg-danger-soft text-danger' => $check['status'] === 'fail',
                                    'bg-raised text-faint' => $check['status'] === 'skip',
                                ])>
                                    <x-icon :name="match ($check['status']) { 'ok' => 'check', 'fail' => 'x', 'warn' => 'alert', default => 'more' }" :size="10" />
                                </span>
                                <span class="grid min-w-0 flex-1 leading-snug">
                                    <span class="text-sm font-medium">{{ $check['label'] }}</span>
                                    <span class="text-[0.8125rem] text-muted">{{ $check['detail'] }}</span>
                                </span>
                                @if ($fix)
                                    <x-button size="sm" wire:click="confirmFix('{{ $fix }}')" :disabled="(bool) $active">{{ $fixes[$fix]['button'] }}</x-button>
                                @endif
                            </div>

                            @if ($open && ! $fix && isset($hints[$check['key']]))
                                <p class="flex items-start gap-1.5 pl-6.5 text-[0.8125rem] text-ink"><x-icon name="info" :size="14" class="mt-px shrink-0 text-faint" />{{ $hints[$check['key']] }}</p>
                            @endif

                            @if ($check['key'] === 'mail' && $check['status'] !== 'skip')
                                <form wire:submit="sendTestMail" class="grid gap-1.5 pl-6.5">
                                    <div class="flex gap-2">
                                        <x-input type="email" wire:model="mailTo" :placeholder="__('jij@bureau.nl')" :aria-label="__('E-mailadres voor de testmail')" autocomplete="email" />
                                        <x-button type="submit" size="sm" class="h-9" :disabled="(bool) $active">{{ __('Testmail sturen') }}</x-button>
                                    </div>
                                    @error('mailTo')
                                        <p class="text-[0.8125rem] text-danger">{{ $message }}</p>
                                    @enderror
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-panel>

    <x-modal name="go-live-fix" :title="$fixConfirmation['title'] ?? ''">
        <p class="text-muted">{{ $fixConfirmation['body'] ?? '' }}</p>
        <x-slot:footer>
            <x-button x-on:click="open = false">{{ __('Annuleren') }}</x-button>
            <x-button variant="primary" wire:click="fix">{{ $fixConfirmation['button'] ?? __('Doorgaan') }}</x-button>
        </x-slot:footer>
    </x-modal>
</div>
