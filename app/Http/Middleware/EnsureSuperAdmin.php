<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * 仅允许有效的超级管理员访问。
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $admin = $request->user();

        if (! ($admin instanceof Admin)) {
            return response()->json([
                'status' => 1,
                'code' => 403,
                'msg' => '当前接口仅允许管理员身份访问',
                'data' => [],
            ], 403);
        }

        if ((int) $admin->status !== 1) {
            return response()->json([
                'status' => 1,
                'code' => 403,
                'msg' => '管理员账号已停用，禁止访问',
                'data' => [],
            ], 403);
        }

        $isSuperAdmin = $admin->roles()
            ->where('code', 'super_admin')
            ->where('status', 1)
            ->exists();

        if (! $isSuperAdmin) {
            return response()->json([
                'status' => 1,
                'code' => 403,
                'msg' => '当前接口仅允许超级管理员访问',
                'data' => [],
            ], 403);
        }

        return $next($request);
    }
}
