<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemTripleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserTripleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SystemTripleService $systemTripleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        [
            $mode,
            $bossMap,
            $atkType,
            $lockedIds,
            $hiddenIds,
        ] = $this->validatedFilters($request);

        $data = $this->systemTripleService->getUserGroups(
            $uid,
            $mode,
            $bossMap,
            $atkType,
            $lockedIds,
            $hiddenIds
        );

        return $this->success($data, '获取用户三刀组合成功');
    }

    /**
     * @return array{
     *     0: int,
     *     1: array<int, int>,
     *     2: int,
     *     3: array<int, int>,
     *     4: array<int, int>
     * }
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'row1' => ['nullable', 'integer'],
            'row2' => ['nullable', 'integer'],
            'row3' => ['nullable', 'integer'],
            'type' => ['nullable', 'integer', 'in:1,2'],
            'atk' => ['nullable', 'integer', 'between:0,4'],
            'lockedIds' => ['nullable', 'array'],
            'lockedIds.*' => ['integer'],
            'hiddenIds' => ['nullable', 'array'],
            'hiddenIds.*' => ['integer'],
        ]);

        $bossMap = [];

        foreach (['row1', 'row2', 'row3'] as $row) {
            $boss = (int) ($validated[$row] ?? 0);
            $bossMap[] = in_array($boss, [1, 2, 3, 4, 5], true)
                ? $boss
                : 0;
        }

        $lockedIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $validated['lockedIds'] ?? []
        );
        $hiddenIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $validated['hiddenIds'] ?? []
        );

        return [
            (int) ($validated['type'] ?? 1),
            $bossMap,
            (int) ($validated['atk'] ?? 0),
            $lockedIds,
            $hiddenIds,
        ];
    }
}
