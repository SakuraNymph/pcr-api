<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PasswordResetException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ResetPasswordRequest;
use App\Http\Requests\User\SendPasswordResetCodeRequest;
use App\Http\Requests\User\VerifyPasswordResetCodeRequest;
use App\Models\User;
use App\Services\UserPasswordResetService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserPasswordResetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserPasswordResetService $passwordResetService
    ) {}

    public function sendCode(
        SendPasswordResetCodeRequest $request
    ): JsonResponse {
        try {
            $data = $this->passwordResetService->sendCode(
                (string) $request->validated('email')
            );
        } catch (PasswordResetException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        }

        return $this->success(
            $data,
            '如果该邮箱已注册，验证码将发送，请查收邮件'
        );
    }

    public function verifyCode(
        VerifyPasswordResetCodeRequest $request
    ): JsonResponse {
        try {
            $data = $this->passwordResetService->verifyCode(
                (string) $request->validated('email'),
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
        $currentUser = $request->user('sanctum');

        try {
            $this->passwordResetService->resetPassword(
                (string) $request->validated('reset_token'),
                (string) $request->validated('password'),
                $currentUser instanceof User ? $currentUser : null
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
