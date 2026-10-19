<?php

namespace App\Services\WpOpen;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Enums\SiteType;
use App\Exceptions\WpOpenException;
use App\Models\Site;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Sitelijst delen met collega's. De verbindingsgegevens komen uit en gaan naar wpopen;
 * notities zijn app-gegevens en worden hier toegevoegd of overgenomen.
 */
final class SiteListExchange
{
    public const string FORMAT = 'bd-deck-sites';

    public const int VERSION = 1;

    private const int MAX_BYTES = 5 * 1024 * 1024;

    /**
     * SSH-opties die naar een bestand op de eigen computer wijzen (gelijk aan `personal()` in wpopen).
     */
    private const string PERSONAL_OPTION_KEYS = '/^(identityfile|certificatefile|userknownhostsfile|globalknownhostsfile|controlpath|identityagent|pkcs11provider|securitykeyprovider|xauthlocation|revokedhostkeys)=/i';

    public function __construct(
        private readonly WpOpen $wpopen,
        private readonly SiteRegistry $registry,
    ) {}

    /**
     * @return array{json: string, count: int}
     */
    public function export(bool $withNotes): array
    {
        $data = $this->wpopen->json(['export-sites'], 30);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['sites'] ?? null)) {
            throw new WpOpenException(__('wpopen gaf geen geldige sitelijst terug.'));
        }

        if ($withNotes) {
            $notes = Site::query()->whereNotNull('notes')->where('notes', '!=', '')->pluck('notes', 'name');

            $data['sites'] = array_map(
                fn (array $site): array => filled($notes[$site['name']] ?? null) ? [...$site, 'notes' => $notes[$site['name']]] : $site,
                $data['sites'],
            );
        }

        return [
            'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
            'count' => count($data['sites']),
        ];
    }

    /**
     * Wat er per site zou gebeuren. wpopen controleert bij het importeren alles opnieuw; dit is voor de voorvertoning.
     *
     * @return list<array{name: string, status: string, reason: ?string, target: ?string, notes: ?string}>
     */
    public function preview(string $path): array
    {
        $existing = Site::query()->get(['id', 'name', 'snapshot'])->keyBy('name');
        $seen = [];
        $rows = [];

        foreach ($this->read($path) as $entry) {
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? mb_substr($entry['name'], 0, 60) : '';
            $reason = $this->problem($entry);

            if ($reason === null && isset($seen[$entry['name']])) {
                $reason = __('staat dubbel in het bestand');
            }

            if ($reason !== null) {
                $rows[] = ['name' => $name, 'status' => 'invalid', 'reason' => $reason, 'target' => null, 'notes' => null];

                continue;
            }

            $seen[$name] = true;
            [$status, $reason] = $this->compare($entry, $existing->get($name));
            $notes = is_string($entry['notes'] ?? null) && trim($entry['notes']) !== '' ? trim($entry['notes']) : null;

            $rows[] = ['name' => $name, 'status' => $status, 'reason' => $reason, 'target' => $entry['target'], 'notes' => $notes];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, string>  $notes  naam → notitie uit het bestand
     * @return array{added: list<string>, updated: list<string>, skipped: list<string>, invalid: list<array{name: string, reason: string}>}
     */
    public function import(string $path, array $names, bool $update, array $notes): array
    {
        $result = $this->wpopen->json(WpOpenCommand::importSites($path, $names, $update)->arguments, 60);

        if (! is_array($result)) {
            throw new WpOpenException(__('wpopen gaf geen geldige JSON terug.'));
        }

        $summary = [
            'added' => array_values(array_filter((array) ($result['added'] ?? []), 'is_string')),
            'updated' => array_values(array_filter((array) ($result['updated'] ?? []), 'is_string')),
            'skipped' => array_values(array_filter((array) ($result['skipped'] ?? []), 'is_string')),
            'invalid' => array_values(array_filter((array) ($result['invalid'] ?? []), fn (mixed $item): bool => is_array($item) && is_string($item['name'] ?? null) && is_string($item['reason'] ?? null))),
        ];

        $this->registry->sync();

        // een eigen notitie gaat altijd voor; die van de collega vult alleen lege notities
        Site::query()
            ->whereIn('name', [...$summary['added'], ...$summary['updated']])
            ->where(fn ($query) => $query->whereNull('notes')->orWhere('notes', ''))
            ->get(['id', 'name', 'notes'])
            ->each(function (Site $site) use ($notes): void {
                if (filled($notes[$site->name] ?? null)) {
                    $site->update(['notes' => $notes[$site->name]]);
                }
            });

        return $summary;
    }

    /**
     * SSH-opties zonder persoonlijke paden, zoals wpopen ze exporteert.
     */
    public static function sharedOptions(string $options): string
    {
        return collect(self::optionPairs($options))
            ->reject(fn (array $pair): bool => self::isPersonal($pair))
            ->map(fn (array $pair): string => implode(' ', $pair))
            ->implode(' ');
    }

    /**
     * @return list<mixed>
     */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(__('Het bestand kon niet gelezen worden.'));
        }

        if ((int) filesize($path) > self::MAX_BYTES) {
            throw new InvalidArgumentException(__('Het bestand is te groot voor een sitelijst.'));
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new InvalidArgumentException(__('Dit is geen geldig JSON-bestand.'));
        }

        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException(__('Dit is geen sitelijst van BD Deck.'));
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException(__('Deze sitelijst komt uit een nieuwere versie van BD Deck; werk BD Deck eerst bij.'));
        }

        if (! is_array($data['sites'] ?? null) || ! array_is_list($data['sites'])) {
            throw new InvalidArgumentException(__('De sitelijst bevat geen sites.'));
        }

        return $data['sites'];
    }

    /**
     * Dezelfde regels als `wpopen import-sites`; de eerste fout is de reden.
     */
    private function problem(mixed $entry): ?string
    {
        if (! is_array($entry)) {
            return __('geen geldige site');
        }

        $text = ['nullable', 'string', 'not_regex:/[|\x00-\x1f\x7f]/'];

        $validator = Validator::make($entry, [
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'target' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}@[A-Za-z0-9.-]{1,253}$/'],
            'ssh_options' => [...$text, 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    WpOpenCommand::splitOptions((string) $value);
                } catch (InvalidArgumentException) {
                    $fail(__('SSH-opties hebben een ongeldig formaat'));

                    return;
                }

                if (self::sharedOptions((string) $value) !== trim((string) preg_replace('/ +/', ' ', (string) $value))) {
                    $fail(__('SSH-opties bevatten een persoonlijk pad (zoals -i)'));
                }
            }],
            'remote' => ['required', ...$text, 'max:500'],
            'mode' => ['required', Rule::enum(SiteMode::class)->only(SiteMode::remote())],
            'provider' => ['required', Rule::enum(SiteProvider::class)],
            'live_url' => [...$text, 'max:255', 'regex:#^https?://[A-Za-z0-9.-]+(/[^|\s]*)?$#'],
            'type' => ['nullable', 'string', Rule::enum(SiteType::class)],
            'public_dir' => [...$text, 'max:500', Rule::prohibitedIf(($entry['type'] ?? null) !== SiteType::Laravel->value)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'name' => __('naam mag alleen letters, cijfers, punt, - en _ bevatten'),
            'target' => __('server moet de vorm gebruiker@host hebben'),
            'ssh_options.max' => __('SSH-opties hebben een ongeldig formaat'),
            'ssh_options.string' => __('SSH-opties hebben een ongeldig formaat'),
            'ssh_options.not_regex' => __('SSH-opties hebben een ongeldig formaat'),
            'remote' => __('pad op de server ontbreekt of is ongeldig'),
            'mode' => __('modus moet ssh of sftp zijn'),
            'provider' => __('provider moet wpmudev, hostinger of other zijn'),
            'live_url' => __('ongeldig live-adres'),
            'type' => __('type moet wordpress of laravel zijn'),
            'public_dir' => __('een losse public-map kan alleen bij Laravel-sites'),
            'notes' => __('notitie is te lang of geen tekst'),
        ]);

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: string, 1: ?string}
     */
    private function compare(array $entry, ?Site $site): array
    {
        if ($site === null) {
            return ['new', null];
        }

        if ($site->isLocalOnly()) {
            return ['blocked', __('Hier is het een lokale site zonder server')];
        }

        $type = ($entry['type'] ?? null) ?: SiteType::WordPress->value;

        if ($site->type->value !== $type) {
            return ['blocked', __('Hier is het een :type-site', ['type' => $site->type->label()])];
        }

        $mine = [
            $site->data('target'), self::sharedOptions((string) $site->data('opts')), $site->data('remote'),
            $site->mode->value, $site->providerType->value, (string) $site->data('live_url'), (string) $site->data('public_dir'),
        ];
        $theirs = [
            $entry['target'], (string) ($entry['ssh_options'] ?? ''), $entry['remote'],
            $entry['mode'], $entry['provider'], (string) ($entry['live_url'] ?? ''), (string) ($entry['public_dir'] ?? ''),
        ];

        return $mine === $theirs ? ['same', null] : ['different', null];
    }

    /**
     * "-p 65002 -i /pad -A" → [["-p", "65002"], ["-i", "/pad"], ["-A"]]
     *
     * @return list<list<string>>
     */
    private static function optionPairs(string $options): array
    {
        $pairs = [];

        foreach (preg_split('/\s+/', trim($options), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($pairs === [] || preg_match('/^-[A-Za-z]/', $token)) {
                $pairs[] = [$token];
            } else {
                $pairs[array_key_last($pairs)][] = $token;
            }
        }

        return $pairs;
    }

    /**
     * @param  list<string>  $pair
     */
    private static function isPersonal(array $pair): bool
    {
        $flag = substr($pair[0], 0, 2);
        $value = implode(' ', array_slice($pair, 1)) ?: substr($pair[0], 2);

        if (in_array($flag, ['-i', '-F', '-E', '-S'], true)) {
            return true;
        }

        if ($flag === '-o' && preg_match(self::PERSONAL_OPTION_KEYS, $value)) {
            return true;
        }

        // vangnet: alles wat naar een bestand op deze computer wijst
        return str_starts_with($value, '/') || str_starts_with($value, '~') || str_contains($value, '/home/');
    }
}
