<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIdentity
{
    public function handle(
        Request $request,
        Closure $next,
        string $requiredStatus = 'registered'
    ): Response {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json([
                'status' => 1,
                'code' => 403,
                'msg' => '当前接口仅允许普通用户身份访问',
                'data' => [],
            ], 403);
        }

        // 游客和正式注册用户均可访问
        if ($requiredStatus === 'any') {
            if (! in_array((int) $user->status, [0, 1], true)) {
                return response()->json([
                    'status' => 1,
                    'code' => 403,
                    'msg' => '当前用户身份无效',
                    'data' => [],
                ], 403);
            }

            return $next($request);
        }

        // 仅允许游客
        if ($requiredStatus === 'guest') {
            if ((int) $user->status !== 0) {
                return response()->json([
                    'status' => 1,
                    'code' => 403,
                    'msg' => '当前用户不是游客，无法执行该操作',
                    'data' => [],
                ], 403);
            }

            return $next($request);
        }

        // 默认仅允许正式注册用户
        if ((int) $user->status !== 1) {
            return response()->json([
                'status' => 1,
                'code' => 401,
                'msg' => '请先完成用户注册并登录',
                'data' => [],
            ], 401);
        }

        return $next($request);
    }
}