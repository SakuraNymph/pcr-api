<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_triple_caches', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();

            $table->char('year_month', 6)
                ->comment('所属年月，格式 YYYYMM');

            $table->unsignedTinyInteger('mode')
                ->comment('作业模式:1=自动;2=手动');

            $table->text('team_ids')
                ->comment('参与组合生成的系统作业ID，升序逗号分隔');

            $table->unsignedInteger('team_count')
                ->default(0)
                ->comment('参与组合生成的系统作业数量');

            $table->char('signature', 64)
                ->comment('系统作业及角色状态签名');

            $table->json('combinations')
                ->comment('系统三刀基础组合结果');

            $table->timestamps();

            $table->unique(
                ['year_month', 'mode'],
                'uk_system_triple_caches_period_mode'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_triple_caches');
    }
};
