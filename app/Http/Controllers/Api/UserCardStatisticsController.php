<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CardStatistics\GetCardStatisticsRolesRequest;
use App\Http\Requests\CardStatistics\UpdateCardStatisticsRequest;
use App\Models\CardStatistic;
use App\Models\UserRolePool;
use App\Services\CardLuckEvaluationService;
use App\Services\CardStatisticsRoleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserCardStatisticsController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CardStatisticsRoleService $roleService,
        protected CardLuckEvaluationService $evaluationService
    ) {
    }

    public function roles(
        GetCardStatisticsRolesRequest $request
    ): JsonResponse {
        $account = $this->accountForUser(
            $request,
            (int) $request->validated('account_id')
        );

        if (! $account) {
            return $this->accountNotFound();
        }

        $allRoles = $this->roleService->roles();
        $availableYears = $this->roleService->availableYears($allRoles);
        $year = $request->validated('year');
        $year = $year === null
            ? ($availableYears[0] ?? (int) now()->year)
            : (int) $year;

        $yearRoles = $this->roleService->rolesForYear($allRoles, $year);
        $statistic = CardStatistic::query()
            ->where('uid', $request->user()->getKey())
            ->where('account_id', $account->id)
            ->where('year', $year)
            ->first();
        $savedData = $this->normalizeStoredData($statistic?->data);

        $roles = array_map(
            fn (array $role): array => $this->presentRole(
                $role,
                array_key_exists($role['id'], $savedData)
                    ? $savedData[$role['id']]
                    : null
            ),
            $yearRoles
        );

        return $this->success([
            'account' => [
                'id' => (int) $account->id,
                'nickname' => (string) $account->nickname,
            ],
            'available_years' => $availableYears,
            'year' => $year,
            'roles' => $roles,
        ], '获取抽卡统计角色成功');
    }

    public function update(
        UpdateCardStatisticsRequest $request
    ): JsonResponse {
        $account = $this->accountForUser(
            $request,
            (int) $request->validated('account_id')
        );

        if (! $account) {
            return $this->accountNotFound();
        }

        $year = (int) $request->validated('year');
        $allRoles = $this->roleService->roles();
        $yearRoles = $this->roleService->rolesForYear($allRoles, $year);
        $allowedRoles = [];

        foreach ($yearRoles as $role) {
            $allowedRoles[$role['id']] = $role;
        }

        $submittedData = $this->normalizeSubmittedData(
            $request->validated('data')
        );

        $invalidIds = array_values(array_diff(
            array_keys($submittedData),
            array_keys($allowedRoles)
        ));

        if ($invalidIds !== []) {
            throw ValidationException::withMessages([
                'data' => '包含不属于当前年份可记录抽卡角色的数据',
            ]);
        }

        $statistic = CardStatistic::query()
            ->where('uid', $request->user()->getKey())
            ->where('account_id', $account->id)
            ->where('year', $year)
            ->first();
        $existingData = $this->normalizeStoredData($statistic?->data);
        $storedData = [];

        // 当前角色列表不可见的历史记录无法在表单中编辑，保存时原样保留。
        foreach ($existingData as $roleId => $count) {
            if (! array_key_exists($roleId, $allowedRoles)) {
                $storedData[] = [
                    'id' => $roleId,
                    'count' => $count,
                ];
            }
        }

        foreach ($yearRoles as $role) {
            if (array_key_exists($role['id'], $submittedData)) {
                $storedData[] = [
                    'id' => $role['id'],
                    'count' => $submittedData[$role['id']],
                ];
            }
        }

        $jsonData = json_encode(
            $storedData,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $now = now();

        DB::transaction(function () use (
            $request,
            $account,
            $year,
            $jsonData,
            $now
        ): void {
            DB::table('card_statistics')->upsert(
                [[
                    'uid' => (int) $request->user()->getKey(),
                    'account_id' => (int) $account->id,
                    'year' => $year,
                    'data' => $jsonData,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['uid', 'account_id', 'year'],
                ['data', 'updated_at']
            );
        });

        $report = [];

        foreach ($yearRoles as $role) {
            if (! array_key_exists($role['id'], $submittedData)) {
                continue;
            }

            $report[] = $this->presentReportRole(
                $role,
                $submittedData[$role['id']]
            );
        }

        return $this->success([
            'account_id' => (int) $account->id,
            'year' => $year,
            'data' => $storedData,
            'report' => $report,
        ], '保存抽卡统计成功');
    }

    private function accountForUser(
        Request $request,
        int $accountId
    ): ?UserRolePool {
        return UserRolePool::query()
            ->where('uid', $request->user()->getKey())
            ->where('status', 1)
            ->whereKey($accountId)
            ->first();
    }

    private function accountNotFound(): JsonResponse
    {
        return $this->error('游戏账号不存在或不属于当前用户', 404);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeStoredData(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $normalized = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }

            $roleId = $item['id'] ?? null;
            $count = $item['count'] ?? null;

            if (
                ! is_int($roleId)
                || $roleId < 1
                || ! is_int($count)
                || $count < 0
                || $count > 200
            ) {
                continue;
            }

            $normalized[$roleId] = $count;
        }

        return $normalized;
    }

    /**
     * @param array<int, array{id: int, count: int}> $data
     * @return array<int, int>
     */
    private function normalizeSubmittedData(array $data): array
    {
        $normalized = [];

        foreach ($data as $item) {
            $normalized[(int) $item['id']] = (int) $item['count'];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRole(array $role, ?int $count): array
    {
        return [
            'id' => $role['id'],
            'image_id' => $role['image_id'],
            'image_url' => $this->roleService->imageUrl($role['image_id']),
            'name' => $role['name'],
            'probability' => $role['probability'],
            'up_time' => $role['up_time'],
            'year' => $role['year'],
            'saved_count' => $count,
            'evaluation' => $count === null
                ? null
                : $this->evaluationService->evaluate(
                    $count,
                    $role['probability']
                ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentReportRole(array $role, int $count): array
    {
        return [
            'id' => $role['id'],
            'image_id' => $role['image_id'],
            'image_url' => $this->roleService->imageUrl($role['image_id']),
            'name' => $role['name'],
            'probability' => $role['probability'],
            'up_time' => $role['up_time'],
            'year' => $role['year'],
            'count' => $count,
            'evaluation' => $this->evaluationService->evaluate(
                $count,
                $role['probability']
            ),
        ];
    }
}
