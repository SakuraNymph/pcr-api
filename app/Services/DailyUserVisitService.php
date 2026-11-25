<?php

namespace App\Services;

use App\Models\DailyUserVisit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DailyUserVisitService
{
    /**
     * 记录用户每日访问，每人每天一条。
     *
     * 游客（status = 0）与正式注册用户（status = 1）均计入。
     */
    public function record(User $user, ?string $ip): void
    {
        try {
            $today = now()->toDateString();
            $cacheKey = 'daily-visit:'.$user->getKey().':'.$today;

            // 命中缓存说明今天已经记录过，避免每个接口请求都写库。
            if (! Cache::add($cacheKey, true, now()->endOfDay())) {
                return;
            }

            DailyUserVisit::query()->insertOrIgnore([
                'user_id' => $user->getKey(),
                'visit_date' => $today,
                'ip' => $ip,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // 访问统计失败不应影响业务请求。
            Log::warning('记录每日访问失败', [
                'user_id' => $user->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
