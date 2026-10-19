<?php

namespace Database\Factories;

use App\Enums\OffsiteStatus;
use App\Models\OffsiteBackup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<OffsiteBackup>
 */
class OffsiteBackupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::slug(fake()->unique()->domainWord().'-'.fake()->randomNumber(3));
        $created = now()->subDays(fake()->numberBetween(0, 60))->startOfMinute();
        $kind = fake()->randomElement(['push', 'manual', 'rebuild']);
        $id = $created->format('Ymd-His').'-'.$kind;

        return [
            'site_slug' => $name,
            'site_name' => $name,
            'backup_id' => $id,
            'kind' => $kind,
            'backup_created_at' => $created,
            'object_key' => "bd-deck/{$name}/{$id}.tar.enc",
            'size' => fake()->numberBetween(10_000, 50_000_000),
            'key_id' => null,
            'status' => OffsiteStatus::Pending,
            'error' => null,
            'uploaded_at' => null,
        ];
    }

    public function uploaded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OffsiteStatus::Uploaded,
            'uploaded_at' => now(),
        ]);
    }

    public function failed(string $error = 'De sleutel klopt niet.'): static
    {
        return $this->state(['status' => OffsiteStatus::Failed, 'error' => $error]);
    }

    public function forBackup(string $slug, string $backupId): static
    {
        return $this->state([
            'site_slug' => $slug,
            'site_name' => $slug,
            'backup_id' => $backupId,
            'kind' => Str::after(Str::after($backupId, '-'), '-') ?: null,
            'backup_created_at' => Carbon::createFromFormat('Ymd-His', substr($backupId, 0, 15)),
            'object_key' => "bd-deck/{$slug}/{$backupId}.tar.enc",
        ]);
    }
}
