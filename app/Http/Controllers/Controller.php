<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * 获取当前普通用户 UID
     *
     * 游客和正式用户均属于 User 模型。
     * 当前身份不是普通用户时返回 null。
     */
    protected function uid(): ?int
    {
        $user = request()->user('sanctum');

        if (! ($user instanceof User)) {
            return null;
        }

        return (int) $user->id;
    }

    /**
     * 获取当前管理员 ID
     *
     * 当前身份不是管理员时返回 null。
     */
    protected function adminId(): ?int
    {
        $admin = request()->user('sanctum');

        if (! ($admin instanceof Admin)) {
            return null;
        }

        return (int) $admin->id;
    }
}