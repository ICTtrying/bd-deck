<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class OffsiteBackupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // rijen ontstaan pas bij echte uploads naar de eigen bucket; nepdata zou naar onbestaande objecten wijzen
    }
}
