
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->unsignedInteger('id')
                ->autoIncrement()
                ->comment('作业ID');

            $table->unsignedTinyInteger('stage')
                ->comment('阶段:1=A阶段;2=B阶段;3=C阶段;4=D阶段;5=E阶段');

            $table->unsignedInteger('source_team_id')
                ->nullable()
                ->comment('复制来源作业ID，用户复制系统作业时记录原系统作业ID');

            $table->unsignedInteger('uid')
                ->default(0)
                ->comment('用户ID，0表示系统作业');

            $table->unsignedTinyInteger('boss')
                ->comment('Boss编号:1-5');

            $table->unsignedInteger('damage')
                ->comment('伤害，单位为万');

            $table->string('team_key', 100)
                ->comment('阵容指纹，角色按照search_area_width标准化后生成');

            $table->unsignedTinyInteger('status')
                ->default(1)
                ->comment('状态:0隐藏;1显示');

            $table->unsignedTinyInteger('mode')
                ->comment('攻击模式:1=自动;2=手动');

            $table->tinyInteger('atk_value')
                ->comment('队伍攻击属性:大于0物理;小于0魔法;0两者均可');

            $table->text('remark')
                ->nullable()
                ->comment('备注');

            $table->unsignedBigInteger('import_timestamp')
                ->nullable()
                ->comment('TXT导入批次时间戳');

            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('创建时间');

            $table->timestamp('updated_at')
                ->nullable()
                ->comment('更新时间');

            $table->primary('id');

            // 同一用户对同一条系统作业只能有一条个人作业记录
            $table->unique(
                ['uid', 'source_team_id'],
                'uk_teams_uid_source_team'
            );

            // 普通用户作业查询
            $table->index(
                ['uid', 'status'],
                'idx_teams_uid_status'
            );

            // 按阶段、Boss、模式查询
            $table->index(
                ['stage', 'boss', 'mode', 'status'],
                'idx_teams_stage_boss_auto_status'
            );

            // 按攻击属性筛选
            $table->index(
                ['stage', 'boss', 'mode', 'atk_value', 'status'],
                'idx_teams_stage_boss_auto_atk_status'
            );

            // 管理员导入时按阵容判断是否重复
            $table->index(
                ['stage', 'boss', 'mode', 'team_key'],
                'idx_teams_stage_boss_auto_team_key'
            );

            // 按创建时间查询公会战月份
            $table->index(
                ['created_at'],
                'idx_teams_created_at'
            );

            // TXT导入批次处理
            $table->index(
                ['import_timestamp'],
                'idx_teams_import_timestamp'
            );

            // 查询复制来源
            $table->index(
                ['source_team_id'],
                'idx_teams_source_team_id'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
