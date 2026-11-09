<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PasswordResetException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ResetPasswordRequest;
use App\Http\Requests\User\VerifyPasswordResetCodeRequest;
use App\Models\User;
use App\Services\UserPasswordResetService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPasswordResetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserPasswordResetService $passwordResetService
    ) {}

    public function sendCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        try {
            $data = $this->passwordResetService->sendCode($user);
        } catch (PasswordResetException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        }

        return $this->success($data, '验证码已发送，请查收邮件');
    }

    public function verifyCode(
        VerifyPasswordResetCodeRequest $request
    ): JsonResponse {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        try {
            $data = $this->passwordResetService->verifyCode(
                $user,
                (string) $request->validated('code')
            );
        } catch (PasswordResetException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        }

        return $this->success($data, '验证成功，请设置新密码');
    }

    public function resetPassword(
        ResetPasswordRequest $request
    ): JsonResponse {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        try {
            $this->passwordResetService->resetPassword(
                $user,
                (string) $request->validated('reset_token'),
                (string) $request->validated('password')
            );
        } catch (PasswordResetException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        }

        return $this->success([], '密码重置成功');
    }
}
