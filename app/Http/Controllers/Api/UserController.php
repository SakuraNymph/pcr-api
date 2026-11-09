<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    /**
     * 用户列表
     */
    public function index(Request $request)
    {
        // 每页数量
        $pageSize = min(
            100,
            max(1, (int) $request->input('pageSize', 20))
        );

        $query = User::query()
            ->select([
                'id',
                'public_uid',
                'ip',
                'country',
                'province',
                'city',
                'nickname',
                'status',
                'is_subscribe',
                'email',
                'created_at',
                'updated_at',
            ]);

        /*
         * 默认只查询已经注册的用户
         * status = 1：已注册
         */
        $query->where('status', 1);

        // 昵称
        if ($request->filled('nickname')) {
            $query->where(
                'nickname',
                'like',
                '%' . trim($request->input('nickname')) . '%'
            );
        }

        // 邮箱
        if ($request->filled('email')) {
            $query->where(
                'email',
                'like',
                '%' . trim($request->input('email')) . '%'
            );
        }

        // public_uid
        if ($request->filled('public_uid')) {
            $query->where(
                'public_uid',
                (int) $request->input('public_uid')
            );
        }

        // IP
        if ($request->filled('ip')) {
            $query->where(
                'ip',
                'like',
                '%' . trim($request->input('ip')) . '%'
            );
        }

        // 国家
        if ($request->filled('country')) {
            $query->where(
                'country',
                'like',
                '%' . trim($request->input('country')) . '%'
            );
        }

        // 省份
        if ($request->filled('province')) {
            $query->where(
                'province',
                'like',
                '%' . trim($request->input('province')) . '%'
            );
        }

        // 城市
        if ($request->filled('city')) {
            $query->where(
                'city',
                'like',
                '%' . trim($request->input('city')) . '%'
            );
        }

        // 是否订阅
        if (
            $request->input('is_subscribe') !== null &&
            $request->input('is_subscribe') !== ''
        ) {
            $query->where(
                'is_subscribe',
                (int) $request->input('is_subscribe')
            );
        }

        // 创建时间范围
        if ($request->filled('start_date')) {
            $query->whereDate(
                'created_at',
                '>=',
                $request->input('start_date')
            );
        }

        if ($request->filled('end_date')) {
            $query->whereDate(
                'created_at',
                '<=',
                $request->input('end_date')
            );
        }

        // 最新用户排在前面
        $query->orderByDesc('id');

        // 分页
        $users = $query->paginate($pageSize);

        return $this->success([
            'items' => $users->items(),
            'total' => $users->total(),
        ]);
    }
}
