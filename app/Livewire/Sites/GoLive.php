<?php

namespace App\Livewire\Sites;

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\AppSettings;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Go-live-checklist op het overzicht: na verhuizen of domein omzetten nalopen wat bezoekers,
 * zoekmachines en mail nodig hebben, met waar het kan een knop om het meteen recht te zetten.
 */
class GoLive extends Component
{
    use InteractsWithSites;

    public Site $site;

    public string $mailTo = '';

    /**
     * Onderdeel dat op bevestiging wacht; zie fixes().
     */
    public ?string $fixing = null;

    public function mount(AppSettings $settings): void
    {
        $this->mailTo = $settings->goLiveMailTo();
    }

    public function check(CommandRunner $runner): void
    {
        $this->attempt(fn () => $this->queueCommand($runner, WpOpenCommand::goLive($this->site)));
        unset($this->activeRun);
    }

    public function sendTestMail(CommandRunner $runner, AppSettings $settings): void
    {
        $this->mailTo = trim($this->mailTo);
        $this->validate(
            ['mailTo' => ['required', 'email:rfc', 'max:191', 'regex:/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/']],
            ['mailTo.regex' => __('Vul een geldig e-mailadres in.')],
            ['mailTo' => __('e-mailadres')],
        );

        $settings->update(['go_live_mail_to' => $this->mailTo]);

        $this->attempt(fn () => $this->queueCommand($runner, WpOpenCommand::goLive($this->site, mailTo: $this->mailTo)));
        unset($this->activeRun);
    }

    /**
     * Wat de checklist zelf kan rechtzetten; WP_DEBUG en adressen omzetten kan alleen met WP-CLI (SSH).
     *
     * @return array<string, array{button: string, title: string, body: string}>
     */
    public function fixes(): array
    {
        if ($this->site->isLaravel()) {
            return [];
        }

        $fixes = [
            'search' => [
                'button' => __('Aanzetten'),
                'title' => __('Zichtbaar maken voor zoekmachines'),
                'body' => __('Zet "Zoekmachines ontmoedigen" uit op live (Instellingen → Lezen), zodat Google de site mag indexeren. Daarna wordt de cache geleegd en alles opnieuw gecontroleerd.'),
            ],
            'debug' => [
                'button' => __('Uitzetten'),
                'title' => __('WP_DEBUG uitzetten'),
                'body' => __('Zet WP_DEBUG in de wp-config.php op live op false, zodat bezoekers geen foutmeldingen meer zien. Daarna wordt alles opnieuw gecontroleerd.'),
            ],
            'local-urls' => [
                'button' => __('Omzetten'),
                'title' => __('Lokale adressen omzetten'),
                'body' => __('Vervangt in de live database alle adressen van :local door :live (ook in pagina\'s van de paginabouwer). Eerst wordt een back-up van de live database gemaakt; daarna wordt de cache geleegd en alles opnieuw gecontroleerd.', [
                    'local' => $this->site->localHost(),
                    'live' => (string) parse_url($this->site->liveUrl(), PHP_URL_HOST),
                ]),
            ],
        ];

        return $this->site->mode->hasShell() ? $fixes : array_intersect_key($fixes, ['search' => true]);
    }

    /**
     * Alleen een knop als die het gemelde probleem ook echt oplost (robots.txt of debug.log kan hij niet aanpassen).
     *
     * @param  array{key: string, status: string, label: string, detail: string}  $check
     */
    public function fixFor(array $check): ?string
    {
        $applies = match ($check['key']) {
            'search' => Str::contains($check['detail'], 'Zoekmachines ontmoedigen'),
            'debug' => Str::contains($check['detail'], 'WP_DEBUG staat aan'),
            'local-urls' => Str::contains($check['detail'], ['.ddev.site', 'oude adres']),
            default => false,
        };

        return $applies && in_array($check['status'], ['fail', 'warn'], true) && array_key_exists($check['key'], $this->fixes()) ? $check['key'] : null;
    }

    public function confirmFix(string $key): void
    {
        if (! array_key_exists($key, $this->fixes())) {
            return;
        }

        $this->fixing = $key;
        $this->dispatch('open-modal', name: 'go-live-fix');
    }

    public function fix(CommandRunner $runner): void
    {
        if ($this->fixing === null || ! array_key_exists($this->fixing, $this->fixes())) {
            return;
        }

        $this->attempt(fn () => $this->queueCommand($runner, WpOpenCommand::goLive($this->site, fix: $this->fixing)));

        $this->fixing = null;
        $this->dispatch('close-modal', name: 'go-live-fix');
        unset($this->activeRun);
    }

    /**
     * Wat je zelf moet doen als de checklist het niet kan rechtzetten.
     *
     * @return array<string, string>
     */
    public function hints(): array
    {
        $host = (string) parse_url($this->site->liveUrl(), PHP_URL_HOST);
        $variant = Str::startsWith($host, 'www.') ? Str::after($host, 'www.') : 'www.'.$host;

        return [
            'www' => __('Stel bij je hosting een doorverwijzing in van :variant naar :host.', ['variant' => $variant, 'host' => $host]),
            'https' => __('Zet bij je hosting SSL en "HTTPS forceren" aan.'),
            'sitemap' => $this->site->isLaravel()
                ? __('Voeg een sitemap toe, bijvoorbeeld met spatie/laravel-sitemap.')
                : __('Zet de sitemap aan in je SEO-plugin, zoals Yoast of Rank Math.'),
            'debug' => $this->site->isLaravel()
                ? __('Zet APP_DEBUG=false en APP_ENV=production in de .env op live.')
                : __('Zet WP_DEBUG op false in de wp-config.php op live.'),
            'local-urls' => __('Vervang ze met een plugin als Better Search Replace, of verhuis opnieuw vanuit BD Deck.'),
            'search' => __('Pas robots.txt aan in de hoofdmap van de site of in je SEO-plugin.'),
        ];
    }

    #[On('sites-changed')]
    public function refreshSite(): void
    {
        $this->site->refresh();
        unset($this->activeRun, $this->recentMove);
    }

    /**
     * Wordt elke paar seconden aangeroepen zolang de checklist loopt.
     */
    public function poll(): void
    {
        $wasActive = $this->activeRun !== null;
        unset($this->activeRun);

        if ($wasActive && $this->activeRun === null) {
            $this->site->refresh();
        }
    }

    #[Computed]
    public function activeRun(): ?CommandRun
    {
        return $this->site->runs()->active()->where('action', WpOpenAction::GoLive)->latestFirst()->first();
    }

    /**
     * Net verhuisd of een nieuw domein: dan hoort de checklist bovenaan je aandacht.
     */
    #[Computed]
    public function recentMove(): ?CommandRun
    {
        return $this->site->runs()
            ->whereIn('action', [WpOpenAction::Migrate, WpOpenAction::ChangeDomain])
            ->where('status', RunStatus::Succeeded)
            ->where('finished_at', '>=', now()->subDays(7))
            ->latestFirst()
            ->first();
    }

    public function render(): View
    {
        return view('livewire.sites.go-live', [
            'fixConfirmation' => $this->fixing !== null ? $this->fixes()[$this->fixing] ?? null : null,
        ]);
    }
}
