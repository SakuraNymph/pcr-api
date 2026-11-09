<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guild\CopyGuildScheduleRequest;
use App\Http\Requests\Guild\UpdateGuildScheduleMemberRequest;
use App\Services\GuildScheduleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserGuildScheduleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GuildScheduleService $guildScheduleService
    ) {}

    public function show(Request $request, int $guild): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $validated = $request->validate([
            'month' => [
                'nullable',
                'string',
                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
            ],
        ]);

        return $this->success(
            $this->guildScheduleService->show(
                $guild,
                $uid,
                $validated['month'] ?? null
            ),
            '获取公会排刀成功'
        );
    }

    public function update(
        UpdateGuildScheduleMemberRequest $request,
        int $guild,
        int $uid
    ): JsonResponse {
        $actorUid = $this->uid();
        if ($actorUid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildScheduleService->save(
                $guild,
                $actorUid,
                $uid,
                $request->validated()
            ),
            '排刀已保存'
        );
    }

    public function copy(
        CopyGuildScheduleRequest $request,
        int $guild
    ): JsonResponse {
        $actorUid = $this->uid();
        if ($actorUid === null) {
            return $this->error('请先登录', 401);
        }

        $validated = $request->validated();

        return $this->success(
            $this->guildScheduleService->copy(
                $guild,
                $actorUid,
                (int) $validated['source_uid'],
                (int) $validated['target_uid']
            ),
            '排刀复制成功'
        );
    }

    public function clear(Request $request, int $guild): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $validated = $request->validate([
            'month' => [
                'nullable',
                'string',
                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
            ],
        ]);

        return $this->success(
            $this->guildScheduleService->clear(
                $guild,
                $uid,
                $validated['month'] ?? null
            ),
            '本月公会排刀已清空'
        );
    }

    public function teamOptions(int $guild): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        return $this->success(
            $this->guildScheduleService->teamOptions($guild, $uid),
            '获取公会排刀作业选项成功'
        );
    }
}
