<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        $this->seedAdmins();
        $this->seedOperator();
    }

    /**
     * Every ADMIN_EMAILS address exists as a verified user, or the Horizon gate (which needs a verified
     * email) is closed to it. A missing one gets a random password nobody sees: the owner of the address
     * signs in through the reset-password mail. An existing account is never touched: verifying a
     * registered-through-the-form account would hand it to whoever registered it.
     */
    private function seedAdmins(): void
    {
        foreach (config('auth.admins') as $email) {
            if (User::query()->where('email', $email)->doesntExist()) {
                User::forceCreate([
                    'name' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => Str::random(40),
                    'email_verified_at' => now(),
                ]);
            }
        }
    }

    private function seedOperator(): void
    {
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
