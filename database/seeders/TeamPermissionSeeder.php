<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeamPermissionSeeder extends Seeder
{
    /** Seed only team API permissions and preserve existing role grants. */
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'system:team:list',
                'display_name' => '作业列表查询',
                'http_method' => 'GET',
                'http_path' => 'api/admin/game/teams',
            ],
            [
                'name' => 'system:team:create',
                'display_name' => '新增作业',
                'http_method' => 'POST',
                'http_path' => 'api/admin/game/teams',
            ],
            [
                'name' => 'system:team:detail',
                'display_name' => '作业详情',
                'http_method' => 'GET',
                'http_path' => 'api/admin/game/teams/{team}',
            ],
            [
                'name' => 'system:team:update',
                'display_name' => '修改作业',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/game/teams/{team}',
            ],
            [
                'name' => 'system:team:delete',
                'display_name' => '隐藏作业',
                'http_method' => 'DELETE',
                'http_path' => 'api/admin/game/teams/{team}',
            ],
            [
                'name' => 'system:team:status',
                'display_name' => '恢复作业',
                'http_method' => 'PATCH',
                'http_path' => 'api/admin/game/teams/{team}/status',
            ],
            [
                'name' => 'system:team:role-options',
                'display_name' => '作业角色选项',
                'http_method' => 'GET',
                'http_path' => 'api/admin/game/roles/options',
            ],
            [
                'name' => 'system:team:d-stage-lineup-options',
                'display_name' => 'D阶段阵容选项',
                'http_method' => 'GET',
                'http_path' => 'api/admin/game/teams/d-stage-lineups/options',
            ],
            [
                'name' => 'system:team-link:update',
                'display_name' => '修改来源作业',
                'http_method' => 'PUT',
                'http_path' => 'api/admin/game/teams/{team}/links/{teamLink}',
            ],
            [
                'name' => 'system:team-link:delete',
                'display_name' => '隐藏来源作业',
                'http_method' => 'DELETE',
                'http_path' => 'api/admin/game/teams/{team}/links/{teamLink}',
            ],
            [
                'name' => 'system:team-link:status',
                'display_name' => '恢复来源作业',
                'http_method' => 'PATCH',
                'http_path' => 'api/admin/game/teams/{team}/links/{teamLink}/status',
            ],
        ];

        DB::transaction(function () use ($permissions): void {
            foreach ($permissions as $permission) {
                Permission::updateOrCreate(
                    ['name' => $permission['name']],
                    [
                        'display_name' => $permission['display_name'],
                        'http_method' => $permission['http_method'],
                        'http_path' => $permission['http_path'],
                    ]
                );
            }

            $superAdmin = Role::query()->where('code', 'super_admin')->first();
            if ($superAdmin) {
                $superAdmin->permissions()->syncWithoutDetaching(
                    Permission::query()->pluck('id')->all()
                );
            }

            $inheritPermissions = [
                'system:team:detail' => 'system:team:update',
                'system:team:status' => 'system:team:delete',
                'system:team:role-options' => 'system:team:create',
                'system:team:d-stage-lineup-options' => 'system:team:create',
                'system:team-link:update' => 'system:team:update',
                'system:team-link:delete' => 'system:team:delete',
                'system:team-link:status' => 'system:team:delete',
            ];

            foreach ($inheritPermissions as $targetName => $sourceName) {
                $target = Permission::query()->where('name', $targetName)->first();
                $source = Permission::query()->where('name', $sourceName)->first();

                if (!$target || !$source) {
                    continue;
                }

                $roleIds = $source->roles()->pluck('roles.id')->all();
                if ($roleIds !== []) {
                    $target->roles()->syncWithoutDetaching($roleIds);
                }
            }
        });
    }
}
