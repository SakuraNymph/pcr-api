<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GuildNicknameSyncService
{
    /**
     * 昵称更新后同步当前月份快照。
     *
     * 个人资料更新不依赖公会模块，表不存在或同步失败时不阻断主流程。
     */
    public function syncCurrentMonth(int $uid, ?string $nickname): void
    {
        try {
            if (
                ! Schema::hasTable('guild_members')
                || ! Schema::hasTable('guild_months')
                || ! Schema::hasTable('guild_month_members')
            ) {
                return;
            }

            $guildIds = DB::table('guild_members')
                ->where('uid', $uid)
                ->where('status', 1)
                ->pluck('guild_id');

            if ($guildIds->isEmpty()) {
                return;
            }

            $monthIds = DB::table('guild_months')
                ->whereIn('guild_id', $guildIds)
                ->where('year_month', now()->format('Y-m'))
                ->pluck('id');

            if ($monthIds->isEmpty()) {
                return;
            }

            DB::table('guild_month_members')
                ->whereIn('month_id', $monthIds)
                ->where('uid', $uid)
                ->update([
                    'nickname' => $nickname,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            Log::warning('公会当前月昵称快照同步失败', [
                'uid' => $uid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
