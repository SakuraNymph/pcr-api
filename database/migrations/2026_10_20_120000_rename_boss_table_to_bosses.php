<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('boss') && ! Schema::hasTable('bosses')) {
            Schema::rename('boss', 'bosses');

            // Keep the index name aligned with the plural table name.
            Schema::table('bosses', function (Blueprint $table) {
                $table->renameIndex(
                    'idx_boss_year_month_status_sort',
                    'idx_bosses_year_month_status_sort'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bosses') && ! Schema::hasTable('boss')) {
            Schema::table('bosses', function (Blueprint $table) {
                $table->renameIndex(
                    'idx_bosses_year_month_status_sort',
                    'idx_boss_year_month_status_sort'
                );
            });

            Schema::rename('bosses', 'boss');
        }
    }
};
