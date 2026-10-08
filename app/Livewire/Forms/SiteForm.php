<?php

namespace App\Livewire\Forms;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Models\Site;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Form;

class SiteForm extends Form
{
    public ?Site $site = null;

    public string $name = '';

    public string $user = '';

    public string $host = '';

    public int $port = 22;

    public string $extraOptions = '';

    /**
     * Automatisch: wpopen zoekt zelf naar wp-content op de server.
     */
    public bool $discover = true;

    public string $remotePath = '';

    public string $mode = 'ssh';

    public string $provider = 'wpmudev';

    public string $liveUrl = '';

    public string $liveLoginUser = '';

    public string $password = '';

    public bool $savePassword = true;

    public function setSite(Site $site): void
    {
        $this->site = $site;
        $this->name = $site->name;
        [$this->user, $this->host] = array_pad(explode('@', (string) $site->data('target'), 2), 2, '');
        $this->port = (int) $site->data('port', 22);
        // poort krijgt een eigen veld; andere SSH-opties blijven ongewijzigd bewaard
        $this->extraOptions = trim((string) preg_replace('/-p\s+\d+/', '', (string) $site->data('opts')));
        $this->discover = false;
        $this->remotePath = (string) $site->data('remote');
        $this->mode = $site->mode->value;
        $this->provider = $site->providerType->value;
        $this->liveUrl = (string) $site->data('live_url');
        $this->liveLoginUser = (string) $site->live_login_user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('sites', 'name')->ignore($this->site?->id)],
            'user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'extraOptions' => ['nullable', 'string', 'max:200'],
            'discover' => ['boolean'],
            'remotePath' => [Rule::requiredIf(! $this->discover), 'nullable', 'string', 'max:500', 'not_regex:/[|\n\r]/'],
            'mode' => ['required', Rule::enum(SiteMode::class)],
            'provider' => ['required', Rule::enum(SiteProvider::class)],
            'liveUrl' => ['nullable', 'url:https', 'max:255'],
            'liveLoginUser' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.regex' => __('Gebruik alleen letters, cijfers, punt, - en _.'),
            'user.regex' => __('Ongeldige gebruikersnaam.'),
            'host.regex' => __('Vul alleen de hostnaam in, zonder https:// of pad.'),
            'remotePath.required' => __('Vul het pad naar wp-content in, of laat BD Deck zoeken.'),
            'liveUrl.url' => __('Gebruik een volledig https-adres, bijvoorbeeld https://klant.nl'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => __('naam'),
            'user' => __('gebruiker'),
            'host' => __('server'),
            'port' => __('poort'),
            'remotePath' => __('pad'),
            'liveUrl' => __('live-adres'),
        ];
    }

    public function target(): string
    {
        return $this->user.'@'.$this->host;
    }

    public function sshOptions(): string
    {
        return trim(($this->port !== 22 ? '-p '.$this->port.' ' : '').$this->extraOptions);
    }

    /**
     * Plakt iemand "sftp://user@host:65002" of "ssh -p 65002 user@host" in het serverveld, dan vullen we alles in.
     */
    public function fillFromConnectionString(string $value): void
    {
        $value = trim($value);

        if (preg_match('#^(?:s?ftp://)?([^@\s/]+)@([^:/\s]+)(?::(\d+))?/?$#', $value, $m)) {
            [$this->user, $this->host] = [$m[1], $m[2]];
            $this->port = isset($m[3]) ? (int) $m[3] : $this->port;
        } elseif (preg_match('/^ssh\s+(?:-p\s+(\d+)\s+)?([^@\s]+)@(\S+)$/', $value, $m)) {
            [$this->user, $this->host] = [$m[2], $m[3]];
            $this->port = $m[1] !== '' ? (int) $m[1] : 22;
        } else {
            return;
        }

        if ($this->name === '') {
            $this->name = Str::of($this->host)->before('.')->replaceEnd('-serverwpmu', '')->toString();
        }

        $this->provider = match (true) {
            str_contains($this->host, 'tempurl.host') || str_contains($this->host, 'wpmudev') => SiteProvider::Wpmudev->value,
            str_contains($this->host, 'hostinger') || str_contains($this->host, 'hstgr') || $this->port === 65002 => SiteProvider::Hostinger->value,
            default => $this->provider,
        };
    }
}
