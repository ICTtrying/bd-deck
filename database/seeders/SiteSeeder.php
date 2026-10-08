<?php

namespace Database\Seeders;

use App\Services\WpOpen\SiteRegistry;
use Illuminate\Database\Seeder;

class SiteSeeder extends Seeder
{
    /**
     * Sites komen uit de sitelijst van wpopen, niet uit verzonnen data.
     */
    public function run(SiteRegistry $registry): void
    {
        $count = $registry->sync();

        $this->command?->info("{$count} site(s) ingelezen uit wpopen.");
    }
}
