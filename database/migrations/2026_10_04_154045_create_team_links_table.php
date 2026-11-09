
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_links', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->unsignedInteger('id')
                ->autoIncrement()
                ->comment('链接ID');

            $table->unsignedInteger('team_id')
                ->comment('作业ID');

            $table->string('url', 1000)
                ->comment('来源链接');

            $table->string('note', 255)
                ->nullable()
                ->comment('备注');

            $table->string('text', 500)
                ->nullable()
                ->comment('链接说明');

            $table->primary('id');

            // 根据作业查询所有来源链接
            $table->index(
                ['team_id'],
                'idx_team_links_team_id'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_links');
    }
};
