<?php

namespace App\Livewire\Settings;

use App\Enums\OffsiteProvider;
use App\Enums\OffsiteStatus;
use App\Exceptions\OffsiteException;
use App\Jobs\SyncOffsiteBackups;
use App\Models\OffsiteBackup;
use App\Services\AppSettings;
use App\Services\Offsite\BackupCipher;
use App\Services\Offsite\OffsiteConfig;
use App\Services\Offsite\OffsiteSync;
use App\Services\Offsite\RetentionPolicy;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Geheimen (geheime sleutel, versleutelwachtwoord) gaan nooit terug naar de browser:
 * de velden blijven leeg en een leeg veld betekent "bewaarde waarde houden".
 */
class OffsiteBackups extends Component
{
    public bool $enabled = false;

    public string $provider = 'backblaze-b2';

    public string $endpoint = '';

    public string $region = '';

    public string $bucket = '';

    public string $accessKeyId = '';

    public string $secretAccessKey = '';

    public string $prefix = OffsiteConfig::DEFAULT_PREFIX;

    public string $passphrase = '';

    public string $passphrase_confirmation = '';

    public int|string $keepLast = 3;

    public int|string $keepDaily = 7;

    public int|string $keepWeekly = 4;

    public int|string $keepMonthly = 6;

    public bool $cleanupLocal = false;

    public int|string $cleanupLocalDays = 30;

    /**
     * @var array{ok: bool, message: string}|null
     */
    public ?array $connectionResult = null;

    public function mount(AppSettings $settings): void
    {
        $config = $settings->offsite();

        $this->fill([
            'enabled' => $config->enabled,
            'provider' => $config->provider->value,
            'endpoint' => $config->endpoint,
            'region' => $config->region,
            'bucket' => $config->bucket,
            'accessKeyId' => $config->accessKeyId,
            'prefix' => $config->prefix,
            'keepLast' => $config->retention->keepLast,
            'keepDaily' => $config->retention->daily,
            'keepWeekly' => $config->retention->weekly,
            'keepMonthly' => $config->retention->monthly,
            'cleanupLocal' => $config->cleanupLocal,
            'cleanupLocalDays' => $config->cleanupLocalDays,
        ]);
    }

    /**
     * Een andere dienst heeft een eigen regio en endpoint; vooringevuld zodat alleen bucket en sleutels overblijven.
     */
    public function updatedProvider(string $value): void
    {
        $provider = OffsiteProvider::tryFrom($value) ?? OffsiteProvider::BackblazeB2;
        $this->region = $provider->defaultRegion();
        $this->endpoint = $provider->endpointFor($this->region);
        $this->connectionResult = null;
    }

    /**
     * Het endpoint volgt de regio, zolang de gebruiker het niet zelf heeft aangepast.
     */
    public function updatingRegion(string $value): void
    {
        $provider = $this->selectedProvider();

        if ($this->endpoint === $provider->endpointFor($this->region)) {
            $this->endpoint = $provider->endpointFor(trim($value));
        }
    }

    public function save(AppSettings $settings, BackupCipher $cipher): void
    {
        $saved = $settings->offsite();
        $this->validate($this->rules($saved), attributes: $this->attributeNames());

        $values = [
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'endpoint' => rtrim(trim($this->endpoint), '/'),
            'region' => trim($this->region),
            'bucket' => trim($this->bucket),
            'access_key_id' => trim($this->accessKeyId),
            'prefix' => trim($this->prefix, '/ '),
            'keep_last' => (int) $this->keepLast,
            'keep_daily' => (int) $this->keepDaily,
            'keep_weekly' => (int) $this->keepWeekly,
            'keep_monthly' => (int) $this->keepMonthly,
            'cleanup_local' => $this->cleanupLocal,
            'cleanup_local_days' => (int) $this->cleanupLocalDays,
        ];

        if ($this->secretAccessKey !== '') {
            $values['secret_access_key'] = OffsiteConfig::seal(trim($this->secretAccessKey));
        }

        $passphraseChanged = false;

        if ($this->passphrase !== '') {
            $keyId = $cipher->keyId($this->passphrase);
            $passphraseChanged = $saved->keyId !== null && $saved->keyId !== $keyId;
            $values['passphrase'] = OffsiteConfig::seal($this->passphrase);
            $values['key_id'] = $keyId;
        }

        $settings->updateOffsite($values);
        $this->reset('secretAccessKey', 'passphrase', 'passphrase_confirmation');

        $this->dispatch('toast', title: __('Cloud-back-ups opgeslagen'), tone: 'success');

        if ($passphraseChanged) {
            $this->dispatch('toast', title: __('Nieuw versleutelwachtwoord'), message: __('Back-ups die al in de cloud staan houden het oude wachtwoord. Bewaar dat ook, anders kun je ze niet meer terugzetten.'), tone: 'warning');
        }
    }

    public function testConnection(AppSettings $settings, OffsiteSync $sync): void
    {
        $saved = $settings->offsite();
        $rules = array_intersect_key($this->rules($saved, requireAll: true), array_flip(['provider', 'endpoint', 'region', 'bucket', 'accessKeyId', 'secretAccessKey', 'prefix']));
        $this->validate($rules, attributes: $this->attributeNames());

        $config = new OffsiteConfig(
            enabled: true,
            provider: $this->selectedProvider(),
            endpoint: rtrim(trim($this->endpoint), '/'),
            region: trim($this->region),
            bucket: trim($this->bucket),
            accessKeyId: trim($this->accessKeyId),
            secretAccessKey: $this->secretAccessKey !== '' ? trim($this->secretAccessKey) : $saved->secretAccessKey,
            prefix: trim($this->prefix, '/ ') ?: OffsiteConfig::DEFAULT_PREFIX,
            passphrase: $this->passphrase !== '' ? $this->passphrase : $saved->passphrase,
        );

        try {
            $sync->testConnection($config);
        } catch (OffsiteException $exception) {
            $this->connectionResult = ['ok' => false, 'message' => $exception->getMessage()];

            return;
        }

        $this->connectionResult = ['ok' => true, 'message' => __('Schrijven, lezen en verwijderen in :bucket lukt.', ['bucket' => $config->bucket])];
    }

    public function syncNow(AppSettings $settings): void
    {
        if (! $settings->offsite()->isActive()) {
            $this->dispatch('toast', title: __('Nog niet klaar om te synchroniseren'), message: __('Zet cloud-back-ups aan, vul alles in en sla op.'), tone: 'danger');

            return;
        }

        SyncOffsiteBackups::dispatch();

        $this->dispatch('toast', title: __('Synchroniseren gestart'), message: __('Dit loopt op de achtergrond; je kunt gewoon verder werken.'), tone: 'success');
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(OffsiteConfig $saved, bool $requireAll = false): array
    {
        $required = $requireAll || $this->enabled;

        return [
            'enabled' => ['boolean'],
            'provider' => ['required', Rule::enum(OffsiteProvider::class)],
            'endpoint' => [Rule::requiredIf($required), 'nullable', 'url:http,https', 'max:255'],
            'region' => [Rule::requiredIf($required), 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'bucket' => [Rule::requiredIf($required), 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{1,61}[A-Za-z0-9]$/'],
            'accessKeyId' => [Rule::requiredIf($required), 'nullable', 'string', 'max:128', 'regex:/^\S+$/'],
            'secretAccessKey' => [Rule::requiredIf($required && $saved->secretAccessKey === null), 'nullable', 'string', 'max:256'],
            'prefix' => ['required', 'string', 'max:100', 'regex:/^\/?[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*\/?$/', 'not_regex:/(^|\/)\.{1,2}(\/|$)/'],
            'passphrase' => [Rule::requiredIf($this->enabled && $saved->passphrase === null), 'nullable', 'string', 'min:12', 'max:200', 'confirmed'],
            'keepLast' => ['required', 'integer', 'between:1,100'],
            'keepDaily' => ['required', 'integer', 'between:0,365'],
            'keepWeekly' => ['required', 'integer', 'between:0,104'],
            'keepMonthly' => ['required', 'integer', 'between:0,120'],
            'cleanupLocal' => ['boolean'],
            'cleanupLocalDays' => ['required', 'integer', 'between:1,365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attributeNames(): array
    {
        return [
            'endpoint' => __('endpoint'),
            'region' => __('regio'),
            'bucket' => __('bucket'),
            'accessKeyId' => __('access key ID'),
            'secretAccessKey' => __('geheime sleutel'),
            'prefix' => __('map'),
            'passphrase' => __('versleutelwachtwoord'),
            'keepLast' => __('laatste back-ups'),
            'keepDaily' => __('dagen'),
            'keepWeekly' => __('weken'),
            'keepMonthly' => __('maanden'),
            'cleanupLocalDays' => __('dagen'),
        ];
    }

    private function selectedProvider(): OffsiteProvider
    {
        return OffsiteProvider::tryFrom($this->provider) ?? OffsiteProvider::BackblazeB2;
    }

    private function retentionPreview(): RetentionPolicy
    {
        $number = fn (int|string $value, int $min, int $max): int => min($max, max($min, (int) $value));

        return new RetentionPolicy(
            keepLast: $number($this->keepLast, 1, 100),
            daily: $number($this->keepDaily, 0, 365),
            weekly: $number($this->keepWeekly, 0, 104),
            monthly: $number($this->keepMonthly, 0, 120),
        );
    }

    public function render(AppSettings $settings): View
    {
        $config = $settings->offsite();
        $inCloud = OffsiteBackup::query()->inCloud();

        return view('livewire.settings.offsite-backups', [
            'providers' => OffsiteProvider::cases(),
            'selectedProvider' => $this->selectedProvider(),
            'hasSecret' => $config->secretAccessKey !== null,
            'hasPassphrase' => $config->passphrase !== null,
            'retentionSentence' => $this->retentionPreview()->describe(),
            'lastSyncAt' => $config->lastSyncAt,
            'cloudCount' => (clone $inCloud)->count(),
            'cloudSize' => (int) (clone $inCloud)->sum('size'),
            'oldKeyCount' => $config->keyId === null ? 0 : (clone $inCloud)->whereNotNull('key_id')->where('key_id', '!=', $config->keyId)->count(),
            'busyCount' => OffsiteBackup::query()->whereIn('status', [OffsiteStatus::Pending, OffsiteStatus::Uploading])->count(),
            'failed' => OffsiteBackup::query()->where('status', OffsiteStatus::Failed)->latest('updated_at')->orderByDesc('id')->get(['site_name', 'backup_id', 'error']),
        ]);
    }
}
