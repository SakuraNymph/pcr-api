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
        Schema::create('admins', function (Blueprint $table) {
            $table->id();

            $table->string('username', 100)
                ->unique()
                ->comment('登录账号');

            $table->string('password', 255)
                ->comment('登录密码');

            $table->string('nickname', 100)
                ->default('')
                ->comment('昵称');

            $table->string('avatar', 500)
                ->default('')
                ->comment('头像');

            $table->string('email', 100)
                ->default('')
                ->comment('邮箱');

            $table->string('mobile', 30)
                ->default('')
                ->comment('手机号');

            $table->tinyInteger('status')
                ->default(1)
                ->comment('状态 0禁用 1启用');

            $table->timestamp('last_login_at')
                ->nullable()
                ->comment('最后登录时间');

            $table->string('last_login_ip', 50)
                ->default('')
                ->comment('最后登录IP');

            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};