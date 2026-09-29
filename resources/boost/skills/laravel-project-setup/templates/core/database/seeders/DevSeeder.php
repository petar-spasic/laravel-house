<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

class DevSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('DevSeeder runs only in local and testing, not in '.app()->environment().'.');
        }

        fake()->seed(20260928);

        foreach (['admin@{{app}}.test' => 'Admin', 'dev@{{app}}.test' => 'Dev'] as $email => $name) {
            $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => self::PASSWORD]);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        }
    }
}
