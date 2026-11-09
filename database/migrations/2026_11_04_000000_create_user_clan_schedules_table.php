<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建用户公会排刀表。
     */
    public function up(): void
    {
        Schema::create('user_clan_schedules', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();

            // 用户 ID，对应 users.id；每个用户只保存一份排刀表
            $table->integer('uid')->comment('用户ID');

            // 表格1 Boss 格子的玩家分配，保留阶段、周目、Boss、玩家名称和槽位
            $table->json('cells')->comment('Boss格子玩家分配');

            // 表格2 玩家显示顺序
            $table->json('player_order')->comment('玩家显示顺序');

            $table->timestamps();

            $table->unique('uid', 'user_clan_schedules_uid_unique');
        });
    }

    /**
     * 删除用户公会排刀表。
     */
    public function down(): void
    {
        Schema::dropIfExists('user_clan_schedules');
    }
};
