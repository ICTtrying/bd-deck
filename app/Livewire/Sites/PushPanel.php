<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\InteractsWithSites;
use App\Models\Site;
use App\Services\WpOpen\CommandRunner;
use App\Services\WpOpen\WpOpen;
use App\Services\WpOpen\WpOpenCommand;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class PushPanel extends Component
{
    use InteractsWithSites;

    public Site $site;

    /**
     * @var list<string>
     */
    public array $files = [];

    /**
     * @var list<string>
     */
    public array $deleted = [];

    /**
     * Bestanden die op live zijn aangepast sinds de laatste sync (gezien in de proefrun).
     *
     * @var list<string>
     */
    public array $changedOnLive = [];

    /**
     * Wat het script na het uploaden nog doet, zoals composer install of migraties.
     *
     * @var list<string>
     */
    public array $steps = [];

    public bool $loaded = false;

    public bool $hasUncommittedChanges = false;

    public string $message = '';

    public bool $database = false;

    public bool $uploads = false;

    public bool $flushCache = true;

    public bool $force = false;

    public string $confirmText = '';

    #[On('open-push')]
    public function open(WpOpen $wpopen): void
    {
        $this->resetValidation();
        $this->reset('files', 'deleted', 'changedOnLive', 'steps', 'message', 'database', 'uploads', 'force', 'confirmText');
        $this->flushCache = true;
        $this->loaded = false;
        $this->hasUncommittedChanges = (int) $this->site->data('git.dirty', 0) > 0;
        $this->dispatch('open-modal', name: 'push');

        $this->attempt(function () use ($wpopen): void {
            $this->parsePreview($wpopen->runOrFail(['push', $this->site->name, '-n'], 90));
            $this->message = $this->hasUncommittedChanges ? $this->suggestedMessage() : '';
            $this->loaded = true;
        });
    }

    /**
     * Live intussen aangepast en lokaal niets open: eerst live binnenhalen is veiliger dan overschrijven.
     */
    public function pullFirst(CommandRunner $runner): void
    {
        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::pullCode($this->site));
            $this->dispatch('close-modal', name: 'push');
        });
    }

    public function push(CommandRunner $runner): void
    {
        $this->validate([
            'message' => ['nullable', 'string', 'max:200'],
            'confirmText' => [Rule::requiredIf($this->database), 'nullable', 'in:'.$this->site->name],
        ], [
            'confirmText.required' => __('Typ de naam van de site om de database-push te bevestigen.'),
            'confirmText.in' => __('Typ de naam van de site om de database-push te bevestigen.'),
        ]);

        if ($this->files === [] && ! $this->database && ! $this->uploads) {
            $this->addError('message', __('Er staat niets klaar om live te zetten.'));

            return;
        }

        if ($this->changedOnLive !== [] && ! $this->force) {
            $this->addError('force', __('Kies eerst wat er met de wijzigingen op live moet gebeuren.'));

            return;
        }

        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::push(
                $this->site,
                message: $this->hasUncommittedChanges ? (trim($this->message) ?: $this->suggestedMessage()) : null,
                database: $this->database,
                uploads: $this->uploads,
                force: $this->force,
                flushCache: $this->flushCache,
            ));

            $this->dispatch('close-modal', name: 'push');
        });
    }

    private function parsePreview(string $output): void
    {
        $lines = collect(explode("\n", $output));

        $this->files = $lines
            ->filter(fn (string $line): bool => str_starts_with($line, '  ↑ '))
            ->map(fn (string $line): string => trim(Str::after($line, '↑')))
            ->values()
            ->all();

        $this->deleted = $lines
            ->skipUntil(fn (string $line): bool => str_contains($line, 'blijft op live staan'))
            ->skip(1)
            ->takeWhile(fn (string $line): bool => str_starts_with($line, '    '))
            ->map(fn (string $line): string => trim($line))
            ->values()
            ->all();

        $this->changedOnLive = $lines
            ->skipUntil(fn (string $line): bool => str_starts_with($line, 'Op live aangepast sinds'))
            ->skip(1)
            ->takeWhile(fn (string $line): bool => str_starts_with($line, '    '))
            ->map(fn (string $line): string => trim($line))
            ->values()
            ->all();

        $this->steps = $lines
            ->filter(fn (string $line): bool => str_starts_with($line, '· ') && ! str_contains($line, 'blijft op live staan'))
            ->map(fn (string $line): string => trim(Str::after($line, '· ')))
            ->values()
            ->all();
    }

    /**
     * Voorstel voor het commitbericht, zodat live zetten één klik blijft; je kunt het altijd aanpassen.
     */
    private function suggestedMessage(): string
    {
        $names = collect($this->files)->map(fn (string $file): string => basename($file))->unique()->values();

        return match (true) {
            $names->isEmpty() => __('Wijzigingen'),
            $names->count() <= 3 => __('Aangepast: :files', ['files' => $names->implode(', ')]),
            default => __('Aangepast: :files en :count meer', ['files' => $names->take(2)->implode(', '), 'count' => $names->count() - 2]),
        };
    }

    public function render(): View
    {
        return view('livewire.sites.push-panel');
    }
}
