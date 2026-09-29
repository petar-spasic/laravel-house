<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductionSeeder extends Seeder
{
    /**
     * The operator first: an address that is also in ADMIN_EMAILS keeps OPERATOR_PASSWORD and is reported once.
     * What is seeded and what happens to an existing account: database/CLAUDE.md.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        $operator = $this->seedOperator();

        foreach (array_diff(config('auth.admins', []), [$operator]) as $email) {
            $admin = $this->seedVerifiedUser($email, Str::before($email, '@'), Str::random(40));

            if ($admin->email_verified_at === null) {
                $this->command?->warn("{$email} already has an unverified account, left untouched, so the Horizon gate stays closed to it: confirm who holds the address, then remove the account and seed again, or verify it by hand.");
            }
        }
    }

    /** @return string|null The operator's address, when one was seeded. */
    private function seedOperator(): ?string
    {
        $email = config('auth.operator.email');
        $password = config('auth.operator.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn('OPERATOR_EMAIL / OPERATOR_PASSWORD unset or empty: no operator account seeded.');

            return null;
        }

        // env() turns the strings true, false and null into non-strings.
        if (! is_string($password)) {
            $this->command?->warn('OPERATOR_PASSWORD is not a plain string (env() reads true, false and null as non-strings): no operator account seeded.');

            return null;
        }

        $operator = $this->seedVerifiedUser($email, 'Operator', $password);

        if ($operator->email_verified_at === null) {
            $this->command?->warn("{$email} already has an unverified account, left untouched and OPERATOR_PASSWORD not applied: verify it by hand if it is the operator's.");
        }

        return $email;
    }

    /**
     * An existing account, verified or not, is never modified: verifying a registered-through-the-form account
     * would hand it to whoever registered it. Addresses match exactly (the config is lower-cased, like the
     * addresses Fortify stores). firstOrCreate re-reads the winner when a concurrent seed inserts first (the
     * unique index on users.email). Unguarded: email_verified_at is not fillable, and strict mode would throw
     * (a direct run() does not unguard; `db:seed` does).
     */
    private function seedVerifiedUser(string $email, string $name, string $password): User
    {
        return User::unguarded(fn () => User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'email_verified_at' => now()],
        ));
    }
}
