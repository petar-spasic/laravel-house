<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ReferenceDataSeeder::class, app()->isProduction() ? ProductionSeeder::class : DevSeeder::class]);
    }
}
