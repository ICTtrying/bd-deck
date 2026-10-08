<?php

namespace App\Livewire\Sites;

use App\Enums\WpOpenAction;
use App\Livewire\Concerns\InteractsWithSites;
use App\Models\CommandRun;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class Console extends Component
{
    use InteractsWithSites;

    public Site $site;

    public string $command = '';

    public bool $live = false;

    public function mount(): void
    {
        $this->live = ! $this->site->isBuilt() && $this->site->mode->hasShell();
    }

    /**
     * @return array<string, string>
     */
    public function suggestions(): array
    {
        return [
            'plugin list' => __('Plugins'),
            'theme list' => __('Thema\'s'),
            'core version --extra' => __('WordPress-versie'),
            'user list --role=administrator' => __('Beheerders'),
            'option get siteurl' => __('Site-URL'),
            'cron event list' => __('Geplande taken'),
            'rewrite flush' => __('Permalinks vernieuwen'),
            'transient delete --expired' => __('Verlopen transients'),
        ];
    }

    public function execute(CommandRunner $runner): void
    {
        $this->validate(['command' => ['required', 'string', 'max:1000']]);

        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::wpCli($this->site, self::tokenize($this->command), $this->live));
            $this->reset('command');
            unset($this->history);
        });
    }

    public function use(string $suggestion): void
    {
        $this->command = $suggestion;
    }

    /**
     * Splitst zoals een shell (aanhalingstekens houden woorden bij elkaar), maar voert niets uit:
     * de argumenten gaan los naar WP-CLI.
     *
     * @return list<string>
     */
    public static function tokenize(string $input): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/', $input, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): string => match (true) {
            ($match[3] ?? '') !== '' => $match[3],
            ($match[2] ?? '') !== '' => $match[2],
            default => stripcslashes($match[1] ?? ''),
        }, $matches);
    }

    /**
     * @return Collection<int, CommandRun>
     */
    #[Computed]
    public function history(): Collection
    {
        return $this->site->runs()->where('action', WpOpenAction::WpCli)->latestFirst()->limit(5)->get();
    }

    #[On('run-started')]
    public function refreshHistory(): void
    {
        unset($this->history);
    }

    public function render(): View
    {
        return view('livewire.sites.console');
    }
}
