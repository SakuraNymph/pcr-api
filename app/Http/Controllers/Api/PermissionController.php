<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Models\Permission;
use App\Services\SystemLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SystemLogService $systemLogService
    ) {
    }

    /**
     * 获取权限列表
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::orderBy('id')->get();

        return $this->success($permissions, '获取权限列表成功');
    }

    /**
     * 创建权限
     */
    public function store(StorePermissionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $permission = Permission::create($data);

        $this->systemLogService->record(
            module: 'permission',
            action: 'create',
            targetType: 'Permission',
            targetId: $permission->id,
            description: '创建 API 权限',
            changes: [
                'name' => [
                    'old' => null,
                    'new' => $permission->name,
                ],
                'description' => [
                    'old' => null,
                    'new' => $permission->description,
                ],
            ],
        );

        return $this->success($permission, '权限创建成功');
    }

    /**
     * 修改权限
     */
    public function update(
        UpdatePermissionRequest $request,
        $id
    ): JsonResponse {
        $permission = Permission::findOrFail($id);

        $data = $request->validated();

        // 保存修改前的数据
        $oldData = [
            'name' => $permission->name,
            'description' => $permission->description,
        ];

        $permission->update($data);

        $permission = $permission->fresh();

        // 记录真正发生变化的字段
        $changes = [];

        if ($oldData['name'] !== $permission->name) {
            $changes['name'] = [
                'old' => $oldData['name'],
                'new' => $permission->name,
            ];
        }

        if ($oldData['description'] !== $permission->description) {
            $changes['description'] = [
                'old' => $oldData['description'],
                'new' => $permission->description,
            ];
        }

        // 只有实际发生变化时才记录日志
        if (! empty($changes)) {
            $this->systemLogService->record(
                module: 'permission',
                action: 'update',
                targetType: 'Permission',
                targetId: $permission->id,
                description: '修改 API 权限',
                changes: $changes,
            );
        }

        return $this->success(
            $permission,
            '权限修改成功'
        );
    }

    /**
     * 删除权限
     */
    public function destroy($id): JsonResponse
    {
        $permission = Permission::findOrFail($id);

        // 保存删除前的信息
        $permissionId = $permission->id;
        $permissionName = $permission->name;
        $permissionDescription = $permission->description;

        // 正在使用的权限禁止删除
        if ($permission->roles()->exists()) {
            $this->systemLogService->recordFailure(
                module: 'permission',
                action: 'delete',
                targetType: 'Permission',
                targetId: $permissionId,
                description: '删除 API 权限',
                errorMessage: '该权限正在使用中，不能删除',
            );

            return $this->error(
                '该权限正在使用中，不能删除',
                400
            );
        }

        $permission->delete();

        // 记录删除日志
        $this->systemLogService->record(
            module: 'permission',
            action: 'delete',
            targetType: 'Permission',
            targetId: $permissionId,
            description: '删除 API 权限',
            changes: [
                'name' => [
                    'old' => $permissionName,
                    'new' => null,
                ],
                'description' => [
                    'old' => $permissionDescription,
                    'new' => null,
                ],
            ],
        );

        return $this->success(null, '权限删除成功');
    }
}