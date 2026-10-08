<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Enums\WpOpenAction;
use App\Models\CommandRun;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommandRun>
 */
class CommandRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'site_name' => fn (array $attributes): ?string => Site::query()->find($attributes['site_id'])?->name,
            'action' => WpOpenAction::Test,
            'label' => WpOpenAction::Test->label(),
            'arguments' => ['test', 'demo', '--json'],
            'status' => RunStatus::Queued,
        ];
    }

    public function running(): static
    {
        return $this->state(['status' => RunStatus::Running, 'pid' => 4242, 'started_at' => now()]);
    }

    public function succeeded(string $output = "✓ Klaar\n"): static
    {
        return $this->state([
            'status' => RunStatus::Succeeded, 'exit_code' => 0, 'output' => $output,
            'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);
    }

    public function failed(string $output = "✗ Mislukt\n"): static
    {
        return $this->state([
            'status' => RunStatus::Failed, 'exit_code' => 1, 'output' => $output,
            'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);
    }
}
