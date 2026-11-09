<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('public_uid')
                ->nullable()
                ->after('id')
                ->comment('对外展示的六位唯一数字 UID，仅正式用户有值');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('public_uid', 'users_public_uid_unique');
        });

        // 回填现有正式用户：随机六位数字，撞码时重试。
        $ids = DB::table('users')
            ->where('status', 1)
            ->whereNull('public_uid')
            ->pluck('id');

        foreach ($ids as $id) {
            $assigned = false;

            for ($attempt = 0; $attempt < 10; $attempt++) {
                $candidate = random_int(100000, 999999);

                try {
                    $affected = DB::table('users')
                        ->where('id', $id)
                        ->whereNull('public_uid')
                        ->update(['public_uid' => $candidate]);
                } catch (UniqueConstraintViolationException $exception) {
                    continue;
                }

                if ($affected === 1) {
                    $assigned = true;
                    break;
                }
            }

            if (! $assigned) {
                throw new \RuntimeException(
                    "迁移终止：users.id={$id} 连续 10 次分配 public_uid 均发生唯一键冲突"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_public_uid_unique');
            $table->dropColumn('public_uid');
        });
    }
};
