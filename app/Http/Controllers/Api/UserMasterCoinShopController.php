<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MasterCoinShopException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterCoinShop\UpdateUserMasterCoinShopRequest;
use App\Services\MasterCoinShopService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserMasterCoinShopController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly MasterCoinShopService $masterCoinShopService
    ) {
    }

    /**
     * 获取用户端全部系统大师币角色。
     */
    public function roles(): JsonResponse
    {
        return $this->success(
            $this->masterCoinShopService->userRoles(),
            '获取大师币角色成功'
        );
    }

    /**
     * 获取当前用户名下游戏账号的大师币配置。
     */
    public function show(int $id): JsonResponse
    {
        $userId = $this->uid();

        if ($userId === null) {
            return $this->error('当前用户身份无效', 403);
        }

        try {
            return $this->success(
                $this->masterCoinShopService->userRolePoolConfig($id, $userId),
                '获取大师币配置成功'
            );
        } catch (MasterCoinShopException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, '获取大师币配置失败');
        }
    }

    /**
     * 保存当前用户名下游戏账号的大师币配置。
     */
    public function update(
        UpdateUserMasterCoinShopRequest $request,
        int $id
    ): JsonResponse {
        $userId = $this->uid();

        if ($userId === null) {
            return $this->error('当前用户身份无效', 403);
        }

        try {
            return $this->success(
                $this->masterCoinShopService->updateUserRolePoolConfig(
                    $id,
                    $userId,
                    $request->validated('roles')
                ),
                '大师币配置保存成功'
            );
        } catch (MasterCoinShopException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, '保存大师币配置失败');
        }
    }

    private function unexpectedError(
        Throwable $exception,
        string $description
    ): JsonResponse {
        Log::error($description, [
            'exception' => $exception,
        ]);

        return $this->error('操作失败，请稍后重试', 500);
    }
}
