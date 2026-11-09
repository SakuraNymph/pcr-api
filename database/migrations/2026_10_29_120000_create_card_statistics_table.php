<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_statistics', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->integer('uid')->comment('网站用户ID');
            $table->unsignedBigInteger('account_id')->comment('游戏账号ID');
            $table->unsignedSmallInteger('year')->comment('卡池年份');
            $table->json('data')->comment('角色抽卡记录');
            $table->timestamps();

            $table->unique(
                ['uid', 'account_id', 'year'],
                'card_statistics_uid_account_year_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_statistics');
    }
};
