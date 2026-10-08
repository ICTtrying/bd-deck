<?php

namespace App\Services\WpOpen;

use App\Enums\RunStatus;
use App\Jobs\RunWpOpenCommand;
use App\Models\CommandRun;

final class CommandRunner
{
    /**
     * @param  array<string, string>  $secretEnvironment  bv. WPO_PASS; gaat versleuteld de wachtrij in
     */
    public function queue(WpOpenCommand $command, array $secretEnvironment = []): CommandRun
    {
        $run = CommandRun::query()->create([
            'site_id' => $command->site?->id,
            'site_name' => $command->site?->name,
            'action' => $command->action,
            'label' => $command->label(),
            'arguments' => $command->arguments,
            'status' => RunStatus::Queued,
        ]);

        RunWpOpenCommand::dispatch($run, $command->script, $secretEnvironment)->onQueue($command->action->queue());

        return $run;
    }

    public function cancel(CommandRun $run): void
    {
        match ($run->status) {
            RunStatus::Queued => $run->update(['status' => RunStatus::Cancelled, 'finished_at' => now()]),
            RunStatus::Running => $run->update(['status' => RunStatus::Cancelling]),
            default => null,
        };
    }
}
