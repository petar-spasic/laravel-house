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

        // The operator first: an address that is also in ADMIN_EMAILS keeps OPERATOR_PASSWORD.
        $this->seedOperator();
        $this->seedAdmins();
    }

    /**
     * Every ADMIN_EMAILS address exists as a verified user, or the Horizon gate (which needs a verified
     * email) is closed to it. A missing one is created verified with a random password known to nobody;
     * its owner sets one through the password-reset flow. An existing account, verified or not, is never
     * modified: verifying a registered-through-the-form account would hand it to whoever registered it.
     */
    private function seedAdmins(): void
    {
        foreach (config('auth.admins', []) as $email) {
            $admin = $this->seedVerifiedUser($email, Str::before($email, '@'), Str::random(40));

            if ($admin->email_verified_at === null) {
                $this->command?->warn("{$email} has an unverified account: the Horizon gate stays closed to it. Confirm who holds the address, then remove the account and seed again.");
            }
        }
    }

    private function seedOperator(): void
    {
        $email = config('auth.operator.email');
        $password = config('auth.operator.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn('OPERATOR_EMAIL / OPERATOR_PASSWORD unset: no operator account seeded.');

            return;
        }

        $this->seedVerifiedUser($email, 'Operator', $password);
    }

    /**
     * firstOrCreate re-reads the winner when a concurrent seed inserts the address first. Unguarded:
     * email_verified_at is not fillable, and a strict-mode run would throw (a direct run() does not
     * unguard; `db:seed` does).
     */
    private function seedVerifiedUser(string $email, string $name, string $password): User
    {
        return User::unguarded(fn () => User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'email_verified_at' => now()],
        ));
    }
}
