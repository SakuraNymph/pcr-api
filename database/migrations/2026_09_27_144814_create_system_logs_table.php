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
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();

            // 操作管理员
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_username', 100)->nullable();

            // 操作模块与动作
            $table->string('module', 50);
            $table->string('action', 50);

            // 被操作的对象
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            // 操作描述
            $table->string('description', 255)->nullable();

            // HTTP 请求信息
            $table->string('method', 10);
            $table->string('path', 255);

            // 请求参数
            $table->json('request_data')->nullable();

            // 数据变化
            $table->json('changes')->nullable();

            // 操作结果：0=失败，1=成功
            $table->unsignedTinyInteger('result')->default(1);

            // 失败原因
            $table->text('error_message')->nullable();

            // 客户端信息
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            // 日志只记录创建时间，不需要 updated_at
            $table->timestamp('created_at')->useCurrent();

            // 索引
            $table->index('admin_id');
            $table->index('module');
            $table->index('action');
            $table->index('target_type');
            $table->index('target_id');
            $table->index('result');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};