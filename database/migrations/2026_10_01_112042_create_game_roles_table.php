<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary()->comment('游戏角色内部ID');

            $table->unsignedBigInteger('role_id')->unique()->comment('游戏官方角色ID');
            $table->unsignedBigInteger('role_id_1')->comment('1星角色ID');
            $table->unsignedBigInteger('role_id_3')->comment('3星角色ID');
            $table->unsignedBigInteger('role_id_6')->nullable()->comment('6星角色ID');

            $table->unsignedTinyInteger('element')->comment('属性：1-5');
            $table->tinyInteger('atk_type')->comment('攻击类型：1物理，0辅助，-1魔法');

            $table->boolean('is_clan_battle')
                ->default(false)
                ->comment('是否公会战角色');

            $table->string('name', 100)->comment('角色名称');
            $table->string('nickname', 100)->comment('角色昵称');

            $table->unsignedInteger('search_area_width')
                ->comment('官方站位距离/攻击距离');

            $table->unsignedTinyInteger('position')
                ->comment('位置：1前卫，2中卫，3后卫');

            $table->boolean('status')
                ->default(true)
                ->comment('状态：1启用，0禁用');

            $table->timestamps();

            $table->index('element');
            $table->index('atk_type');
            $table->index('position');
            $table->index('is_clan_battle');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_roles');
    }
};
