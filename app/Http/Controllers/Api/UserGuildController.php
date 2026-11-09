<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guild\SearchGuildRequest;
use App\Http\Requests\Guild\StoreGuildRequest;
use App\Http\Requests\Guild\UpdateGuildRequest;
use App\Services\GuildService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserGuildController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GuildService $guildService
    ) {}

    public function index(SearchGuildRequest $request): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildService->search(
                (string) ($request->validated('keyword') ?? ''),
                (int) ($request->validated('page') ?? 1)
            ),
            '搜索公会成功'
        );
    }

    public function store(StoreGuildRequest $request): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildService->create($uid, $request->validated()),
            '公会创建成功'
        );
    }

    public function overview(): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildService->overview($uid),
            '获取公会概览成功'
        );
    }

    public function show(int $guild): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildService->show($guild, $uid),
            '获取公会详情成功'
        );
    }

    public function update(
        UpdateGuildRequest $request,
        int $guild
    ): JsonResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildService->update($guild, $uid, $request->validated()),
            '公会资料更新成功'
        );
    }

    public function destroy(int $guild): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $this->guildService->dissolve($guild, $uid);

        return $this->success(null, '公会已解散');
    }
}
