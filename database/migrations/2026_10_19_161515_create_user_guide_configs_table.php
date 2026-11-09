<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建新手指引配置表
     */
    public function up(): void
    {
        Schema::create('user_guide_configs', function (Blueprint $table) {
            // 用户 UID，每个用户只有一条配置记录
            $table->unsignedInteger('uid')->primary();

            // 新手指引配置
            $table->json('configuration');

            // 创建时间
            $table->timestamp('created_at')->useCurrent();

            // 更新时间
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * 删除新手指引配置表
     */
    public function down(): void
    {
        Schema::dropIfExists('user_guide_configs');
    }
};