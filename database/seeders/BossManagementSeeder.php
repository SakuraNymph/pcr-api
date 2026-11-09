<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BossManagementSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $gameMenu = Menu::query()
                ->where('name', 'Game')
                ->where('type', 1)
                ->first();

            if (! $gameMenu) {
                throw new RuntimeException('未找到 Game 父级菜单');
            }

            $menu = Menu::updateOrCreate(
                ['name' => 'BossManagement'],
                [
                    'parent_id' => $gameMenu->id,
                    'type' => 2,
                    'path' => '/game/bosses',
                    'component' => '/game/bosses/index',
                    'redirect' => '',
                    'title' => 'BOSS 管理',
                    'icon' => 'lucide:crown',
                    'permission' => '',
                    'sort' => 1,
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
                    'name' => 'system:boss:list',
                    'display_name' => 'BOSS 管理数据查询',
                    'http_method' => 'GET',
                    'http_path' => 'api/admin/game/bosses',
                ],
                [
                    'name' => 'system:boss:select',
                    'display_name' => '切换本月或上月 BOSS',
                    'http_method' => 'PUT',
                    'http_path' => 'api/admin/game/bosses/select',
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
        });
    }
}
