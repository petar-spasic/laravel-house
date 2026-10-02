# if:tenancy
<?php

// Merge into tests/TestCase.php (why: tests/CLAUDE.md, Tenancy). Without it RefreshDatabase migrates as the app role,
// which cannot drop or create tables, and the shipped tests fail.

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function artisan($command, $parameters = [])
    {
        if (str_starts_with($command, 'migrate')) {
            $parameters += ['--database' => 'pgsql_owner'];
        }

        return parent::artisan($command, $parameters);
    }
}
# endif
