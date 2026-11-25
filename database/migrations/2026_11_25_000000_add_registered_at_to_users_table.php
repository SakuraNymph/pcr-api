<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 新增独立的注册时间字段。
     *
     * created_at 表示用户（游客）首次创建时间，注册为正式用户时不会变化，
     * 因此不能用作注册时间统计。历史正式用户无法还原真实注册时刻，
     * 使用 created_at 作为近似值回填。
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('registered_at')->nullable()->after('status');
            $table->index('registered_at', 'users_registered_at_index');
        });

        DB::table('users')
            ->where('status', 1)
            ->whereNull('registered_at')
            ->update(['registered_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_registered_at_index');
            $table->dropColumn('registered_at');
        });
    }
};
