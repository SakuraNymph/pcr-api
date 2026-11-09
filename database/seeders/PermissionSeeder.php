<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    /**
     * 初始化 API 权限
     */
    public function run(): void
    {
        $permissions = [
            // =========================
            // Dashboard
            // =========================
            [
                'name' => 'system:dashboard:analytics',
                'display_name' => '数据分析',
                'http_method' => 'GET',
                'http_path' => 'api/admin/analytics',
            ],
            [
                'name' => 'system:dashboard:trend',
                'display_name' => '数据趋势',
                'http_method' => 'GET',
                'http_path' => 'api/admin/trend',
            ],

            // =========================
            // 用户管理
            // =========================
            [
                'name' => 'system:user:list',
                'display_name' => '用户列表查询',
                'http_method' => 'GET',
                'http_path' => 'api/admin/users',
            ],
            [
                'name' => 'system:user:create',
                'display_name' => '创建用户',
                'http_method' => 'POST',
                'http_path' => 'api/admin/users',
            ],
            [
                'name' => 'system:user:update',
                'display_name' => '修改用户',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/users/{id}',
            ],
            [
                'name' => 'system:user:delete',
                'display_name' => '删除用户',
                'http_method' => 'DELETE',
                'http_path' => 'api/admin/users/{id}',
            ],

            // =========================
            // 管理员管理
            // =========================
            [
                'name' => 'system:admin:list',
                'display_name' => '管理员列表查询',
                'http_method' => 'GET',
                'http_path' => 'api/admin/admins',
            ],
            [
                'name' => 'system:admin:create',
                'display_name' => '创建管理员',
                'http_method' => 'POST',
                'http_path' => 'api/admin/admins',
            ],
            [
                'name' => 'system:admin:update',
                'display_name' => '修改管理员',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/admins/{admin}',
            ],
            [
                'name' => 'system:admin:delete',
                'display_name' => '删除管理员',
                'http_method' => 'DELETE',
                'http_path' => 'api/admin/admins/{admin}',
            ],
            [
                'name' => 'system:admin:status',
                'display_name' => '修改管理员状态',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/admins/{admin}/status',
            ],

            // =========================
            // 角色管理
            // =========================
            [
                'name' => 'system:role:list',
                'display_name' => '角色列表查询',
                'http_method' => 'GET',
                'http_path' => 'api/admin/roles',
            ],
            [
                'name' => 'system:role:create',
                'display_name' => '创建角色',
                'http_method' => 'POST',
                'http_path' => 'api/admin/roles',
            ],
            [
                'name' => 'system:role:update',
                'display_name' => '修改角色',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/roles/{role}',
            ],
            [
                'name' => 'system:role:delete',
                'display_name' => '删除角色',
                'http_method' => 'DELETE',
                'http_path' => 'api/admin/roles/{role}',
            ],

            // =========================
            // 角色 - 菜单权限
            // =========================
            [
                'name' => 'system:role:menu:list',
                'display_name' => '查询角色菜单权限',
                'http_method' => 'GET',
                'http_path' => 'api/admin/roles/{role}/menus',
            ],
            [
                'name' => 'system:role:menu:update',
                'display_name' => '修改角色菜单权限',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/roles/{role}/menus',
            ],

            // =========================
            // 角色 - API 权限
            // =========================
            [
                'name' => 'system:role:permission:list',
                'display_name' => '查询角色 API 权限',
                'http_method' => 'GET',
                'http_path' => 'api/admin/roles/{role}/permissions',
            ],
            [
                'name' => 'system:role:permission:update',
                'display_name' => '修改角色 API 权限',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/roles/{role}/permissions',
            ],

            // =========================
            // 管理员 - 角色
            // =========================
            [
                'name' => 'system:admin:role:list',
                'display_name' => '查询管理员角色',
                'http_method' => 'GET',
                'http_path' => 'api/admin/admins/{admin}/roles',
            ],
            [
                'name' => 'system:admin:role:update',
                'display_name' => '修改管理员角色',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/admins/{admin}/roles',
            ],

            // =========================
            // 菜单管理
            // =========================
            [
                'name' => 'system:menu:list',
                'display_name' => '菜单列表查询',
                'http_method' => 'GET',
                'http_path' => 'api/admin/menus_list',
            ],
            [
                'name' => 'system:menu:create',
                'display_name' => '创建菜单',
                'http_method' => 'POST',
                'http_path' => 'api/admin/menus',
            ],
            [
                'name' => 'system:menu:update',
                'display_name' => '修改菜单',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/menus/{menu}',
            ],
            [
                'name' => 'system:menu:delete',
                'display_name' => '删除菜单',
                'http_method' => 'DELETE',
                'http_path' => 'api/admin/menus',
            ],

            // =========================
            // API 权限管理
            // =========================
            [
                'name' => 'system:permission:list',
                'display_name' => 'API 权限列表查询',
                'http_method' => 'GET',
                'http_path' => 'api/admin/permissions',
            ],
            [
                'name' => 'system:permission:create',
                'display_name' => '创建 API 权限',
                'http_method' => 'POST',
                'http_path' => 'api/admin/permissions',
            ],
            [
                'name' => 'system:permission:update',
                'display_name' => '修改 API 权限',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/permissions/{id}',
            ],
            [
                'name' => 'system:permission:delete',
                'display_name' => '删除 API 权限',
                'http_method' => 'DELETE',
                'http_path' => 'api/admin/permissions/{id}',
            ],

            // =========================
            // 测试接口
            // =========================
            [
                'name' => 'system:test:list',
                'display_name' => '测试接口查询',
                'http_method' => 'GET',
                'http_path' => 'api/admin/test',
            ],
            [
                'name' => 'system:test:dynamic',
                'display_name' => '动态参数测试',
                'http_method' => 'GET',
                'http_path' => 'api/admin/test/{id}',
            ],
        ];

        DB::transaction(function () use ($permissions) {
            foreach ($permissions as $permission) {
                Permission::updateOrCreate(
                    [
                        'name' => $permission['name'],
                    ],
                    [
                        'display_name' => $permission['display_name'],
                        'http_method' => $permission['http_method'],
                        'http_path' => $permission['http_path'],
                    ]
                );
            }
        });
    }
}
