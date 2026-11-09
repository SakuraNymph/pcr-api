<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_role_images', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('game_role_id')
                ->comment('游戏角色ID');

            $table->unsignedBigInteger('image_id')
                ->comment('当前使用的头像ID');

            $table->boolean('is_6_star')
                ->default(false)
                ->comment('是否使用六星头像');

            $table->boolean('is_download')
                ->default(false)
                ->comment('头像是否已下载');

            $table->timestamps();

            $table->unique('game_role_id');

            $table->unique('image_id');

            $table->foreign('game_role_id')
                ->references('id')
                ->on('game_roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_role_images');
    }
};
