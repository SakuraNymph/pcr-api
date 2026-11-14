<?php

namespace App\Http\Middleware;

use App\Models\DailyUserVisit;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordDailyUserVisit
{
    /**
     * 记录正式注册用户的每日访问，每人每天一条。
     *
     * 访客不计入：新项目不再为游客创建用户记录，游客访问数据没有归属。
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && (int) $user->status === 1) {
            $this->record($request, $user);
        }

        return $next($request);
    }

    private function record(Request $request, User $user): void
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
                'ip' => $request->ip(),
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
