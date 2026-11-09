<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminListRequest;
use App\Http\Requests\Admin\DeleteAdminRequest;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Models\Admin;
use App\Services\SystemLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SystemLogService $systemLogService
    ) {
    }

    /**
     * 管理员登录
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required',
        ]);

        $admin = Admin::where('username', $request->username)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return $this->error('账号或密码错误', 422);
        }

        // $admin->tokens()->delete();

        $token = $admin->createToken('admin-token')->plainTextToken;

        $data = [
            'admin' => $admin,
            'accessToken' => $token,
        ];

        return $this->success($data);
    }

    /**
     * 获取管理员列表
     */
    public function index(AdminListRequest $request)
    {
        $username = $request->input('username');
        $nickname = $request->input('nickname');
        $status = $request->input('status');
        $roleId = $request->input('role_id');
        $createdStart = $request->input('created_start');
        $createdEnd = $request->input('created_end');

        $perPage = $request->input('per_page', 20);

        $query = Admin::with('roles')
            ->orderByDesc('id');

        if ($username !== null && $username !== '') {
            $query->where('username', 'like', '%' . $username . '%');
        }

        if ($nickname !== null && $nickname !== '') {
            $query->where('nickname', 'like', '%' . $nickname . '%');
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($roleId !== null && $roleId !== '') {
            $query->whereHas('roles', function ($roleQuery) use ($roleId) {
                $roleQuery->where('roles.id', $roleId);
            });
        }

        if ($createdStart !== null && $createdStart !== '') {
            $query->whereDate('created_at', '>=', $createdStart);
        }

        if ($createdEnd !== null && $createdEnd !== '') {
            $query->whereDate('created_at', '<=', $createdEnd);
        }

        $admins = $query->paginate($perPage);

        return $this->success($admins, '获取管理员列表成功');
    }

    /**
     * 创建管理员
     */
    public function store(StoreAdminRequest $request)
    {
        $data = $request->validated();

        $roleIds = $data['role_ids'];

        unset($data['role_ids']);

        $data['password'] = Hash::make($data['password']);

        $admin = DB::transaction(function () use ($data, $roleIds) {
            $admin = Admin::create($data);

            $admin->roles()->sync($roleIds);

            return $admin;
        });

        $admin = $admin->fresh()->load('roles');

        // 记录管理员创建日志
        $this->systemLogService->record(
            module: 'admin',
            action: 'create',
            targetType: 'Admin',
            targetId: $admin->id,
            description: '创建管理员',
            changes: [
                'roles' => [
                    'old' => [],
                    'new' => $admin->roles->pluck('id')->values()->toArray(),
                ],
            ],
        );

        return $this->success(
            $admin,
            '管理员创建成功'
        );
    }

    /**
     * 修改管理员
     */
    public function update(UpdateAdminRequest $request, Admin $admin)
    {
        $data = $request->validated();

        $roleIds = $data['role_ids'];

        unset($data['role_ids']);

        // 保存修改前的数据，用于生成操作日志
        $oldData = [
            'username' => $admin->username,
            'nickname' => $admin->nickname,
            'status' => $admin->status,
        ];

        // 获取修改前的角色 ID
        $oldRoleIds = $admin->roles()
            ->pluck('roles.id')
            ->sort()
            ->values()
            ->toArray();

        // 只有传递了新密码，才修改密码
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        DB::transaction(function () use ($admin, $data, $roleIds) {
            $admin->update($data);

            $admin->roles()->sync($roleIds);
        });

        $admin = $admin->fresh()->load('roles');

        // 构建数据变化
        $changes = [];

        if ($oldData['username'] !== $admin->username) {
            $changes['username'] = [
                'old' => $oldData['username'],
                'new' => $admin->username,
            ];
        }

        if ($oldData['nickname'] !== $admin->nickname) {
            $changes['nickname'] = [
                'old' => $oldData['nickname'],
                'new' => $admin->nickname,
            ];
        }

        if ((int) $oldData['status'] !== (int) $admin->status) {
            $changes['status'] = [
                'old' => $oldData['status'],
                'new' => $admin->status,
            ];
        }

        // 判断是否修改了密码
        if (array_key_exists('password', $request->validated())) {
            $changes['password'] = [
                'changed' => true,
            ];
        }

        // 获取修改后的角色 ID
        $newRoleIds = $admin->roles
            ->pluck('id')
            ->sort()
            ->values()
            ->toArray();

        if ($oldRoleIds !== $newRoleIds) {
            $changes['roles'] = [
                'old' => $oldRoleIds,
                'new' => $newRoleIds,
            ];
        }

        // 记录管理员修改日志
        $this->systemLogService->record(
            module: 'admin',
            action: 'update',
            targetType: 'Admin',
            targetId: $admin->id,
            description: '修改管理员',
            changes: $changes,
        );

        return $this->success(
            $admin,
            '管理员修改成功'
        );
    }

    /**
     * 删除管理员
     */
    public function destroy(DeleteAdminRequest $request, Admin $admin)
    {
        // 删除前保存管理员信息
        $adminId = $admin->id;
        $adminUsername = $admin->username;

        // 获取删除前的角色 ID
        $roleIds = $admin->roles()
            ->pluck('roles.id')
            ->sort()
            ->values()
            ->toArray();

        DB::transaction(function () use ($admin) {
            $admin->roles()->detach();

            $admin->delete();
        });

        // 记录管理员删除日志
        $this->systemLogService->record(
            module: 'admin',
            action: 'delete',
            targetType: 'Admin',
            targetId: $adminId,
            description: '删除管理员',
            changes: [
                'username' => [
                    'old' => $adminUsername,
                    'new' => null,
                ],
                'roles' => [
                    'old' => $roleIds,
                    'new' => [],
                ],
            ],
        );

        return $this->success([], '管理员删除成功');
    }

    /**
     * 修改管理员状态
     */
    public function updateStatus(Request $request, Admin $admin)
    {
        // 不允许修改当前登录管理员的状态
        if ($admin->id === $request->user()->id) {
            return $this->error('不能修改当前登录管理员的状态');
        }

        // 当前状态为1则改为0，当前状态为0则改为1
        $admin->status = $admin->status === 1 ? 0 : 1;
        $admin->save();

        return $this->success(
            $admin->fresh(),
            '管理员状态修改成功'
        );
    }

    /**
     * 获取当前登录管理员信息
     */
    public function info(Request $request)
    {
        $admin = $request->user();

        return $this->success($admin);
    }

    /**
     * 获取管理员权限代码
     */
    public function codes(Request $request)
    {
        $admin = $request->user();

        $permissions = $admin->roles()
            ->with('permissions')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->unique('id')
            ->values()
            ->pluck('name')
            ->values()
            ->toArray();

        return $this->success($permissions);
    }

    /**
     * 管理员退出登录
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success([], '已退出登录');
    }
}