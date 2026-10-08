<?php

namespace Database\Factories;

use App\Enums\SiteMode;
use App\Enums\SiteProvider;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::slug(fake()->unique()->domainWord().'-'.fake()->randomNumber(3));

        return [
            'name' => $name,
            'is_favorite' => false,
            'snapshot' => $this->snapshot($name),
            'synced_at' => now(),
        ];
    }

    /**
     * Een eigen naam in een test moet ook in de afgeleide paden en URL's terechtkomen.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Site $site): void {
            if (($site->snapshot['name'] ?? null) === $site->name) {
                return;
            }

            $derived = array_intersect_key($this->snapshot($site->name), array_flip([
                'name', 'slug', 'target', 'host', 'live_host', 'live_site_url', 'local_url', 'project_dir', 'wp_content_dir',
            ]));

            $site->snapshot = array_replace($site->snapshot ?? [], $derived);
        });
    }

    public function favorite(): static
    {
        return $this->state(['is_favorite' => true]);
    }

    public function sftp(): static
    {
        return $this->snapshotState(['mode' => SiteMode::Sftp->value, 'remote' => 'site/public_html/wp-content', 'root' => 'site/public_html']);
    }

    public function hostinger(): static
    {
        return $this->snapshotState(['provider' => SiteProvider::Hostinger->value, 'port' => 65002, 'opts' => '-p 65002']);
    }

    public function built(): static
    {
        return $this->snapshotState([
            'built' => true,
            'ddev_status' => 'running',
            'git' => [
                'branch' => 'dev', 'dirty' => 0, 'pending_files' => 0, 'unpushed_commits' => 0,
                'last_commit' => ['hash' => 'abc1234', 'subject' => 'Start: live-staat', 'date' => now()->toIso8601String()],
                'last_deploy' => null, 'remote' => null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function snapshotState(array $values): static
    {
        return $this->state(fn (array $attributes): array => [
            'snapshot' => array_replace_recursive($attributes['snapshot'] ?? [], $values),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $name): array
    {
        $host = $name.'.tempurl.host';

        return [
            'name' => $name,
            'slug' => $name,
            'target' => 'wesley@'.$host,
            'user' => 'wesley',
            'host' => $host,
            'port' => 22,
            'opts' => '',
            'remote' => '/home/wesley/'.$name.'/public_html/wp-content',
            'root' => '/home/wesley/'.$name.'/public_html',
            'mode' => SiteMode::Ssh->value,
            'provider' => SiteProvider::Wpmudev->value,
            'live_url' => null,
            'live_host' => $name.'.nl',
            'live_site_url' => 'https://'.$name.'.nl',
            'local_url' => 'https://'.$name.'.ddev.site',
            'project_dir' => '/home/wesley/wp-sites/'.$name,
            'wp_content_dir' => '/home/wesley/wp-sites/'.$name.'/wp-content',
            'built' => false,
            'ddev_status' => 'absent',
            'git' => null,
            'backups' => 0,
            'last_backup' => null,
        ];
    }
}
