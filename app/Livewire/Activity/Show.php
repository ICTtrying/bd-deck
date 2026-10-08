<?php

namespace App\Livewire\Activity;

use App\Models\CommandRun;
use App\Services\WpOpen\CommandRunner;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    public CommandRun $run;

    public function refreshRun(): void
    {
        $this->run->refresh();
    }

    public function cancel(CommandRunner $runner): void
    {
        $runner->cancel($this->run);
        $this->run->refresh();
    }

    public function render(): View
    {
        return view('livewire.activity.show')->title($this->run->label);
    }
}
