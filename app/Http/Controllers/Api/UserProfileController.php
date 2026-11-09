<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Models\User;
use App\Services\UserProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserProfileService $userProfileService
    ) {}

    public function avatarOptions(): JsonResponse
    {
        return $this->success(
            $this->userProfileService->avatarOptions(),
            '获取头像选项成功'
        );
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        return $this->success(
            $this->userProfileService->updateProfile(
                $user,
                $request->validated()
            ),
            '个人资料更新成功'
        );
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        $this->userProfileService->updatePassword(
            $user,
            $request->validated()
        );

        return $this->success([], '密码修改成功');
    }
}
