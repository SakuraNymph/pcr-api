<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GuildApplicationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserGuildApplicationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GuildApplicationService $guildApplicationService
    ) {}

    public function index(int $guild): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildApplicationService->list($guild, $uid),
            '获取加入申请成功'
        );
    }

    public function store(int $guild): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildApplicationService->create($guild, $uid),
            '申请已提交，请等待会长或副会长审核'
        );
    }

    public function approve(int $guild, int $application): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildApplicationService->approve(
                $guild,
                $uid,
                $application
            ),
            '已同意加入申请'
        );
    }

    public function reject(int $guild, int $application): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildApplicationService->reject(
                $guild,
                $uid,
                $application
            ),
            '已拒绝加入申请'
        );
    }

    public function withdraw(int $application): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $this->guildApplicationService->withdraw($application, $uid);

        return $this->success(null, '申请已撤销');
    }
}
