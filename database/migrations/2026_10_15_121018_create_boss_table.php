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
        Schema::create('boss', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary()->comment('BOSS ID');
            $table->string('name', 255)->comment('BOSS 名称');
            $table->string('file_path', 255)->comment('BOSS 图片路径');
            $table->char('year_month', 6)->nullable()->comment('所属年月，格式 YYYYMM');
            $table->tinyInteger('status')->default(0)->comment('状态：0=上月BOSS，1=当月BOSS');
            $table->unsignedTinyInteger('sort')->nullable()->comment('BOSS 顺序：1=一王，5=五王');

            $table->index(
                ['year_month', 'status', 'sort'],
                'idx_boss_year_month_status_sort'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boss');
    }
};
