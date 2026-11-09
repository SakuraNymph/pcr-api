<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserRolePool;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class UserRolePoolController extends Controller
{
    use ApiResponse;

    /**
     * 获取当前注册用户的角色池列表。
     */
    public function index()
    {
        $uid = $this->uid();

        $list = UserRolePool::query()
            ->where('uid', $uid)
            ->where('status', 1)
            ->orderBy('id')
            ->get([
                'id',
                'nickname',
                'roles',
                'created_at',
                'updated_at',
            ]);

        return $this->success($list);
    }

    /**
     * 创建角色池。
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nickname' => ['required', 'string', 'max:64'],
            'roles' => ['required', 'array'],
            'roles.*' => [
                'required',
                'integer',
                'distinct',
                'exists:game_roles,id',
            ],
        ]);

        $uid = $this->uid();

        $pool = UserRolePool::create([
            'uid' => $uid,
            'nickname' => $data['nickname'],
            'roles' => $data['roles'],
            'status' => 1,
        ]);

        return $this->success(
            $pool,
            '角色池创建成功'
        );
    }

    /**
     * 获取当前用户的角色池详情。
     */
    public function show(int $id)
    {
        $uid = $this->uid();

        $pool = UserRolePool::query()
            ->where('uid', $uid)
            ->where('status', 1)
            ->where('id', $id)
            ->firstOrFail();

        return $this->success($pool);
    }

    /**
     * 修改当前用户的角色池。
     *
     * nickname 和 roles 均可单独修改。
     */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'nickname' => ['sometimes', 'required', 'string', 'max:64'],
            'roles' => ['sometimes', 'required', 'array'],
            'roles.*' => [
                'required',
                'integer',
                'distinct',
                'exists:game_roles,id',
            ],
        ]);

        $uid = $this->uid();

        $pool = UserRolePool::query()
            ->where('uid', $uid)
            ->where('status', 1)
            ->where('id', $id)
            ->firstOrFail();

        $pool->update($data);

        return $this->success(
            $pool->fresh(),
            '角色池修改成功'
        );
    }

    /**
     * 软删除当前用户的角色池。
     */
    public function destroy(int $id)
    {
        $uid = $this->uid();

        $pool = UserRolePool::query()
            ->where('uid', $uid)
            ->where('status', 1)
            ->where('id', $id)
            ->firstOrFail();

        $pool->update([
            'status' => 0,
        ]);

        return $this->success(
            null,
            '角色池删除成功'
        );
    }
}
