<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guild\GuildInvitationCandidateRequest;
use App\Http\Requests\Guild\SendGuildInvitationRequest;
use App\Services\GuildInvitationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserGuildInvitationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GuildInvitationService $guildInvitationService
    ) {}

    public function candidate(
        GuildInvitationCandidateRequest $request,
        int $guild
    ): JsonResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildInvitationService->candidate(
                $guild,
                $uid,
                (int) $request->validated('uid')
            ),
            '查询邀请候选人成功'
        );
    }

    public function store(
        SendGuildInvitationRequest $request,
        int $guild
    ): JsonResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildInvitationService->send(
                $guild,
                $uid,
                (int) $request->validated('invitee_uid')
            ),
            '邀请发送成功'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:received,sent'],
        ]);
        $type = $validated['type'] ?? 'received';

        return $this->success(
            $this->guildInvitationService->list($uid, $type),
            '获取公会邀请成功'
        );
    }

    public function revoke(int $invitation): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $this->guildInvitationService->revoke($invitation, $uid);

        return $this->success(null, '邀请已撤销');
    }

    public function accept(int $invitation): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildInvitationService->accept($invitation, $uid),
            '已加入公会'
        );
    }

    public function reject(int $invitation): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $this->guildInvitationService->reject($invitation, $uid);

        return $this->success(null, '邀请已拒绝');
    }
}
