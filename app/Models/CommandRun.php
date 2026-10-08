<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use Database\Factories\CommandRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable([
    'site_id', 'site_name', 'action', 'label', 'arguments', 'status', 'output', 'exit_code', 'pid', 'started_at', 'finished_at',
])]
class CommandRun extends Model
{
    /** @use HasFactory<CommandRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => WpOpenAction::class,
            'status' => RunStatus::class,
            'arguments' => 'array',
            'exit_code' => 'integer',
            'pid' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', RunStatus::active());
    }

    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function appendOutput(string $chunk): void
    {
        // kleurcodes en voortgangs-\r van ddev/lftp maken het logboek onleesbaar
        $clean = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $chunk) ?? $chunk;
        $clean = str_replace("\r\n", "\n", $clean);
        $clean = preg_replace('/\r(?!\n)/', "\n", $clean) ?? $clean;

        $this->output = ($this->output ?? '').$clean;
        $this->saveQuietly();
    }

    public function markRunning(int $pid): void
    {
        $this->update(['status' => RunStatus::Running, 'pid' => $pid, 'started_at' => now()]);
    }

    public function finish(int $exitCode, bool $cancelled = false): void
    {
        $this->update([
            'status' => match (true) {
                $cancelled => RunStatus::Cancelled,
                $exitCode === 0 => RunStatus::Succeeded,
                default => RunStatus::Failed,
            },
            'exit_code' => $exitCode,
            'finished_at' => now(),
        ]);
    }

    public function commandLine(): string
    {
        return 'wpopen '.collect($this->arguments)
            ->map(fn (string $argument): string => preg_match('/^[\w.\/:@=+-]+$/', $argument) ? $argument : escapeshellarg($argument))
            ->implode(' ');
    }

    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now());
    }

    /**
     * Stappen ("→ …") die het script meldt, voor de voortgangsweergave.
     *
     * @return Collection<int, string>
     */
    public function steps(): Collection
    {
        return Str::of($this->output ?? '')
            ->explode("\n")
            ->filter(fn (string $line): bool => str_starts_with($line, '→ '))
            ->map(fn (string $line): string => trim(Str::after($line, '→ ')))
            ->values();
    }

    public function lastMessage(): ?string
    {
        $line = Str::of($this->output ?? '')->trim()->explode("\n")->filter(fn (string $line): bool => trim($line) !== '')->last();

        return $line === null ? null : trim($line);
    }
}
