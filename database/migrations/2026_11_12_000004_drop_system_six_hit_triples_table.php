<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('system_six_hit_triples');
    }

    public function down(): void
    {
        if (Schema::hasTable('system_six_hit_triples')) {
            return;
        }

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
    }
};
