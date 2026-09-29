<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Upserts `database/data/<table>.json` (a list of rows) and
 * `database/data/<table>/…/*.json` (a row or a list per file) on `id`.
 * Never deletes: removing a row is a migration.
 */
class ReferenceDataSeeder extends Seeder
{
    private const ID_PATTERN = '/^[a-z]{2,6}_[A-Za-z0-9]{16}$/';

    private const CHUNK = 500;

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->tables() as $table => $files) {
                $this->seedTable($table, $files);
            }
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private function tables(): array
    {
        $root = database_path('data');
        $tables = [];

        foreach (File::glob($root.'/*.json') as $file) {
            $tables[basename($file, '.json')][] = $file;
        }

        foreach (File::directories($root) as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() === 'json') {
                    $tables[basename($directory)][] = $file->getPathname();
                }
            }
        }

        ksort($tables);

        return array_map(function (array $files): array {
            sort($files);

            return $files;
        }, $tables);
    }

    /**
     * @param  list<string>  $files
     */
    private function seedTable(string $table, array $files): void
    {
        $rows = [];
        $columns = null;

        foreach ($files as $file) {
            $decoded = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);

            foreach (array_is_list($decoded) ? $decoded : [$decoded] as $row) {
                if (! is_string($row['id'] ?? null) || preg_match(self::ID_PATTERN, $row['id']) !== 1) {
                    throw new RuntimeException("{$file}: every row needs an explicit Stripe-style id, got ".json_encode($row['id'] ?? null));
                }

                $keys = array_keys($row);
                sort($keys);
                $columns ??= $keys;

                if ($keys !== $columns) {
                    throw new RuntimeException("{$file}: row {$row['id']} has different columns than the rest of {$table}");
                }

                $rows[] = array_map(fn (mixed $value): mixed => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value, $row);
            }
        }

        $update = array_values(array_diff($columns ?? [], ['id']));

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, ['id'], $update);
        }

        $this->command?->info(sprintf('%s: %d rows', $table, count($rows)));
    }
}
