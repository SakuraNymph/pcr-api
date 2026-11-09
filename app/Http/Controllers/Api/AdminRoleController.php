<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncAdminRolesRequest;
use App\Models\Admin;
use App\Models\Role;
use App\Services\SystemLogService;
use App\Traits\ApiResponse;

class AdminRoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SystemLogService $systemLogService
    ) {
    }

    /**
     * 获取所有角色
     *
     * 用于给管理员分配角色时的角色列表
     */
    public function all()
    {
        $roles = Role::select(
            'id',
            'name',
            'code',
            'description',
            'sort',
            'status'
        )
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return $this->success($roles);
    }

    /**
     * 给指定管理员分配角色
     *
     * PUT /admin/admins/{admin}/roles
     */
    public function assignRoles(SyncAdminRolesRequest $request, Admin $admin)
    {
        // 获取修改前的角色 ID
        $oldRoleIds = $admin->roles()
            ->pluck('roles.id')
            ->sort()
            ->values()
            ->toArray();

        // 获取提交的新角色 ID
        $newRoleIds = collect($request->role_ids)
            ->map(fn ($roleId) => (int) $roleId)
            ->sort()
            ->values()
            ->toArray();

        // 同步角色
        $admin->roles()->sync($newRoleIds);

        // 只有角色确实发生变化时才记录日志
        if ($oldRoleIds !== $newRoleIds) {
            $this->systemLogService->record(
                module: 'admin',
                action: 'assign',
                targetType: 'Admin',
                targetId: $admin->id,
                description: '分配管理员角色',
                changes: [
                    'roles' => [
                        'old' => $oldRoleIds,
                        'new' => $newRoleIds,
                    ],
                ],
            );
        }

        return $this->success([], '角色分配成功');
    }

    /**
     * 获取指定管理员拥有的角色
     *
     * GET /admin/admins/{admin}/roles
     */
    public function getRoles(Admin $admin)
    {
        $roles = $admin->roles()
            ->select(
                'roles.id',
                'roles.name',
                'roles.code',
                'roles.description',
                'roles.sort',
                'roles.status'
            )
            ->orderBy('roles.sort')
            ->orderBy('roles.id')
            ->get();

        return $this->success($roles, '获取管理员角色成功');
    }
}