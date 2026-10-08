<?php

namespace Database\Factories;

use App\Enums\CredentialKind;
use App\Models\Credential;
use App\Support\SealedSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * Geen echte versleuteling: tests die het geheim lezen zetten het zelf via een ontgrendelde kluis.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => null,
            'label' => fake()->words(2, true),
            'kind' => CredentialKind::ApiKey,
            'username' => fake()->userName(),
            'secret' => new SealedSecret('niet-ontsleutelbaar'),
            'url' => fake()->url(),
            'notes' => null,
        ];
    }
}
