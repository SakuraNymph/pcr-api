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
        Schema::table('game_roles', function (Blueprint $table) {
            $table->unsignedTinyInteger('obtain')
                ->nullable()
                ->after('element')
                ->comment('1抽卡获取2活动获取3兑换获取');

            $table->unsignedTinyInteger('probability')
                ->nullable()
                ->after('obtain')
                ->comment('1(0.7)2(1.4)');

            $table->date('up_time')
                ->nullable()
                ->after('probability')
                ->comment('卡池开始时间');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_roles', function (Blueprint $table) {
            $table->dropColumn([
                'obtain',
                'probability',
                'up_time',
            ]);
        });
    }
};