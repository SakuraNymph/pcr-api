<?php

namespace App\Services;

use App\Models\UserRolePool;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class SystemSixHitService
{
    private const ALGORITHM_VERSION = 8;

    private const RESULT_CACHE_TTL_SECONDS = 3600;

    private const CACHE_LOCK_SECONDS = 120;

    private const CACHE_LOCK_WAIT_SECONDS = 15;

    private const RESULT_CACHE_KEY_PREFIX = 'six-hit:final:v1:';

    private const RESULT_CACHE_LOCK_PREFIX = 'six-hit:lock:final:';

    public function __construct(
        private readonly SystemSixHitPlanner $systemSixHitPlanner,
        private readonly SystemTeamContextService $teamContext,
        private readonly SystemTeamSourceService $teamSource,
        private readonly SystemHitFilters $hitFilters,
        private readonly SystemTeamPresenter $teamPresenter
    ) {}

    /**
     * @param  array<int, int>  $lowBosses
     * @param  array<int, int>  $highBosses
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<string, mixed>>
     */
    public function getCurrentMonthGroups(
        int $uid,
        int $accountId,
        array $lowBosses = [],
        array $highBosses = [],
        int $difficulty = 0,
        int $stage = 0,
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = []
    ): array {
        $pool = UserRolePool::query()
            ->where('uid', $uid)
            ->where('status', 1)
            ->whereKey($accountId)
            ->firstOrFail();
        $roles = $this->normalizeRoleIds($pool->roles ?? []);
        $roleSet = array_fill_keys($roles, true);
        $yearMonth = $this->teamContext->monthRange(false)[2];

        return $this->getGroups(
            $yearMonth,
            $difficulty,
            $lowBosses,
            $highBosses,
            $stage,
            $atkType,
            SystemSixHitPlanner::ENDPOINT_CURRENT,
            $lockedIds,
            $hiddenIds,
            $uid,
            $accountId,
            $this->hashRoleIds($roles),
            $roleSet
        );
    }

    /**
     * @param  array<int, int>  $lowBosses
     * @param  array<int, int>  $highBosses
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<string, mixed>>
     */
    public function getPreviousMonthGroups(
        array $lowBosses = [],
        array $highBosses = [],
        int $difficulty = 0,
        int $stage = 0,
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = []
    ): array {
        $yearMonth = $this->teamContext->monthRange(true)[2];

        return $this->getGroups(
            $yearMonth,
            $difficulty,
            $lowBosses,
            $highBosses,
            $stage,
            $atkType,
            SystemSixHitPlanner::ENDPOINT_PREVIOUS,
            $lockedIds,
            $hiddenIds
        );
    }

    /**
     * @param  array<int, int>  $lowBosses
     * @param  array<int, int>  $highBosses
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @param  array<int, bool>|null  $accountRoles
     * @return array<int, array<string, mixed>>
     */
    private function getGroups(
        string $yearMonth,
        int $difficulty,
        array $lowBosses,
        array $highBosses,
        int $stage,
        int $atkType,
        int $endpoint,
        array $lockedIds = [],
        array $hiddenIds = [],
        ?int $uid = null,
        ?int $accountId = null,
        ?string $rolePoolHash = null,
        ?array $accountRoles = null
    ): array {
        $lowRows = $this->teamSource->lowRows($yearMonth);
        $highRows = $this->filterSourceRowsByDifficulty(
            $this->teamSource->highRows($yearMonth),
            $difficulty
        );
        $lowState = $this->teamContext->buildSnapshotStateFromRows(
            $lowRows,
            [2, 3]
        );
        $highState = $this->teamContext->buildSnapshotStateFromRows(
            $highRows,
            [4]
        );
        $snapshots = $lowState['snapshots'] + $highState['snapshots'];
        $lockedIds = $this->hitFilters->normalizeTeamIds($lockedIds);
        $hiddenIds = $this->hitFilters->normalizeTeamIds($hiddenIds);
        [$lowLockedIds, $highLockedIds] = $this->hitFilters->splitLockedIdsByStage(
            $lockedIds,
            $snapshots
        );
        $sourceSignature = $this->sourceSignature($lowState, $highState);
        $filters = $this->normalizeFilters(
            $lowBosses,
            $highBosses,
            $difficulty,
            $stage,
            $atkType,
            $lockedIds,
            $hiddenIds
        );
        $filterHash = $this->hitFilters->hash($filters);
        $cacheKey = $this->resultCacheKey(
            $yearMonth,
            $difficulty,
            $sourceSignature,
            $endpoint,
            $uid,
            $accountId,
            $rolePoolHash,
            $filterHash
        );
        $cached = $this->findResultCache($cacheKey);

        if ($cached !== null) {
            return $this->presentResults(
                $cached,
                $snapshots,
                $accountRoles
            );
        }

        $lock = Cache::lock(
            self::RESULT_CACHE_LOCK_PREFIX.$cacheKey,
            self::CACHE_LOCK_SECONDS
        );
        $locked = false;

        try {
            try {
                $lock->block(self::CACHE_LOCK_WAIT_SECONDS);
                $locked = true;
            } catch (LockTimeoutException) {
                $cached = $this->findResultCache($cacheKey);

                if ($cached !== null) {
                    return $this->presentResults(
                        $cached,
                        $snapshots,
                        $accountRoles
                    );
                }
            }

            $cached = $this->findResultCache($cacheKey);

            if ($cached !== null) {
                return $this->presentResults(
                    $cached,
                    $snapshots,
                    $accountRoles
                );
            }

            $results = $this->systemSixHitPlanner->plan(
                $lowState,
                $highState,
                $accountRoles,
                $stage,
                $atkType,
                $filters['lowBosses'],
                $filters['highBosses'],
                $lowLockedIds,
                $highLockedIds,
                $hiddenIds,
                $endpoint
            );

            if ($locked) {
                $this->storeResultCache($cacheKey, $results);
            }

            return $this->presentResults(
                $results,
                $snapshots,
                $accountRoles
            );
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    /**
     * D 阶段按难度筛选；B/C 阶段不参与难度过滤。
     *
     * @param  array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }  $rows
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }
     */
    private function filterSourceRowsByDifficulty(
        array $rows,
        int $difficulty
    ): array {
        if ($difficulty <= 0) {
            return $rows;
        }

        $teams = [];

        foreach ($rows['teams'] as $teamId => $team) {
            if ((int) ($team['stage'] ?? 0) !== 4) {
                $teams[$teamId] = $team;
                continue;
            }

            foreach (($rows['links'][$teamId] ?? []) as $link) {
                if ((int) ($link['difficulty'] ?? 0) === $difficulty) {
                    $teams[$teamId] = $team;
                    break;
                }
            }
        }

        $rows['teams'] = $teams;

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $lowState
     * @param  array<string, mixed>  $highState
     */
    private function sourceSignature(
        array $lowState,
        array $highState
    ): string {
        $createdAt = [];

        foreach ([
            $lowState['snapshots'],
            $highState['snapshots'],
        ] as $snapshots) {
            foreach ($snapshots as $teamId => $snapshot) {
                $createdAt[(int) $teamId] = $snapshot['created_at'] ?? null;
            }
        }

        ksort($createdAt, SORT_NUMERIC);

        return hash('sha256', json_encode([
            'algorithm_version' => self::ALGORITHM_VERSION,
            'low' => $lowState['signature'],
            'high' => $highState['signature'],
            'created_at' => $createdAt,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>|null  $accountRoles
     * @return array<int, array<string, mixed>>
     */
    private function presentResults(
        array $results,
        array $snapshots,
        ?array $accountRoles
    ): array {
        $presented = [];

        foreach ($results as $result) {
            $pairs = [];
            $borrows = $result['borrows'] ?? [];

            foreach ($result['pairs'] as $pair) {
                $lowId = (int) $pair[0];
                $highId = (int) $pair[1];
                $pairs[] = [
                    'low' => $this->teamPresenter->presentTeam(
                        $lowId,
                        $snapshots,
                        $borrows,
                        $accountRoles
                    ),
                    'high' => $this->teamPresenter->presentTeam(
                        $highId,
                        $snapshots,
                        $borrows,
                        $accountRoles
                    ),
                ];
            }

            $presented[] = [
                'score' => round(
                    ((int) $result['score_tenths']) / 10,
                    1
                ),
                'borrow_count' => (int) $result['borrow_count'],
                'overlap' => (int) $result['overlap'],
                'permutation_index' => (int) $result['permutation'],
                'pairs' => $pairs,
            ];
        }

        return $presented;
    }

    /**
     * @param  array<int, int>  $lowBosses
     * @param  array<int, int>  $highBosses
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<string, mixed>
     */
    private function normalizeFilters(
        array $lowBosses,
        array $highBosses,
        int $difficulty,
        int $stage,
        int $atkType,
        array $lockedIds,
        array $hiddenIds
    ): array {
        $lowBosses = $this->hitFilters->normalizeBosses($lowBosses);
        $highBosses = $this->hitFilters->normalizeBosses($highBosses);

        return [
            'lowBosses' => $lowBosses,
            'highBosses' => $highBosses,
            'difficulty' => $difficulty,
            'stage' => $stage,
            'atkType' => $atkType,
            'lockedIds' => $this->hitFilters->normalizeTeamIds($lockedIds),
            'hiddenIds' => $this->hitFilters->normalizeTeamIds($hiddenIds),
        ];
    }

    /**
     * 最终结果缓存 Key 必须覆盖所有影响计算结果的输入：
     * 年月、难度、数据签名、接口类型、用户、账号、角色池、筛选条件和算法版本。
     */
    private function resultCacheKey(
        string $yearMonth,
        int $difficulty,
        string $sourceSignature,
        int $endpoint,
        ?int $uid,
        ?int $accountId,
        ?string $rolePoolHash,
        string $filterHash
    ): string {
        return hash('sha256', json_encode([
            'year_month' => $yearMonth,
            'difficulty' => $difficulty,
            'source_signature' => $sourceSignature,
            'endpoint' => $endpoint,
            'uid' => $uid,
            'account_id' => $accountId,
            'role_pool_hash' => $rolePoolHash,
            'filter_hash' => $filterHash,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ], JSON_THROW_ON_ERROR));
    }

    private function resultCacheStoreKey(string $cacheKey): string
    {
        return self::RESULT_CACHE_KEY_PREFIX.$cacheKey;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function findResultCache(string $cacheKey): ?array
    {
        $cached = Cache::get($this->resultCacheStoreKey($cacheKey));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function storeResultCache(string $cacheKey, array $results): void
    {
        $now = now();

        Cache::put(
            $this->resultCacheStoreKey($cacheKey),
            $results,
            $now->copy()->addSeconds(self::RESULT_CACHE_TTL_SECONDS)
        );
    }

    /**
     * @param  array<int, int>  $roles
     * @return array<int, int>
     */
    private function normalizeRoleIds(array $roles): array
    {
        $roles = array_values(array_unique(array_map(
            static fn (mixed $roleId): int => (int) $roleId,
            $roles
        )));
        sort($roles, SORT_NUMERIC);

        return $roles;
    }

    /**
     * @param  array<int, int>  $roles
     */
    private function hashRoleIds(array $roles): string
    {
        return hash('sha256', json_encode($roles, JSON_THROW_ON_ERROR));
    }
}
