<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('system_six_hit_result_caches');
        Schema::dropIfExists('system_six_hit_cache_sets');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_six_hit_cache_sets')) {
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
        }

        if (! Schema::hasTable('system_six_hit_result_caches')) {
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
    }
};
