# if:tenancy
<?php

// Merge into tests/TestCase.php (why: tests/CLAUDE.md, Tenancy). Without it RefreshDatabase migrates as the app role,
// which cannot drop or create tables, and the shipped tests fail.

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /** @var array<string, true> */
    private static array $workerDatabases = [];

    public function artisan($command, $parameters = [])
    {
        if (str_starts_with($command, 'migrate')) {
            $parameters += ['--database' => 'pgsql_owner'];
        }

        return parent::artisan($command, $parameters);
    }

    // Runs before Laravel's parallel hooks, which would create the worker's database as the app role (NOCREATEDB) and
    // move only the default connection. The owner creates it, with the grants roles.sql gives in {{app}}_test (default
    // privileges are per database), and pgsql_owner follows it.
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $token = ParallelTesting::token();

        if (empty($_SERVER['LARAVEL_PARALLEL_TESTING']) || ! $token || ParallelTesting::option('without_databases')) {
            return;
        }

        $database = config('database.connections.pgsql_owner.database')."_test_{$token}";
        $first = ! isset(self::$workerDatabases[$database]);

        if ($first && ! DB::connection('pgsql_owner')->scalar('select count(*) from pg_database where datname = ?', [$database])) {
            Schema::connection('pgsql_owner')->createDatabase($database);
        }

        config(['database.connections.pgsql_owner.database' => $database]);
        DB::purge('pgsql_owner');

        if ($first) {
            DB::connection('pgsql_owner')->unprepared(<<<'SQL'
                GRANT USAGE ON SCHEMA public TO {{app}}_app;
                GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {{app}}_app;
                GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {{app}}_app;
                ALTER DEFAULT PRIVILEGES FOR ROLE {{app}} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {{app}}_app;
                ALTER DEFAULT PRIVILEGES FOR ROLE {{app}} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {{app}}_app;
                SQL);
            self::$workerDatabases[$database] = true;
        }
    }
}
# endif
