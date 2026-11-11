<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $database = DB::connection()->getDatabaseName();

        foreach (['game_role_statistics', 'team_signature_locks'] as $table) {
            $engine = DB::table('information_schema.tables')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->value('engine');

            if ($engine !== null && strcasecmp((string) $engine, 'InnoDB') !== 0) {
                DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
            }
        }
    }

    public function down(): void {}
};
