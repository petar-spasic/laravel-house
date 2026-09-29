<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        ['email' => $email, 'password' => $password] = config('auth.operator');

        if (blank($email) || blank($password)) {
            $this->command?->warn('OPERATOR_EMAIL / OPERATOR_PASSWORD unset: no operator account seeded.');

            return;
        }

        if (User::query()->where('email', $email)->doesntExist()) {
            User::forceCreate([
                'name' => 'Operator',
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);
        }
    }
}
