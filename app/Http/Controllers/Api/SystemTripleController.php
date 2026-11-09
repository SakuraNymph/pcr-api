<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemTripleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemTripleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SystemTripleService $systemTripleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond(
            $request,
            false,
            '获取系统三刀组合成功'
        );
    }

    public function previousMonth(Request $request): JsonResponse
    {
        return $this->respond(
            $request,
            true,
            '获取上月系统三刀组合成功'
        );
    }

    private function respond(
        Request $request,
        bool $previousMonth,
        string $message
    ): JsonResponse {
        [
            $mode,
            $bossMap,
            $atkType,
            $lockedIds,
            $hiddenIds,
            $accountId,
        ] = $this->validatedFilters($request, $previousMonth);

        $data = $accountId === null
            ? $this->systemTripleService->getGroups(
                $mode,
                $bossMap,
                $atkType,
                $lockedIds,
                $hiddenIds,
                $previousMonth
            )
            : $this->systemTripleService->getAccountGroups(
                (int) $request->user()->id,
                $accountId,
                $mode,
                $bossMap,
                $atkType,
                $lockedIds,
                $hiddenIds
            );

        return $this->success($data, $message);
    }

    /**
     * @return array{
     *     0: int,
     *     1: array<int, int>,
     *     2: int,
     *     3: array<int, int>,
     *     4: array<int, int>,
     *     5: int|null
     * }
     */
    private function validatedFilters(
        Request $request,
        bool $previousMonth
    ): array
    {
        $rules = [
            'row1' => ['nullable', 'integer'],
            'row2' => ['nullable', 'integer'],
            'row3' => ['nullable', 'integer'],
            'type' => ['nullable', 'integer', 'in:1,2'],
            'atk' => ['nullable', 'integer', 'between:0,4'],
            'lockedIds' => ['nullable', 'array'],
            'lockedIds.*' => ['integer'],
            'hiddenIds' => ['nullable', 'array'],
            'hiddenIds.*' => ['integer'],
        ];

        if (! $previousMonth) {
            $uid = (int) ($request->user()?->id ?? 0);
            $rules['accountId'] = [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('user_role_pools', 'id')->where(
                    static fn ($query) => $query
                        ->where('uid', $uid)
                        ->where('status', 1)
                ),
            ];
        }

        $validated = $request->validate($rules, [
            'accountId.integer' => '游戏账号必须是整数',
            'accountId.min' => '游戏账号参数无效',
            'accountId.exists' => '游戏账号不存在或不属于当前用户',
        ]);

        $bossMap = [];

        foreach (['row1', 'row2', 'row3'] as $row) {
            $boss = (int) ($validated[$row] ?? 0);
            $bossMap[] = in_array($boss, [1, 2, 3, 4, 5], true) ? $boss : 0;
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
            isset($validated['accountId'])
                ? (int) $validated['accountId']
                : null,
        ];
    }
}
