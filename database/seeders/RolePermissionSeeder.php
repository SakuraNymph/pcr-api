<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * 给三个角色分配全部 API 权限
     */
    public function run(): void
    {
        DB::transaction(function () {
            $permissionIds = Permission::query()
                ->pluck('id')
                ->toArray();

            $roleCodes = [
                'super_admin',
                'data_admin',
                'test_admin',
            ];

            $roles = Role::query()
                ->whereIn('code', $roleCodes)
                ->get();

            foreach ($roles as $role) {
                $role->permissions()->sync($permissionIds);
            }
        });
    }
}
