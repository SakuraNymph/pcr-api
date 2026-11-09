<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->renameColumn('auto', 'mode');
        });

        Schema::table('team_links', function (Blueprint $table) {
            $table->dropColumn('text');

            $table->string('author', 32)
                ->after('url')
                ->comment('作者');

            $table->unsignedTinyInteger('difficulty')
                ->after('author')
                ->comment('难度等级:1-5');

            $table->unsignedInteger('damage')
                ->after('difficulty')
                ->comment('来源伤害');

            $table->index(
                ['author', 'difficulty'],
                'idx_team_links_author_difficulty'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_links', function (Blueprint $table) {
            $table->dropIndex('idx_team_links_author_difficulty');

            $table->dropColumn([
                'author',
                'difficulty',
                'damage',
            ]);

            $table->string('text', 500)
                ->nullable()
                ->after('note')
                ->comment('链接说明');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->renameColumn('mode', 'auto');
        });
    }
};