<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Het hoofdwachtwoord kies je zelf bij de eerste start van de app.
     */
    public function run(): void
    {
        $this->call(SiteSeeder::class);
    }
}
