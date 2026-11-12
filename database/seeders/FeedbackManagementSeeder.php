<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FeedbackManagementSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $parentMenu = Menu::query()
                ->where('name', 'UserManagement')
                ->where('type', 1)
                ->first();

            if (! $parentMenu) {
                $parentMenu = Menu::query()
                    ->where('title', '用户管理')
                    ->where('type', 1)
                    ->first();
            }

            if (! $parentMenu) {
                throw new RuntimeException('未找到「用户管理」父级菜单，无法挂载意见反馈菜单');
            }

            $menu = Menu::updateOrCreate(
                ['name' => 'FeedbackManagement'],
                [
                    'parent_id' => $parentMenu->id,
                    'type' => 2,
                    'path' => '/user/feedback',
                    'component' => '/user/feedback/index',
                    'redirect' => '',
                    'title' => '意见反馈',
                    'icon' => 'lucide:message-square-text',
                    'permission' => '',
                    'sort' => 10,
                    'hidden' => 0,
                    'keep_alive' => 1,
                    'affix_tab' => 0,
                    'iframe_src' => '',
                    'link' => '',
                    'status' => 1,
                ]
            );

            $permissions = [
                [
                    'name' => 'system:feedback:list',
                    'display_name' => '意见反馈列表查询',
                    'http_method' => 'GET',
                    'http_path' => 'api/admin/feedback',
                ],
                [
                    'name' => 'system:feedback:statistics',
                    'display_name' => '意见反馈状态统计',
                    'http_method' => 'GET',
                    'http_path' => 'api/admin/feedback/statistics',
                ],
                [
                    'name' => 'system:feedback:detail',
                    'display_name' => '意见反馈详情查询',
                    'http_method' => 'GET',
                    'http_path' => 'api/admin/feedback/{id}',
                ],
                [
                    'name' => 'system:feedback:reply',
                    'display_name' => '意见反馈回复',
                    'http_method' => 'POST',
                    'http_path' => 'api/admin/feedback/{id}/messages',
                ],
                [
                    'name' => 'system:feedback:status',
                    'display_name' => '意见反馈状态修改',
                    'http_method' => 'PUT',
                    'http_path' => 'api/admin/feedback/{id}/status',
                ],
                [
                    'name' => 'system:feedback:read',
                    'display_name' => '意见反馈标记已读',
                    'http_method' => 'POST',
                    'http_path' => 'api/admin/feedback/{id}/read',
                ],
            ];

            $permissionIds = [];

            foreach ($permissions as $permission) {
                $model = Permission::updateOrCreate(
                    ['name' => $permission['name']],
                    [
                        'display_name' => $permission['display_name'],
                        'menu_id' => $menu->id,
                        'http_method' => $permission['http_method'],
                        'http_path' => $permission['http_path'],
                    ]
                );

                $permissionIds[] = $model->id;
            }

            $superAdmin = Role::query()
                ->where('code', 'super_admin')
                ->first();

            if ($superAdmin) {
                $superAdmin->menus()->syncWithoutDetaching([$menu->id]);
                $superAdmin->permissions()->syncWithoutDetaching($permissionIds);
            }

            // 确保其他管理员角色不持有意见反馈菜单与权限
            Role::query()
                ->whereIn('code', ['data_admin', 'test_admin'])
                ->get()
                ->each(function (Role $role) use ($menu, $permissionIds): void {
                    $role->menus()->detach($menu->id);
                    $role->permissions()->detach($permissionIds);
                });
        });
    }
}
