<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\DailyUserVisitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordDailyUserVisit
{
    /**
     * 记录游客与正式注册用户的每日访问，每人每天一条。
     *
     * 管理员等其他身份不计入。
     */
    public function __construct(
        private readonly DailyUserVisitService $dailyUserVisitService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && in_array((int) $user->status, [0, 1], true)
        ) {
            $this->dailyUserVisitService->record($user, $request->ip());
        }

        return $next($request);
    }
}
