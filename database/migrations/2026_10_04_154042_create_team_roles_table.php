
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_roles', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->unsignedInteger('id')
                ->autoIncrement()
                ->comment('记录ID');

            $table->unsignedInteger('team_id')
                ->comment('作业ID');

            $table->unsignedInteger('role_id')
                ->comment('角色ID');

            $table->unsignedTinyInteger('status')
                ->default(1)
                ->comment('角色状态:0不可用;1可用');

            $table->primary('id');

            // 同一个作业中，同一个角色不能重复
            $table->unique(
                ['team_id', 'role_id'],
                'uk_team_roles_team_role'
            );

            // 根据作业查询角色
            $table->index(
                ['team_id'],
                'idx_team_roles_team_id'
            );

            // 根据角色查询使用过哪些作业
            $table->index(
                ['role_id'],
                'idx_team_roles_role_id'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_roles');
    }
};
