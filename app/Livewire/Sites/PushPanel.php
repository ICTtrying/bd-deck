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
        $this->reset('files', 'deleted', 'message', 'database', 'uploads', 'force', 'confirmText');
        $this->flushCache = true;
        $this->loaded = false;
        $this->hasUncommittedChanges = (int) $this->site->data('git.dirty', 0) > 0;
        $this->dispatch('open-modal', 'push');

        $this->attempt(function () use ($wpopen): void {
            $this->parsePreview($wpopen->runOrFail(['push', $this->site->name, '-n'], 60));
            $this->loaded = true;
        });
    }

    public function push(CommandRunner $runner): void
    {
        $this->validate([
            'message' => [Rule::requiredIf($this->hasUncommittedChanges), 'nullable', 'string', 'max:200'],
            'confirmText' => [Rule::requiredIf($this->database), 'nullable', 'in:'.$this->site->name],
        ], [
            'message.required' => __('Beschrijf kort wat je hebt aangepast; dit wordt het commitbericht.'),
            'confirmText.required' => __('Typ de naam van de site om de database-push te bevestigen.'),
            'confirmText.in' => __('Typ de naam van de site om de database-push te bevestigen.'),
        ]);

        if ($this->files === [] && ! $this->database && ! $this->uploads) {
            $this->addError('message', __('Er staat niets klaar om live te zetten.'));

            return;
        }

        $this->attempt(function () use ($runner): void {
            $this->queueCommand($runner, WpOpenCommand::push(
                $this->site,
                message: $this->hasUncommittedChanges ? $this->message : null,
                database: $this->database,
                uploads: $this->uploads,
                force: $this->force,
                flushCache: $this->flushCache,
            ));

            $this->dispatch('close-modal', 'push');
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
    }

    public function render(): View
    {
        return view('livewire.sites.push-panel');
    }
}
