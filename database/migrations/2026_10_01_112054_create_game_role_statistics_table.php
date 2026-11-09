<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_role_statistics', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('game_role_id')
                ->unique()
                ->comment('游戏角色ID');

            $table->unsignedBigInteger('use_times')
                ->default(0)
                ->comment('角色使用次数');

            $table->timestamps();

            $table->foreign('game_role_id')
                ->references('id')
                ->on('game_roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_role_statistics');
    }
};
