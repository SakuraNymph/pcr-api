<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('system_triple_account_caches');
        Schema::dropIfExists('system_triple_caches');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_triple_caches')) {
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

        if (! Schema::hasTable('system_triple_account_caches')) {
            Schema::create('system_triple_account_caches', function (Blueprint $table): void {
                $table->engine = 'InnoDB';

                $table->id();
                $table->char('year_month', 6);
                $table->unsignedTinyInteger('mode');
                $table->unsignedTinyInteger('algorithm_version')->default(1);
                $table->char('source_signature', 64);
                $table->unsignedBigInteger('uid');
                $table->unsignedBigInteger('account_id');
                $table->char('role_pool_hash', 64);
                $table->char('filter_hash', 64);
                $table->json('filters');
                $table->json('results');
                $table->unsignedTinyInteger('result_count')->default(0);
                $table->timestamp('generated_at')->nullable();
                $table->timestamps();

                $table->unique(
                    [
                        'year_month',
                        'mode',
                        'algorithm_version',
                        'source_signature',
                        'uid',
                        'account_id',
                        'role_pool_hash',
                        'filter_hash',
                    ],
                    'uk_system_triple_account_cache_key'
                );
                $table->index(
                    ['year_month', 'mode', 'account_id'],
                    'idx_system_triple_account_period_account'
                );
            });
        }
    }
};
