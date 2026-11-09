<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建用户角色池表。
     */
    public function up(): void
    {
        Schema::create('user_role_pools', function (Blueprint $table) {
            $table->id();

            // 用户 ID，对应 users.id
            // 一个用户可以拥有多个角色池，因此不设置唯一索引
            $table->integer('uid')->comment('用户ID');

            // 角色池名称
            $table->string('nickname', 64)->comment('角色池名称');

            // 保存 game_roles.id 组成的 JSON 数组
            $table->json('roles')->comment('角色ID列表');

            // 保存该游戏账号选中的大师币角色 ID 数组
            $table->json('coin')->nullable()->comment('大师币角色ID列表');

            // 软删除状态：1 正常，0 已删除
            $table->tinyInteger('status')
                ->default(1)
                ->comment('状态：1正常，0已删除');

            $table->timestamps();

            // 按用户查询角色池
            $table->index('uid', 'user_role_pools_uid_index');

            // 按用户和状态查询有效角色池
            $table->index(
                ['uid', 'status'],
                'user_role_pools_uid_status_index'
            );
        });
    }

    /**
     * 删除用户角色池表。
     */
    public function down(): void
    {
        Schema::dropIfExists('user_role_pools');
    }
};
