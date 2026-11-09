<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MasterCoinShopException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterCoinShop\SortMasterCoinShopRolesRequest;
use App\Http\Requests\MasterCoinShop\StoreMasterCoinShopRoleRequest;
use App\Http\Requests\MasterCoinShop\UpdateMasterCoinShopRoleTypeRequest;
use App\Services\MasterCoinShopService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MasterCoinShopController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly MasterCoinShopService $masterCoinShopService
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->success(
            $this->masterCoinShopService->overview(),
            '获取大师币商店配置成功'
        );
    }

    public function searchGameRoles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => [
                'required',
                'string',
                'max:100',
            ],
        ], [
            'keyword.required' => '请输入角色名称或昵称',
            'keyword.string' => '搜索关键词必须是字符串',
            'keyword.max' => '搜索关键词不能超过100个字符',
        ]);

        return $this->success(
            $this->masterCoinShopService->searchGameRoles(
                trim($validated['keyword'])
            ),
            '搜索游戏角色成功'
        );
    }

    public function store(StoreMasterCoinShopRoleRequest $request): JsonResponse
    {
        try {
            $shopRole = $this->masterCoinShopService->add(
                (int) $request->validated('game_role_id'),
                (int) $request->validated('type')
            );

            return $this->success($shopRole, '角色已加入大师币商店');
        } catch (MasterCoinShopException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, '添加大师币商店角色失败');
        }
    }

    public function updateType(
        UpdateMasterCoinShopRoleTypeRequest $request,
        int $gameRole
    ): JsonResponse {
        try {
            $shopRole = $this->masterCoinShopService->move(
                $gameRole,
                (int) $request->validated('type')
            );

            return $this->success($shopRole, '角色商店分组切换成功');
        } catch (MasterCoinShopException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, '切换大师币商店角色分组失败');
        }
    }

    public function sortGroup(
        SortMasterCoinShopRolesRequest $request,
        int $type
    ): JsonResponse {
        try {
            $overview = $this->masterCoinShopService->sortGroup(
                $type,
                $request->validated('game_role_ids')
            );

            return $this->success($overview, '分组排序保存成功');
        } catch (MasterCoinShopException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, '保存大师币商店分组排序失败');
        }
    }

    public function destroy(int $gameRole): JsonResponse
    {
        try {
            $this->masterCoinShopService->remove($gameRole);

            return $this->success([], '角色已移出大师币商店');
        } catch (MasterCoinShopException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->statusCode()
            );
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, '移除大师币商店角色失败');
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
