<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
        |--------------------------------------------------------------------------
        | 当前管理员
        |--------------------------------------------------------------------------
        */

        $admin = $request->user();

        if (!$admin) {
            return response()->json([
                'message' => '未登录',
            ], 401);
        }


        /*
        |--------------------------------------------------------------------------
        | OPTIONS 请求直接放行
        |--------------------------------------------------------------------------
        |
        | 浏览器 CORS 预检请求不进行权限检查。
        |
        */

        if ($request->method() === 'OPTIONS') {
            return $next($request);
        }


        /*
        |--------------------------------------------------------------------------
        | 当前路由
        |--------------------------------------------------------------------------
        */

        $route = $request->route();

        if (!$route) {
            return response()->json([
                'message' => '无法获取当前路由',
            ], 403);
        }


        /*
        |--------------------------------------------------------------------------
        | 当前请求信息
        |--------------------------------------------------------------------------
        */

        $httpMethod = strtoupper($request->method());

        /*
         * Laravel 当前项目中：
         *
         * $route->uri()
         *
         * 返回：
         *
         * api/admin/users
         * api/admin/users/{id}
         *
         * 因此这里和 permissions.http_path 使用相同格式。
         */
        $routePath = ltrim($route->uri(), '/');


        /*
        |--------------------------------------------------------------------------
        | 超级管理员直接放行
        |--------------------------------------------------------------------------
        */

        $isSuperAdmin = $admin->roles()
            ->where('code', 'super_admin')
            ->where('status', 1)
            ->exists();

        if ($isSuperAdmin) {
            return $next($request);
        }


        /*
        |--------------------------------------------------------------------------
        | 获取当前管理员所有角色的 API 权限
        |--------------------------------------------------------------------------
        */

        $permissions = $admin->roles()
            ->where('status', 1)
            ->with([
                'permissions' => function ($query) {
                    $query->whereNotNull('http_method')
                        ->whereNotNull('http_path');
                },
            ])
            ->get()
            ->flatMap(function ($role) {
                return $role->permissions;
            })
            ->unique('id');


        /*
        |--------------------------------------------------------------------------
        | 权限匹配
        |--------------------------------------------------------------------------
        |
        | 数据库：
        *
        * api/admin/users
        * api/admin/users/{id}
        *
        * Laravel 路由：
        *
        * api/admin/users
        * api/admin/users/{id}
        *
        * 两边统一去掉开头的 /
        * 然后直接进行比较。
        *
        */

        $hasPermission = $permissions->contains(function ($permission) use ($httpMethod, $routePath) {

            /*
             * 权限 HTTP 方法
             */
            $permissionMethod = strtoupper(trim($permission->http_method));

            /*
             * 权限路径
             */
            $permissionPath = trim($permission->http_path);

            /*
             * 只去掉开头的 /
             *
             * 例如：
             *
             * /api/admin/users
             *
             * 转换成：
             *
             * api/admin/users
             *
             * 注意：
             *
             * 这里不再删除 api/ 前缀。
             */
            $permissionPath = ltrim($permissionPath, '/');

            return $permissionMethod === $httpMethod
                && $permissionPath === $routePath;
        });


        /*
        |--------------------------------------------------------------------------
        | 权限检查日志
        |--------------------------------------------------------------------------
        */

        Log::info('API 权限检查', [
            'admin_id' => $admin->id,
            'username' => $admin->username,
            'method' => $httpMethod,
            'route_uri' => $routePath,
            'has_permission' => $hasPermission,
            'permission_ids' => $permissions->pluck('id')->values()->all(),
        ]);


        /*
        |--------------------------------------------------------------------------
        | 没有权限
        |--------------------------------------------------------------------------
        */

        if (!$hasPermission) {
            return response()->json([
                'message' => '没有权限访问该接口',
            ], 403);
        }


        /*
        |--------------------------------------------------------------------------
        | 有权限
        |--------------------------------------------------------------------------
        */

        return $next($request);
    }
}
