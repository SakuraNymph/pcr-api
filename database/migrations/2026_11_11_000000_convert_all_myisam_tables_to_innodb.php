<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $configuredDatabase = (string) $connection->getDatabaseName();
        $actualDatabase = (string) (DB::selectOne('SELECT DATABASE() AS database_name')->database_name ?? '');
        $expectedDatabase = env('INNODB_MIGRATION_EXPECTED_DATABASE');

        if ($expectedDatabase !== null && $expectedDatabase !== '') {
            if ($configuredDatabase !== $expectedDatabase || $actualDatabase !== $expectedDatabase) {
                throw new RuntimeException(
                    "Refusing to convert storage engines: expected database [{$expectedDatabase}], "
                    . "configured [{$configuredDatabase}], actual [{$actualDatabase}]."
                );
            }
        } elseif ($configuredDatabase !== $actualDatabase) {
            throw new RuntimeException(
                "Refusing to convert storage engines: configured database [{$configuredDatabase}] "
                . "does not match actual database [{$actualDatabase}]."
            );
        }

        $tables = collect(DB::select(
            "SELECT TABLE_NAME AS table_name
             FROM information_schema.tables
             WHERE table_schema = ?
               AND table_type = 'BASE TABLE'
               AND LOWER(engine) = 'myisam'
             ORDER BY TABLE_NAME",
            [$actualDatabase]
        ))
            ->pluck('table_name')
            ->map(fn ($table) => (string) $table)
            ->reject(fn (string $table) => $table === 'migrations')
            ->values();

        if ($this->tableIsMyIsam($actualDatabase, 'migrations')) {
            $tables->push('migrations');
        }

        if ($tables->isEmpty()) {
            $this->logSafely('InnoDB conversion skipped: no MyISAM tables found.', [
                'database' => $actualDatabase,
            ]);

            return;
        }

        foreach ($tables as $table) {
            $table = (string) $table;
            $before = $this->engineFor($actualDatabase, $table);

            if (strcasecmp((string) $before, 'InnoDB') === 0) {
                continue;
            }

            $this->logSafely('Converting table storage engine to InnoDB.', [
                'database' => $actualDatabase,
                'table' => $table,
                'before' => $before,
            ]);

            DB::statement('ALTER TABLE `' . str_replace('`', '``', $table) . '` ENGINE=InnoDB');

            $after = $this->engineFor($actualDatabase, $table);

            if (strcasecmp((string) $after, 'InnoDB') !== 0) {
                throw new RuntimeException(
                    "Failed to convert table [{$table}] to InnoDB; current engine is [{$after}]."
                );
            }

            $this->logSafely('Table storage engine converted to InnoDB.', [
                'database' => $actualDatabase,
                'table' => $table,
                'before' => $before,
                'after' => $after,
            ]);
        }
    }

    public function down(): void
    {
        // Engine conversion is intentionally irreversible.
    }

    private function tableIsMyIsam(string $database, string $table): bool
    {
        $engine = $this->engineFor($database, $table);

        return $engine !== null && strcasecmp($engine, 'MyISAM') === 0;
    }

    private function engineFor(string $database, string $table): ?string
    {
        $row = DB::selectOne(
            'SELECT ENGINE AS engine
             FROM information_schema.tables
             WHERE table_schema = ?
               AND table_name = ?
             LIMIT 1',
            [$database, $table]
        );

        if ($row === null || $row->engine === null) {
            return null;
        }

        return (string) $row->engine;
    }

    private function logSafely(string $message, array $context = []): void
    {
        try {
            Log::info($message, $context);
        } catch (\Throwable) {
            // Logging must not block the engine conversion.
        }
    }
};
