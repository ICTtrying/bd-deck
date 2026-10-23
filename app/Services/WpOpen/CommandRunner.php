<?php

namespace App\Services\WpOpen;

use App\Enums\RunStatus;
use App\Jobs\RunWpOpenCommand;
use App\Models\CommandRun;
use App\Services\ShopifyStorePassword;

final class CommandRunner
{
    public function __construct(private readonly ShopifyStorePassword $storePasswords) {}

    /**
     * @param  array<string, string>  $secretEnvironment  bv. WPO_PASS; gaat versleuteld de wachtrij in
     * @param  bool  $notify  false voor controles op de achtergrond: die melden zich alleen als er iets te melden valt
     */
    public function queue(WpOpenCommand $command, array $secretEnvironment = [], bool $notify = true): CommandRun
    {
        // vóór het aanmaken van de run: bij een vergrendelde kluis mag er geen halve run achterblijven
        $secretEnvironment += $this->storePasswords->environmentFor($command->site, $command->action);

        $run = CommandRun::query()->create([
            'site_id' => $command->site?->id,
            'site_name' => $command->site?->name,
            'action' => $command->action,
            'label' => $command->label(),
            'arguments' => $command->arguments,
            'status' => RunStatus::Queued,
        ]);

        RunWpOpenCommand::dispatch($run, $command->script, $secretEnvironment, $notify)->onQueue($command->action->queue());

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
