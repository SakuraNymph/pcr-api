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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // IP 地址
            $table->string('ip', 45);
            $table->unique('ip', 'users_ip_unique');

            // 地理位置
            $table->string('country')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();

            // 用户信息
            $table->string('nickname')->nullable();

            // 用户状态
            // 1 = 正常，0 = 禁用
            $table->tinyInteger('status')->default(0);

            // 是否订阅
            // false = 未订阅，true = 已订阅
            $table->tinyInteger('is_subscribe')->default(1);

            // 登录信息
            $table->string('email')->nullable();
            $table->string('password')->nullable();

            // Laravel Remember Me
            $table->rememberToken();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
