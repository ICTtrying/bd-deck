<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\UptimeIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UptimeIncident>
 */
class UptimeIncidentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'reason' => 'HTTP 503',
            'started_at' => now()->subMinutes(30),
            'ended_at' => now()->subMinutes(10),
        ];
    }

    public function ongoing(): static
    {
        return $this->state(['ended_at' => null]);
    }
}
