<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(app()->isProduction()
            ? [ProductionSeeder::class]
            : [ReferenceDataSeeder::class, DevSeeder::class]);
    }
}
