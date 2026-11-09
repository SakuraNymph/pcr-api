<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MasterCoinShopSeeder extends Seeder
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
                ['name' => 'MasterCoinShop'],
                [
                    'parent_id' => $gameMenu->id,
                    'type' => 2,
                    'path' => '/game/master-coin-shop',
                    'component' => '/game/master-coin-shop/index',
                    'redirect' => '',
                    'title' => '大师币商店',
                    'icon' => 'lucide:store',
                    'permission' => '',
                    'sort' => 2,
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
                    'name' => 'system:master-coin-shop:list',
                    'display_name' => '大师币商店列表查询',
                    'http_method' => 'GET',
                    'http_path' => 'api/admin/game/master-coin-shop',
                ],
                [
                    'name' => 'system:master-coin-shop:search',
                    'display_name' => '大师币商店角色搜索',
                    'http_method' => 'GET',
                    'http_path' => 'api/admin/game/master-coin-shop/game-roles',
                ],
                [
                    'name' => 'system:master-coin-shop:create',
                    'display_name' => '添加大师币商店角色',
                    'http_method' => 'POST',
                    'http_path' => 'api/admin/game/master-coin-shop/roles',
                ],
                [
                    'name' => 'system:master-coin-shop:update',
                    'display_name' => '切换大师币商店角色分组',
                    'http_method' => 'PUT',
                    'http_path' => 'api/admin/game/master-coin-shop/roles/{gameRole}/type',
                ],
                [
                    'name' => 'system:master-coin-shop:sort',
                    'display_name' => '大师币商店组内排序',
                    'http_method' => 'PUT',
                    'http_path' => 'api/admin/game/master-coin-shop/groups/{type}/sort',
                ],
                [
                    'name' => 'system:master-coin-shop:delete',
                    'display_name' => '移除大师币商店角色',
                    'http_method' => 'DELETE',
                    'http_path' => 'api/admin/game/master-coin-shop/roles/{gameRole}',
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
