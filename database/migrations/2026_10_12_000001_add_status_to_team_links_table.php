<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_links', function (Blueprint $table): void {
            $table->unsignedTinyInteger('status')
                ->default(1)
                ->after('damage')
                ->comment('来源状态:0隐藏;1显示');

            $table->index(['status', 'id'], 'idx_team_links_status_id');
        });

        DB::table('team_links')
            ->whereIn('team_id', DB::table('teams')->select('id')->where('status', 0))
            ->update(['status' => 0]);
    }

    public function down(): void
    {
        Schema::table('team_links', function (Blueprint $table): void {
            $table->dropIndex('idx_team_links_status_id');
            $table->dropColumn('status');
        });
    }
};
