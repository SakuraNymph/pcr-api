<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\SyncRoleMenusRequest;
use App\Http\Requests\Role\SyncRolePermissionsRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\Role;
use App\Services\SystemLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SystemLogService $systemLogService
    ) {
    }

    /**
     * 角色列表
     */
    public function list(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return $this->success($roles);
    }

    /**
     * 新增角色
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = Role::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'status' => $data['status'] ?? 1,
        ]);

        // 记录角色创建日志
        $this->systemLogService->record(
            module: 'role',
            action: 'create',
            targetType: 'Role',
            targetId: $role->id,
            description: '创建角色',
            changes: [
                'name' => [
                    'old' => null,
                    'new' => $role->name,
                ],
                'code' => [
                    'old' => null,
                    'new' => $role->code,
                ],
                'description' => [
                    'old' => null,
                    'new' => $role->description,
                ],
                'sort' => [
                    'old' => null,
                    'new' => $role->sort,
                ],
                'status' => [
                    'old' => null,
                    'new' => $role->status,
                ],
            ],
        );

        return $this->success($role, '角色创建成功');
    }

    /**
     * 修改角色
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();

        // 保存修改前的数据
        $oldData = [
            'name' => $role->name,
            'code' => $role->code,
            'description' => $role->description,
            'sort' => $role->sort,
            'status' => $role->status,
        ];

        $role->update($data);

        $role = $role->fresh();

        // 记录实际发生变化的字段
        $changes = [];

        if ($oldData['name'] !== $role->name) {
            $changes['name'] = [
                'old' => $oldData['name'],
                'new' => $role->name,
            ];
        }

        if ($oldData['code'] !== $role->code) {
            $changes['code'] = [
                'old' => $oldData['code'],
                'new' => $role->code,
            ];
        }

        if ($oldData['description'] !== $role->description) {
            $changes['description'] = [
                'old' => $oldData['description'],
                'new' => $role->description,
            ];
        }

        if ((int) $oldData['sort'] !== (int) $role->sort) {
            $changes['sort'] = [
                'old' => $oldData['sort'],
                'new' => $role->sort,
            ];
        }

        if ((int) $oldData['status'] !== (int) $role->status) {
            $changes['status'] = [
                'old' => $oldData['status'],
                'new' => $role->status,
            ];
        }

        // 只有实际发生变化时才记录日志
        if (! empty($changes)) {
            $this->systemLogService->record(
                module: 'role',
                action: 'update',
                targetType: 'Role',
                targetId: $role->id,
                description: '修改角色',
                changes: $changes,
            );
        }

        return $this->success($role, '角色修改成功');
    }

    /**
     * 删除角色
     */
    public function destroy(Role $role): JsonResponse
    {
        // 保存删除前的信息
        $roleId = $role->id;
        $roleName = $role->name;
        $roleCode = $role->code;

        // 获取角色当前关联的菜单
        $menuIds = $role->menus()
            ->pluck('menus.id')
            ->sort()
            ->values()
            ->toArray();

        // 获取角色当前关联的 API 权限
        $permissionIds = $role->permissions()
            ->pluck('permissions.id')
            ->sort()
            ->values()
            ->toArray();

        // 1. 超级管理员角色禁止删除
        if ($role->code === 'super_admin') {
            $this->systemLogService->recordFailure(
                module: 'role',
                action: 'delete',
                targetType: 'Role',
                targetId: $roleId,
                description: '删除角色',
                errorMessage: '超级管理员角色不能删除',
            );

            return $this->error('超级管理员角色不能删除', 403);
        }

        // 2. 正在使用的角色禁止删除
        if ($role->admins()->exists()) {
            $this->systemLogService->recordFailure(
                module: 'role',
                action: 'delete',
                targetType: 'Role',
                targetId: $roleId,
                description: '删除角色',
                errorMessage: '该角色正在使用中，不能删除',
            );

            return $this->error('该角色正在使用中，不能删除', 400);
        }

        // 3. 删除角色及角色菜单、权限关联
        DB::transaction(function () use ($role) {
            // 删除角色与菜单的关联
            $role->menus()->detach();

            // 删除角色与 API 权限的关联
            $role->permissions()->detach();

            // 删除角色
            $role->delete();
        });

        // 记录角色删除日志
        $this->systemLogService->record(
            module: 'role',
            action: 'delete',
            targetType: 'Role',
            targetId: $roleId,
            description: '删除角色',
            changes: [
                'name' => [
                    'old' => $roleName,
                    'new' => null,
                ],
                'code' => [
                    'old' => $roleCode,
                    'new' => null,
                ],
                'menus' => [
                    'old' => $menuIds,
                    'new' => [],
                ],
                'permissions' => [
                    'old' => $permissionIds,
                    'new' => [],
                ],
            ],
        );

        return $this->success(null, '角色删除成功');
    }

    /**
     * 获取角色拥有的菜单
     */
    public function menus(Role $role): JsonResponse
    {
        $menus = $role->menus()
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return $this->success($menus, '获取角色菜单成功');
    }

    /**
     * 修改角色菜单
     */
    public function syncMenus(
        SyncRoleMenusRequest $request,
        Role $role
    ): JsonResponse {
        // 获取修改前的菜单 ID
        $oldMenuIds = $role->menus()
            ->pluck('menus.id')
            ->sort()
            ->values()
            ->toArray();

        // 获取新的菜单 ID
        $newMenuIds = collect($request->menu_ids)
            ->map(fn ($menuId) => (int) $menuId)
            ->sort()
            ->values()
            ->toArray();

        // 同步角色菜单
        $role->menus()->sync($newMenuIds);

        // 只有实际发生变化时才记录日志
        if ($oldMenuIds !== $newMenuIds) {
            $this->systemLogService->record(
                module: 'role',
                action: 'sync',
                targetType: 'Role',
                targetId: $role->id,
                description: '修改角色菜单',
                changes: [
                    'menus' => [
                        'old' => $oldMenuIds,
                        'new' => $newMenuIds,
                    ],
                ],
            );
        }

        return $this->success(null, '角色菜单修改成功');
    }

    /**
     * 获取角色拥有的 API 权限
     */
    public function permissions(Role $role): JsonResponse
    {
        $permissions = $role->permissions()
            ->orderBy('id')
            ->get();

        return $this->success($permissions, '获取角色权限成功');
    }

    /**
     * 修改角色 API 权限
     */
    public function syncPermissions(
        SyncRolePermissionsRequest $request,
        Role $role
    ): JsonResponse {
        // 获取修改前的权限 ID
        $oldPermissionIds = $role->permissions()
            ->pluck('permissions.id')
            ->sort()
            ->values()
            ->toArray();

        // 获取新的权限 ID
        $newPermissionIds = collect($request->permission_ids)
            ->map(fn ($permissionId) => (int) $permissionId)
            ->sort()
            ->values()
            ->toArray();

        // 同步角色 API 权限
        $role->permissions()->sync($newPermissionIds);

        // 只有实际发生变化时才记录日志
        if ($oldPermissionIds !== $newPermissionIds) {
            $this->systemLogService->record(
                module: 'role',
                action: 'sync',
                targetType: 'Role',
                targetId: $role->id,
                description: '修改角色 API 权限',
                changes: [
                    'permissions' => [
                        'old' => $oldPermissionIds,
                        'new' => $newPermissionIds,
                    ],
                ],
            );
        }

        return $this->success([], '角色权限修改成功');
    }
}