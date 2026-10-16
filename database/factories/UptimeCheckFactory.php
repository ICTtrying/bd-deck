<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\UptimeCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UptimeCheck>
 */
class UptimeCheckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'is_up' => true,
            'status_code' => 200,
            'response_ms' => fake()->numberBetween(120, 900),
            'error' => null,
            'checked_at' => now(),
        ];
    }

    public function down(string $error = 'HTTP 503'): static
    {
        return $this->state(['is_up' => false, 'status_code' => null, 'response_ms' => null, 'error' => $error]);
    }
}
