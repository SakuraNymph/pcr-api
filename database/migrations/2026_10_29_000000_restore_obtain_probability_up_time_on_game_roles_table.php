<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair databases where the original migration was recorded but the
     * game_roles columns were not actually created.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('game_roles', 'obtain')) {
            Schema::table('game_roles', function (Blueprint $table): void {
                $table->unsignedTinyInteger('obtain')
                    ->nullable()
                    ->after('element')
                    ->comment('1抽卡获取2活动获取3兑换获取');
            });
        }

        if (! Schema::hasColumn('game_roles', 'probability')) {
            Schema::table('game_roles', function (Blueprint $table): void {
                $table->unsignedTinyInteger('probability')
                    ->nullable()
                    ->after('obtain')
                    ->comment('1(0.7)2(1.4)');
            });
        }

        if (! Schema::hasColumn('game_roles', 'up_time')) {
            Schema::table('game_roles', function (Blueprint $table): void {
                $table->date('up_time')
                    ->nullable()
                    ->after('probability')
                    ->comment('卡池开始时间');
            });
        }
    }

    /**
     * The original migration may have created these columns in another
     * environment, so rolling this repair migration back must not drop them.
     */
    public function down(): void
    {
        // Intentionally left empty to avoid deleting columns this migration
        // may not have created.
    }
};
