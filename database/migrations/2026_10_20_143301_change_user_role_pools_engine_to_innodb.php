
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 将用户角色池表转换为 InnoDB。
     */
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `user_role_pools` ENGINE=InnoDB'
        );

        DB::statement(
            'ALTER TABLE `user_role_pools` ' .
            'CONVERT TO CHARACTER SET utf8mb4 ' .
            'COLLATE utf8mb4_unicode_ci'
        );
    }

    /**
     * 回滚时恢复为 MyISAM。
     */
    public function down(): void
    {
        DB::statement(
            'ALTER TABLE `user_role_pools` ENGINE=MyISAM'
        );
    }
};
