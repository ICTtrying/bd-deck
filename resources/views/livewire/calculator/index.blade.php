@use('App\Enums\QuoteManagement')
@use('App\Enums\QuotePlatform')
@use('App\Enums\QuoteProjectType')
@use('App\Enums\QuoteTariff')
@use('App\Support\Money')

@php
    $quote = $this->quote;
    $isShop = $type->isShop();
    $hours = rtrim(rtrim(number_format($quote->totalHours(), 1, ',', ''), '0'), ',');
    $rates = [
        QuoteTariff::Standard->value => QuoteTariff::Standard->label().' · '.Money::format(QuoteTariff::Standard->hourlyRateCents()).'/'.__('uur'),
        QuoteTariff::Friend->value => QuoteTariff::Friend->label().' · '.Money::format(QuoteTariff::Friend->hourlyRateCents()).'/'.__('uur'),
    ];
@endphp

<div class="grid gap-6">
    <x-page-header :title="__('Calculator')" :description="__('Stel een offerte samen met de prijzen van borgmandigital.nl. Kies de soort opdracht, vul in wat de klant wil en kopieer het resultaat als offertetekst.')" />

    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4" role="radiogroup" aria-label="{{ __('Soort opdracht') }}">
        @foreach ($projectTypes as $option)
            <button type="button" wire:click="selectType('{{ $option->value }}')" role="radio" aria-checked="{{ $type === $option ? 'true' : 'false' }}" @class([
                'grid gap-1 rounded-xl border p-4 text-left transition-colors',
                'border-local bg-local-soft' => $type === $option,
                'border-line bg-surface hover:border-line-strong' => $type !== $option,
            ])>
                <span class="flex items-center gap-2 text-sm font-semibold"><x-icon :name="$option->icon()" />{{ $option->label() }}</span>
                <span class="text-[0.8125rem] text-muted">{{ $option->description() }}</span>
            </button>
        @endforeach
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="grid gap-6">
            @if ($type === QuoteProjectType::Website)
                <x-panel :title="__('Website')" :description="__('Het pakket bepaalt wat er inbegrepen is; daarboven reken je meerwerk.')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field :label="__('Aantal pagina\'s')" for="pages" :hint="__('Start tot 3, Groei tot 6, Pro tot 10 pagina\'s.')">
                            <x-input id="pages" type="number" min="1" wire:model.live.debounce.300ms="pages" class="w-24" />
                        </x-field>
                        <x-field :label="__('Pakket')" for="package">
                            <x-select id="package" wire:model.live="package">
                                <option value="auto">{{ __('Automatisch op basis van pagina\'s') }}</option>
                                @foreach ($websitePackages as $key => $websitePackage)
                                    <option value="{{ $key }}">{{ $websitePackage['label'] }} · {{ Money::format($websitePackage['price']) }}</option>
                                @endforeach
                            </x-select>
                        </x-field>
                    </div>
                </x-panel>
            @elseif ($isShop)
                <x-panel :title="__('Webshop')" :description="__('Start tot 50 producten; zodra de klant meer of een Groei-functie wil, wordt het Webshop Groei.')">
                    <div class="grid gap-4">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-field :label="__('Platform')" for="platform">
                                <x-select id="platform" wire:model.live="platform">
                                    @foreach (QuotePlatform::cases() as $option)
                                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                    @endforeach
                                </x-select>
                            </x-field>
                            <x-field :label="__('Aantal producten')" for="products">
                                <x-input id="products" type="number" min="0" wire:model.live.debounce.300ms="products" class="w-28" />
                            </x-field>
                            <x-field :label="__('Pagina\'s naast de shop')" for="shop-pages" :hint="__('Start 2, Groei 4 inbegrepen.')">
                                <x-input id="shop-pages" type="number" min="0" wire:model.live.debounce.300ms="pages" class="w-24" />
                            </x-field>
                        </div>
                        <div class="grid gap-1 divide-y divide-line">
                            <x-toggle :label="__('Varianten')" :description="__('Maat, kleur en andere opties per product.')" wire:model.live="hasVariants" />
                            <x-toggle :label="__('Meerdere verzendzones')" :description="__('Verzendkosten per land, gewicht of bedrag.')" wire:model.live="multipleShippingZones" />
                            <x-toggle :label="__('Kortingscodes')" wire:model.live="discountCodes" />
                            <x-toggle :label="__('Extra betaalmethode')" :description="__('Naast iDEAL en creditcard.')" wire:model.live="extraPaymentMethod" />
                        </div>
                    </div>
                </x-panel>
            @elseif ($type === QuoteProjectType::WebApp)
                <x-panel :title="__('Webapplicatie')" :description="__('Eerst een intake voor een vaste prijs, optioneel een prototype, daarna de bouw.')">
                    <div class="grid gap-4">
                        <div class="grid gap-1 divide-y divide-line">
                            <x-toggle :label="__('Intakesessie')" :description="__('€ 195; wordt verrekend als de klant doorgaat met de bouw.')" wire:model.live="includeIntake" />
                            <x-toggle :label="__('Klikbaar prototype')" :description="__('€ 695; maximaal 3 schermen en 1 soort gebruiker.')" wire:model.live="includePrototype" />
                        </div>
                        <x-field :label="__('Geschatte bouwuren')" for="build-hours" :hint="__('Leeg of 0 laat de bouw weg: die krijgt na de intake een vaste prijs. Standaardtarief heeft een minimum van € 2.500.')">
                            <div class="flex items-center gap-2">
                                <x-input id="build-hours" type="number" min="0" step="1" wire:model.live.debounce.300ms="buildHours" class="w-24" />
                                <span class="text-muted">{{ __('uur') }}</span>
                            </div>
                        </x-field>
                    </div>
                </x-panel>
            @elseif ($type === QuoteProjectType::Other)
                <x-panel :title="__('Overig werk')" :description="__('Losse uren tegen het tarief dat je rechts bovenaan kiest.')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field :label="__('Omschrijving (optioneel)')" for="other-description" :hint="__('Komt in de offertetekst te staan, bijvoorbeeld: Site verhuizen naar een nieuwe host.')" class="sm:col-span-2">
                            <x-input id="other-description" maxlength="120" wire:model.live.debounce.300ms="otherDescription" />
                        </x-field>
                        <x-field :label="__('Aantal uren')" for="other-hours" :hint="__('Halve uren mag, bijvoorbeeld 1,5.')">
                            <div class="flex items-center gap-2">
                                <x-input id="other-hours" type="number" min="0" step="0.5" wire:model.live.debounce.300ms="otherHours" class="w-24" />
                                <span class="text-muted">{{ __('uur') }}</span>
                            </div>
                        </x-field>
                    </div>
                </x-panel>
            @endif

            @if ($type->usesPackages())
                <x-panel :title="__('Meerwerk')" :description="__('Alleen invullen wat boven het pakket uitkomt.')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field :label="__('Extra formulieren')" for="extra-forms">
                            <x-input id="extra-forms" type="number" min="0" wire:model.live.debounce.300ms="extraForms" class="w-24" />
                        </x-field>
                        <x-field :label="__('Extra wijzigingsrondes')" for="extra-rounds">
                            <x-input id="extra-rounds" type="number" min="0" wire:model.live.debounce.300ms="extraRounds" class="w-24" />
                        </x-field>
                        <x-field :label="__('Pagina\'s waar ik teksten en beeld plaats')" for="content-pages">
                            <x-input id="content-pages" type="number" min="0" wire:model.live.debounce.300ms="contentPages" class="w-24" />
                        </x-field>
                        <x-field :label="__('Uitleg (per half uur)')" for="explanation">
                            <x-input id="explanation" type="number" min="0" wire:model.live.debounce.300ms="explanationHalfHours" class="w-24" />
                        </x-field>
                    </div>
                    <div class="mt-3">
                        <x-toggle :label="__('Logo en favicon plaatsen')" wire:model.live="replaceLogo" />
                    </div>
                </x-panel>
            @endif

            @if ($type !== QuoteProjectType::Other)
                <x-panel :title="__('Maandelijkse kosten')" :description="__('Vast geprijsd, dus zonder vriendenkorting.')">
                    @if ($type === QuoteProjectType::WebApp)
                        <x-toggle :label="__('Hosting en onderhoud Laravel')" :description="__('Vanaf € 75 per maand, met 1 uur onderhoud.')" wire:model.live="webAppHosting" />
                    @else
                        <div class="grid gap-4">
                            <x-segmented :label="__('Beheer')" property="management" :value="$management->value" :options="collect(QuoteManagement::cases())->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all()" />
                            @if ($management !== QuoteManagement::None)
                                <x-toggle :label="__('Jaarlijks vooraf betalen')" :description="__('De klant betaalt 11 maanden in plaats van 12.')" wire:model.live="yearlyBilling" />
                            @endif
                        </div>
                    @endif
                </x-panel>
            @endif
        </div>

        <aside class="grid gap-4 lg:sticky lg:top-6" aria-label="{{ __('Offerte') }}">
            <x-segmented :label="__('Tarief')" property="tariff" :value="$tariff->value" :options="$rates" />

            <x-panel :title="$quote->title" :padding="false">
                @if ($quote->isEmpty())
                    <p class="px-5 py-4 text-muted">{{ __('Nog niets om te berekenen.') }}</p>
                @endif

                @if ($quote->lines !== [])
                    <ul class="divide-y divide-line">
                        @foreach ($quote->lines as $line)
                            <li class="flex items-start justify-between gap-4 px-5 py-2.5" wire:key="line-{{ $loop->index }}">
                                <span class="grid min-w-0 gap-0.5">
                                    <span class="text-sm">{{ $line->label }}</span>
                                    @if ($line->detail)
                                        <span class="text-[0.8125rem] text-muted">{{ $line->detail }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 text-sm tabular-nums">{{ Money::format($line->cents($tariff)) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <dl class="grid gap-1 border-t border-line px-5 py-3 text-sm">
                        <div class="flex justify-between gap-4 text-muted"><dt>{{ __('Totaal excl. btw') }}</dt><dd class="tabular-nums">{{ Money::format($quote->totalCents($tariff)) }}</dd></div>
                        <div class="flex justify-between gap-4 text-muted"><dt>{{ __('Btw :percentage%', ['percentage' => config('quote.vat_percentage')]) }}</dt><dd class="tabular-nums">{{ Money::format($quote->vatCents($tariff)) }}</dd></div>
                        <div class="flex justify-between gap-4 pt-1 text-base font-semibold"><dt>{{ __('Totaal incl. btw') }}</dt><dd class="tabular-nums">{{ Money::format($quote->totalInclVatCents($tariff)) }}</dd></div>
                    </dl>
                @endif

                @if ($quote->recurring !== [])
                    <ul class="divide-y divide-line border-t border-line">
                        @foreach ($quote->recurring as $line)
                            <li class="grid gap-0.5 px-5 py-2.5" wire:key="recurring-{{ $loop->index }}">
                                <span class="flex justify-between gap-4 text-sm"><span>{{ $line->label }}</span><span class="tabular-nums">{{ Money::format($line->monthlyCents) }} {{ __('p/m') }}</span></span>
                                <span class="text-[0.8125rem] text-muted">{{ $line->detail }}</span>
                                @if ($line->billedMonthsPerYear < 12)
                                    <span class="text-[0.8125rem] text-muted">{{ __('Jaarlijks vooraf: :amount', ['amount' => Money::format($line->yearlyCents())]) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-panel>

            @if ($quote->lines !== [])
                <div class="flex flex-wrap items-center justify-between gap-2 text-[0.8125rem] text-muted">
                    <span>
                        @if ($hours !== '' && $hours !== '0')
                            {{ __('Ongeveer :hours uur werk.', ['hours' => $hours]) }}
                        @endif
                        @if ($tariff === QuoteTariff::Friend)
                            {{ __('Scheelt :amount t.o.v. standaardtarief.', ['amount' => Money::format($quote->friendDiscountCents())]) }}
                        @else
                            {{ __('Vriendenprijs: :amount.', ['amount' => Money::format($quote->totalCents(QuoteTariff::Friend))]) }}
                        @endif
                    </span>
                    <x-copy-button :text="$quote->toText($tariff)" :label="__('Kopieer offertetekst')" />
                </div>
            @endif

            @foreach ($quote->notes as $note)
                <p class="flex items-start gap-2 rounded-lg border border-line bg-raised px-3 py-2.5 text-[0.8125rem]" wire:key="note-{{ $loop->index }}">
                    <x-icon name="info" :size="14" class="mt-0.5 shrink-0 text-faint" />{{ $note }}
                </p>
            @endforeach
        </aside>
    </div>
</div>
