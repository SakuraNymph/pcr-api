<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_clan_schedule_members', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('schedule_id')->comment('排刀表ID');
            // users 表是历史 MyISAM 表，uid 与其 int 主键保持一致，不建立跨引擎外键。
            $table->integer('uid')->comment('成员用户ID');
            $table->timestamps();

            $table->unique(
                ['schedule_id', 'uid'],
                'user_clan_schedule_members_schedule_uid_unique'
            );
            $table->index('uid', 'user_clan_schedule_members_uid_index');

            $table->foreign('schedule_id')
                ->references('id')
                ->on('user_clan_schedules')
                ->cascadeOnDelete();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_clan_schedule_members');
    }
};
