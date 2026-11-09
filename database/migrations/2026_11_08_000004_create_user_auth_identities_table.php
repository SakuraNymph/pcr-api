<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 第三方登录身份关联表（预留，不实现实际第三方登录）。
     */
    public function up(): void
    {
        Schema::create('user_auth_identities', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('uid')->comment('用户ID，对应 users.id');
            $table->string('provider', 20)->comment('qq / wechat_web / wechat_mp');
            $table->string('app_id', 64)->comment('平台应用标识，如小程序 appid 或开放平台应用 ID');
            $table->string('openid', 64)->nullable()->comment('平台 openid');
            $table->string('unionid', 64)->nullable()->comment('平台 unionid，跨应用归并用');
            $table->string('nickname', 64)->nullable()->comment('平台昵称快照');
            $table->string('avatar', 255)->nullable()->comment('平台头像快照');
            $table->timestamps();

            $table->unique(
                ['provider', 'app_id', 'openid'],
                'user_auth_identities_provider_app_id_openid_unique'
            );
            $table->unique(
                ['uid', 'provider'],
                'user_auth_identities_uid_provider_unique'
            );
            $table->index(
                ['provider', 'unionid'],
                'user_auth_identities_provider_unionid_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_auth_identities');
    }
};
