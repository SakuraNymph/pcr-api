<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Game\SystemSixHitCurrentRequest;
use App\Http\Requests\Game\SystemSixHitPreviousMonthRequest;
use App\Services\SystemSixHitService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class SystemSixHitController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SystemSixHitService $systemSixHitService
    ) {}

    public function current(SystemSixHitCurrentRequest $request): JsonResponse
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $data = $this->systemSixHitService->getCurrentMonthGroups(
            $uid,
            (int) $request->validated('accountId'),
            array_map('intval', $request->validated('lowBosses') ?? []),
            array_map('intval', $request->validated('highBosses') ?? []),
            (int) ($request->validated('mode') ?? 1),
            (int) ($request->validated('stage') ?? 0),
            (int) ($request->validated('atkType') ?? 0),
            array_map('intval', $request->validated('lockedIds') ?? []),
            array_map('intval', $request->validated('hiddenIds') ?? [])
        );

        return $this->success($data, '获取系统六刀组合成功');
    }

    public function previousMonth(
        SystemSixHitPreviousMonthRequest $request
    ): JsonResponse {
        $data = $this->systemSixHitService->getPreviousMonthGroups(
            array_map('intval', $request->validated('lowBosses') ?? []),
            array_map('intval', $request->validated('highBosses') ?? []),
            (int) ($request->validated('mode') ?? 1),
            (int) ($request->validated('stage') ?? 0),
            (int) ($request->validated('atkType') ?? 0),
            array_map('intval', $request->validated('lockedIds') ?? []),
            array_map('intval', $request->validated('hiddenIds') ?? [])
        );

        return $this->success($data, '获取上月系统六刀组合成功');
    }
}
