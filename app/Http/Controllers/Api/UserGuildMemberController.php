<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guild\TransferGuildOwnerRequest;
use App\Services\GuildService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserGuildMemberController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GuildService $guildService
    ) {}

    public function leave(int $guild): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $this->guildService->leave($guild, $uid);

        return $this->success(null, '已退出公会');
    }

    public function kick(int $guild, int $uid): JsonResponse
    {
        $actorUid = $this->uid();
        if ($actorUid === null) {
            return $this->error('请先登录', 401);
        }

        $this->guildService->kick($guild, $actorUid, $uid);

        return $this->success(null, '成员已移出公会');
    }

    public function appointVice(int $guild, int $uid): JsonResponse
    {
        $actorUid = $this->uid();
        if ($actorUid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildService->appointVice($guild, $actorUid, $uid),
            '副会长任命成功'
        );
    }

    public function revokeVice(int $guild, int $uid): JsonResponse
    {
        $actorUid = $this->uid();
        if ($actorUid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildService->revokeVice($guild, $actorUid, $uid),
            '副会长身份已撤销'
        );
    }

    public function transferOwner(
        TransferGuildOwnerRequest $request,
        int $guild
    ): JsonResponse {
        $actorUid = $this->uid();
        if ($actorUid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildService->transferOwner(
                $guild,
                $actorUid,
                (int) $request->validated('target_uid')
            ),
            '会长转让成功'
        );
    }
}
