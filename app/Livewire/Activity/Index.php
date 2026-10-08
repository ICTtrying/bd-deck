<?php

namespace App\Livewire\Activity;

use App\Enums\RunStatus;
use App\Models\CommandRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Activiteit')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'zoek', except: '')]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, CommandRun>
     */
    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        return CommandRun::query()
            ->when(RunStatus::tryFrom($this->status), fn ($query, RunStatus $status) => $query->where('status', $status))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('site_name', 'like', '%'.$this->search.'%')
                ->orWhere('label', 'like', '%'.$this->search.'%')))
            ->latestFirst()
            ->paginate(25);
    }

    #[Computed]
    public function hasActive(): bool
    {
        return CommandRun::query()->active()->exists();
    }

    public function render(): View
    {
        return view('livewire.activity.index', ['statuses' => RunStatus::cases()]);
    }
}
