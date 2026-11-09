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
        Schema::create('admin_login_logs', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('admin_id')
                ->default(0)
                ->comment('管理员ID');

            $table->string('username', 100)
                ->default('')
                ->comment('登录账号');

            $table->string('ip', 50)
                ->default('')
                ->comment('登录IP');

            $table->string('user_agent', 500)
                ->default('')
                ->comment('用户代理');

            $table->tinyInteger('login_status')
                ->default(1)
                ->comment('登录状态 1成功 0失败');

            $table->string('fail_reason', 255)
                ->default('')
                ->comment('失败原因');

            $table->string('country', 100)
                ->default('')
                ->comment('国家');

            $table->string('province', 100)
                ->default('')
                ->comment('省份');

            $table->string('city', 100)
                ->default('')
                ->comment('城市');

            $table->string('device', 100)
                ->default('')
                ->comment('设备类型');

            $table->string('browser', 100)
                ->default('')
                ->comment('浏览器');

            $table->string('os', 100)
                ->default('')
                ->comment('操作系统');

            $table->timestamps();

            // 索引
            $table->index('admin_id');
            $table->index('username');
            $table->index('ip');
            $table->index('login_status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_login_logs');
    }
};