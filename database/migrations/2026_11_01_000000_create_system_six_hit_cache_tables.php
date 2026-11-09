<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_six_hit_cache_sets', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->char('year_month', 6)
                ->comment('所属年月，格式 YYYYMM');
            $table->unsignedTinyInteger('mode')
                ->comment('作业模式:1=自动;2=手动');
            $table->unsignedSmallInteger('algorithm_version')
                ->default(1)
                ->comment('六刀算法版本');
            $table->char('source_signature', 64)
                ->comment('参与计算的数据签名');
            $table->unsignedInteger('low_team_count')->default(0);
            $table->unsignedInteger('high_team_count')->default(0);
            $table->unsignedInteger('low_triple_count')->default(0);
            $table->unsignedInteger('high_triple_count')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['year_month', 'mode', 'source_signature'],
                'uk_six_cache_period_mode_sig'
            );
            $table->index(
                ['year_month', 'mode', 'id'],
                'idx_six_cache_period_mode'
            );
        });

        Schema::create('system_six_hit_triples', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('cache_set_id');
            $table->unsignedTinyInteger('side')
                ->comment('1=低阶段;2=高阶段');
            $table->unsignedInteger('team_1_id');
            $table->unsignedInteger('team_2_id');
            $table->unsignedInteger('team_3_id');
            $table->json('team_ids')
                ->comment('按BOSS和ID排序的三个作业ID');
            $table->unsignedTinyInteger('boss_1');
            $table->unsignedTinyInteger('boss_2');
            $table->unsignedTinyInteger('boss_3');
            $table->unsignedBigInteger('score_tenths');
            $table->unsignedTinyInteger('stage_2_count')->default(0);
            $table->unsignedTinyInteger('stage_3_count')->default(0);
            $table->unsignedTinyInteger('stage_4_count')->default(0);
            $table->unsignedTinyInteger('atk_positive_count')->default(0);
            $table->unsignedTinyInteger('atk_nonpositive_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['cache_set_id', 'side', 'team_1_id', 'team_2_id', 'team_3_id'],
                'uk_six_triple_teams'
            );
            $table->index(
                ['cache_set_id', 'side', 'score_tenths'],
                'idx_six_triple_score'
            );
            $table->index(
                ['cache_set_id', 'side', 'stage_2_count', 'stage_3_count'],
                'idx_six_triple_stage'
            );
            $table->index(
                ['cache_set_id', 'side', 'atk_positive_count', 'atk_nonpositive_count'],
                'idx_six_triple_atk'
            );
        });

        Schema::create('system_six_hit_result_caches', function (Blueprint $table): void {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('cache_set_id');
            $table->unsignedTinyInteger('endpoint')
                ->comment('1=本月;2=上月');
            $table->unsignedInteger('uid')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->char('role_pool_hash', 64)->nullable();
            $table->char('filter_hash', 64);
            $table->char('cache_key', 64);
            $table->json('filters');
            $table->json('results');
            $table->unsignedTinyInteger('result_count')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique('cache_key', 'uk_six_result_cache_key');
            $table->index(
                ['cache_set_id', 'endpoint'],
                'idx_six_result_cache_set'
            );
            $table->index(
                ['endpoint', 'uid', 'account_id'],
                'idx_six_result_owner'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_six_hit_result_caches');
        Schema::dropIfExists('system_six_hit_triples');
        Schema::dropIfExists('system_six_hit_cache_sets');
    }
};
