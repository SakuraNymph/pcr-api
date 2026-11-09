<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminIdentity
{
    /**
     * 验证当前请求是否属于管理员身份
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $admin = $request->user();

        // 必须是已认证的管理员模型
        if (! ($admin instanceof Admin)) {
            return response()->json([
                'status' => 1,
                'code' => 403,
                'msg' => '当前接口仅允许管理员身份访问',
                'data' => [],
            ], 403);
        }

        // 管理员账号必须处于启用状态
        if ((int) $admin->status !== 1) {
            return response()->json([
                'status' => 1,
                'code' => 403,
                'msg' => '管理员账号已停用，禁止访问',
                'data' => [],
            ], 403);
        }

        return $next($request);
    }
}