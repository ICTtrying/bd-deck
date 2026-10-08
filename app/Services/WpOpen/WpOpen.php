<?php

namespace App\Services\WpOpen;

use App\Exceptions\WpOpenException;
use App\Services\AppSettings;
use App\Support\HostEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use JsonException;

/**
 * Enige toegang tot het wpopen-script: alle logica blijft daar, de app roept het alleen aan.
 */
final class WpOpen
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function command(array $arguments, ?string $script = null): array
    {
        // setsid: eigen procesgroep, zodat stoppen ook ssh/rsync/lftp onder het script meeneemt
        return ['setsid', 'bash', $script ?? $this->settings->scriptPath(), ...$arguments];
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string|false>
     */
    public function environment(array $extra = []): array
    {
        $home = $this->settings->homeDirectory();
        $host = HostEnvironment::overrides();
        $inheritedPath = is_string($host['PATH'] ?? null) ? $host['PATH'] : (string) getenv('PATH');
        $path = array_unique(array_filter([
            $home.'/.local/bin', '/usr/local/bin', '/usr/bin', '/bin', ...explode(':', $inheritedPath),
        ]));

        return [
            ...$host,
            'HOME' => $home,
            'PATH' => implode(':', $path),
            'WP_SITES_DIR' => $this->settings->sitesDirectory(),
            'WPO_SSH_KEY' => $this->settings->sshKeyPath(),
            'WPO_NONINTERACTIVE' => '1',
            'LC_ALL' => 'C.UTF-8',
            'TERM' => 'dumb',
            'NO_COLOR' => '1',
            ...$extra,
        ];
    }

    /**
     * @param  array<string, string>  $extra
     */
    public function pending(array $extra = []): PendingProcess
    {
        return Process::env($this->environment($extra))->path($this->settings->homeDirectory());
    }

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments, int $timeout = 120): ProcessResult
    {
        return $this->pending()->timeout($timeout)->run($this->command($arguments));
    }

    /**
     * @param  list<string>  $arguments
     */
    public function runOrFail(array $arguments, int $timeout = 120): string
    {
        $result = $this->run($arguments, $timeout);

        if (! $result->successful()) {
            throw WpOpenException::fromOutput($result->output()."\n".$result->errorOutput(), (int) $result->exitCode());
        }

        return $result->output();
    }

    /**
     * @param  list<string>  $arguments
     */
    public function json(array $arguments, int $timeout = 120): mixed
    {
        return self::decode($this->runOrFail($arguments, $timeout));
    }

    /**
     * Neemt de laatste JSON-regel: plugins en WP-CLI kunnen er waarschuwingen voor zetten.
     */
    public static function decode(string $output): mixed
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output))));

        foreach ($lines as $line) {
            if (str_starts_with($line, '{') || str_starts_with($line, '[') || $line === 'null') {
                try {
                    return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }
            }
        }

        throw new WpOpenException(__('wpopen gaf geen geldige JSON terug.'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sites(): array
    {
        return (array) $this->json(['list', '--json'], 60);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function site(string $name): ?array
    {
        $site = $this->json(['info', $name, '--json'], 60);

        return is_array($site) ? $site : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function backups(string $name): array
    {
        return (array) $this->json(['backups', $name, '--json'], 30);
    }

    public function loginUrl(string $name, bool $live, ?string $user = null): string
    {
        $arguments = ['login', $name];

        if ($live) {
            $arguments[] = '--live';
        }

        if ($user !== null && $user !== '') {
            array_push($arguments, '--user', $user);
        }

        $url = trim((string) collect(explode("\n", $this->runOrFail($arguments, 60)))->filter()->last());

        if (! str_starts_with($url, 'https://')) {
            throw new WpOpenException(__('Geen geldige inloglink ontvangen.'));
        }

        return $url;
    }

    /**
     * Stopt een lopend script inclusief alles wat het gestart heeft.
     */
    public function terminate(int $pid): void
    {
        Process::run(['kill', '-TERM', '--', '-'.$pid]);
    }
}
