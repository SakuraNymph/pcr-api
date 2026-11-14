<?php

namespace App\Services;

use App\Models\Team;
use App\Models\UserRolePool;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use LogicException;

class SystemTripleService
{
    private const STAGE = 4;

    private const RESULT_LIMIT = 10;

    private const MULTIPLIER_RATES = [0, 4.5, 4.5, 4.7, 4.8, 5];

    private const FORMAL_SOURCE_CACHE_KEY = 'systemTripleTeams';

    private const FORMAL_MAKE_TEAMS_CACHE_KEY = 'systemTripleTeamsMakeTeams';

    private const PUBLIC_CACHE_TTL_DAYS = 30;

    private const FORMAL_CACHE_LOCK_SECONDS = 120;

    private const FORMAL_CACHE_LOCK_WAIT_SECONDS = 15;

    public function __construct(
        private readonly SystemTriplePlanner $planner,
        private readonly SystemTeamContextService $teamContext,
        private readonly SystemTeamSourceService $teamSource,
        private readonly SystemHitFilters $hitFilters,
        private readonly SystemTeamPresenter $teamPresenter
    ) {}

    /**
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getGroups(
        int $difficulty,
        array $bossMap = [],
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = [],
        bool $previousMonth = false
    ): array {
        return $this->getPublicGroups(
            $this->teamContext->monthRange($previousMonth)[2],
            $difficulty,
            $bossMap,
            $atkType,
            $lockedIds,
            $hiddenIds
        );
    }

    /**
     * 使用当前用户游戏账号的角色池筛选本月系统三刀。
     *
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getAccountGroups(
        int $uid,
        int $accountId,
        int $difficulty,
        array $bossMap = [],
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = []
    ): array {
        $pool = UserRolePool::query()
            ->where('uid', $uid)
            ->where('status', 1)
            ->whereKey($accountId)
            ->first();

        if ($pool === null) {
            throw ValidationException::withMessages([
                'accountId' => '游戏账号不存在或不属于当前用户',
            ]);
        }

        $difficulty = $this->normalizeDifficulty($difficulty);
        $context = $this->loadPublicContext(
            $this->teamContext->monthRange(false)[2]
        );
        $snapshots = $context['snapshots'];
        $combinations = $context['combinations'];
        $roleIds = $this->normalizeRoleIds($pool->roles ?? []);
        $accountRoles = array_fill_keys($roleIds, true);
        $normalizedLockedIds = $this->normalizeTeamIds($lockedIds);
        $normalizedHiddenIds = $this->normalizeTeamIds($hiddenIds);
        $results = $this->planner->matchAccount(
            $combinations,
            $context['groups'],
            $difficulty,
            $snapshots,
            $accountRoles,
            $bossMap,
            $atkType,
            $normalizedLockedIds,
            $normalizedHiddenIds
        );

        return $this->presentAccountResults(
            $results,
            $snapshots,
            $accountRoles
        );
    }

    /**
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function getPublicGroups(
        string $yearMonth,
        int $difficulty,
        array $bossMap,
        int $atkType,
        array $lockedIds,
        array $hiddenIds
    ): array {
        $difficulty = $this->normalizeDifficulty($difficulty);
        $context = $this->loadPublicContext($yearMonth);
        $combinations = $context['combinations'];
        $snapshots = $context['snapshots'];
        $teamIdsList = $this->planner->filterPublic(
            $combinations,
            $context['groups'],
            $difficulty,
            $bossMap,
            $atkType,
            $lockedIds,
            $hiddenIds
        );

        return array_map(
            fn (array $teamIds): array => $this->presentCombination(
                $teamIds,
                $snapshots
            ),
            $teamIdsList
        );
    }

    /**
     * 构建指定阶段的作业快照状态。
     *
     * @param  array<int, int>  $stages
     * @return array{
     *     snapshots: array<int, array<string, mixed>>,
     *     team_ids: string,
     *     team_count: int,
     *     signature: string
     * }
     */
    public function buildSnapshotState(
        Collection $teams,
        array $stages
    ): array {
        return $this->teamContext->buildSnapshotState($teams, $stages);
    }

    /**
     * 从已构建的快照状态生成现有三刀规则下的完整组合。
     *
     * @param  array{
     *     snapshots: array<int, array<string, mixed>>,
     *     team_ids: string,
     *     team_count: int,
     *     signature: string
     * }  $state
     * @return array<int, array<int, int>>
     */
    public function buildCombinationsForState(array $state): array
    {
        return $this->makeCombinations(array_values($state['snapshots']));
    }

    /**
     * 使用现有系统三刀规则构建指定阶段的完整候选集。
     *
     * @param  array<int, int>  $stages
     * @return array{
     *     snapshots: array<int, array<string, mixed>>,
     *     team_ids: string,
     *     team_count: int,
     *     signature: string,
     *     combinations: array<int, array<int, int>>
     * }
     */
    public function buildCandidateState(
        Collection $teams,
        array $stages
    ): array {
        $state = $this->buildSnapshotState($teams, $stages);
        $state['combinations'] = $this->buildCombinationsForState($state);

        return $state;
    }

    /**
     * 获取当前用户本月指定难度的三刀组合，实时计算且不使用缓存。
     *
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getUserGroups(
        int $uid,
        int $difficulty,
        array $bossMap = [],
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = [],
        int $mode = 0
    ): array {
        [$startOfMonth, $startOfNextMonth] = $this->teamContext->monthRange(
            false
        );
        $teams = $this->teamContext->queryTeams(
            $uid,
            [self::STAGE],
            $startOfMonth,
            $startOfNextMonth
        );

        if ($mode > 0) {
            $teams = $teams
                ->filter(
                    static fn (Team $team): bool => (int) $team->mode === $mode
                )
                ->values();
        }

        $sourceLinksByTeamId = $this->teamContext->queryUserSourceLinks(
            $teams
        );

        $snapshots = $teams
            ->mapWithKeys(function (Team $team) use ($sourceLinksByTeamId): array {
                $links = $team->source_team_id === null
                    ? $team->links
                    : $sourceLinksByTeamId->get(
                        (int) $team->source_team_id,
                        collect()
                    );

                return [
                    (int) $team->id => $this->teamContext->makeSnapshot(
                        $team,
                        $links
                    ),
                ];
            })
            ->all();

        if ($difficulty > 0) {
            $snapshots = array_filter(
                $snapshots,
                fn (array $snapshot): bool => $this->teamContext->snapshotHasDifficulty(
                    $snapshot,
                    $difficulty
                )
            );
        }

        $combinations = $this->makeCombinations(
            array_values($snapshots),
            true
        );
        $filtered = $this->filterCombinations(
            $combinations,
            $snapshots,
            $bossMap,
            $atkType,
            $lockedIds,
            $hiddenIds
        );

        return array_map(
            fn (array $teamIds): array => $this->presentCombination(
                $teamIds,
                $snapshots,
                true
            ),
            $filtered
        );
    }

    /**
     * @return array{
     *     year_month: string,
     *     snapshots: array<int, array<string, mixed>>,
     *     combinations: array<int, array<string, mixed>>,
     *     groups: array<int, array<int, int>>,
     *     stats: array<string, int>,
     *     signature: string
     * }
     */
    private function loadPublicContext(string $yearMonth): array
    {
        $sourceKey = $this->sourceCacheKey($yearMonth);
        $makeTeamsKey = $this->makeTeamsCacheKey($yearMonth);
        $lockKey = $this->lockCacheKey($yearMonth);
        $rows = $this->teamSource->highRows($yearMonth);
        $signature = $this->teamContext->formalSourceSignature($rows);
        $source = $this->decodeFormalSourcePayload(
            Cache::get($sourceKey),
            $yearMonth,
            $signature
        );
        $made = $this->planner->decodeFormalPayload(
            Cache::get($makeTeamsKey),
            $yearMonth,
            $signature
        );

        if ($source !== null && $made !== null) {
            return $this->formalContext(
                $yearMonth,
                $source,
                $made,
                $signature
            );
        }

        $lock = Cache::lock(
            $lockKey,
            self::FORMAL_CACHE_LOCK_SECONDS
        );
        $locked = false;

        try {
            try {
                $lock->block(self::FORMAL_CACHE_LOCK_WAIT_SECONDS);
                $locked = true;
            } catch (LockTimeoutException) {
                $source = $this->decodeFormalSourcePayload(
                    Cache::get($sourceKey),
                    $yearMonth,
                    $signature
                );
                $made = $this->planner->decodeFormalPayload(
                    Cache::get($makeTeamsKey),
                    $yearMonth,
                    $signature
                );

                if ($source !== null && $made !== null) {
                    return $this->formalContext(
                        $yearMonth,
                        $source,
                        $made,
                        $signature
                    );
                }

                $snapshots = $this->teamContext->buildFormalSnapshots($rows);
                $built = $this->planner->build(array_values($snapshots));

                return $this->formalContext(
                    $yearMonth,
                    $snapshots,
                    $built,
                    $signature
                );
            }

            $rows = $this->teamSource->highRows($yearMonth);
            $signature = $this->teamContext->formalSourceSignature($rows);
            $source = $this->decodeFormalSourcePayload(
                Cache::get($sourceKey),
                $yearMonth,
                $signature
            );
            $made = $this->planner->decodeFormalPayload(
                Cache::get($makeTeamsKey),
                $yearMonth,
                $signature
            );

            if ($source !== null && $made !== null) {
                return $this->formalContext(
                    $yearMonth,
                    $source,
                    $made,
                    $signature
                );
            }

            $snapshots = $source
                ?? $this->teamContext->buildFormalSnapshots($rows);
            $built = $this->planner->build(array_values($snapshots));
            $makePayload = $this->planner->buildFormalPayload(
                $built,
                $yearMonth,
                $signature
            );
            $makePayload['generated_at'] = now()->toIso8601String();
            $sourcePayload = $this->formalSourcePayload(
                $yearMonth,
                $signature,
                $snapshots
            );
            $expiresAt = now()->addDays(
                self::PUBLIC_CACHE_TTL_DAYS
            );

            Cache::put(
                $makeTeamsKey,
                $makePayload,
                $expiresAt
            );

            Cache::put(
                $sourceKey,
                $sourcePayload,
                $expiresAt
            );

            return $this->formalContext(
                $yearMonth,
                $snapshots,
                $built,
                $signature
            );
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array{
     *     combinations: array<int, array<string, mixed>>,
     *     groups: array<int, array<int, int>>,
     *     stats: array<string, int>
     * }  $built
     * @return array{
     *     year_month: string,
     *     snapshots: array<int, array<string, mixed>>,
     *     combinations: array<int, array<string, mixed>>,
     *     groups: array<int, array<int, int>>,
     *     stats: array<string, int>,
     *     signature: string
     * }
     */
    private function formalContext(
        string $yearMonth,
        array $snapshots,
        array $built,
        string $signature
    ): array {
        return [
            'year_month' => $yearMonth,
            'snapshots' => $snapshots,
            'combinations' => $built['combinations'],
            'groups' => $built['groups'],
            'stats' => $built['stats'],
            'signature' => $signature,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<string, mixed>
     */
    private function formalSourcePayload(
        string $yearMonth,
        string $signature,
        array $snapshots
    ): array {
        return [
            'version' => SystemTriplePlanner::CACHE_VERSION,
            'year_month' => $yearMonth,
            'signature' => $signature,
            'team_count' => count($snapshots),
            'snapshots' => array_values($snapshots),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function decodeFormalSourcePayload(
        mixed $payload,
        string $yearMonth,
        string $signature
    ): ?array {
        if (
            ! is_array($payload)
            || (int) ($payload['version'] ?? 0)
                !== SystemTriplePlanner::CACHE_VERSION
            || (string) ($payload['year_month'] ?? '') !== $yearMonth
            || (string) ($payload['signature'] ?? '') !== $signature
            || ! is_array($payload['snapshots'] ?? null)
        ) {
            return null;
        }

        $snapshots = [];

        foreach ($payload['snapshots'] as $snapshot) {
            if (
                ! is_array($snapshot)
                || ! isset($snapshot['id'])
                || ! is_array($snapshot['roles'] ?? null)
                || ! is_array($snapshot['links'] ?? null)
                || ! is_array($snapshot['role_details'] ?? null)
            ) {
                return null;
            }

            $snapshots[(int) $snapshot['id']] = $snapshot;
        }

        return $snapshots;
    }

    private function normalizeDifficulty(int $difficulty): int
    {
        return in_array($difficulty, [1, 2, 3, 4, 5], true)
            ? $difficulty
            : 1;
    }

    private function sourceCacheKey(string $yearMonth): string
    {
        return self::FORMAL_SOURCE_CACHE_KEY.':'.$yearMonth;
    }

    private function makeTeamsCacheKey(string $yearMonth): string
    {
        return self::FORMAL_MAKE_TEAMS_CACHE_KEY.':'.$yearMonth;
    }

    private function lockCacheKey(string $yearMonth): string
    {
        return self::FORMAL_SOURCE_CACHE_KEY.':'.$yearMonth.':build';
    }

    /**
     * @param  array<int, array{team_ids: array<int, int>, borrows: array<int, int>}>  $results
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>  $accountRoles
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function presentAccountResults(
        array $results,
        array $snapshots,
        array $accountRoles
    ): array {
        $presented = [];

        foreach ($results as $result) {
            $teamIds = array_values(array_map(
                static fn (mixed $teamId): int => (int) $teamId,
                $result['team_ids'] ?? []
            ));
            $borrows = [];

            foreach (($result['borrows'] ?? []) as $teamId => $roleId) {
                $borrows[(int) $teamId] = (int) $roleId;
            }

            if (
                count($teamIds) !== 3
                || array_diff($teamIds, array_keys($snapshots)) !== []
            ) {
                continue;
            }

            $presented[] = $this->presentCombination(
                $teamIds,
                $snapshots,
                false,
                $borrows,
                $accountRoles
            );
        }

        return $presented;
    }

    /**
     * @return array<int, int>
     */
    private function normalizeRoleIds(iterable $roleIds): array
    {
        $normalized = [];

        foreach ($roleIds as $roleId) {
            $roleId = (int) $roleId;

            if ($roleId > 0) {
                $normalized[$roleId] = $roleId;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * @param  iterable<int, int>  $teamIds
     * @return array<int, int>
     */
    private function normalizeTeamIds(iterable $teamIds): array
    {
        return $this->hitFilters->normalizeTeamIds($teamIds);
    }

    /**
     * @param  array<int, array<string, mixed>>  $teams
     * @return array<int, array<int, int>>
     */
    private function makeCombinations(
        array $teams,
        bool $strictBorrowRules = false
    ): array {
        $count = count($teams);
        $pairCompatible = [];

        for ($a = 0; $a < $count - 1; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                $pairCompatible[$a][$b] = $this->isPairCompatible(
                    $teams[$a],
                    $teams[$b],
                    $strictBorrowRules
                );
            }
        }

        $combinations = [];
        $seen = [];

        for ($a = 0; $a < $count - 2; $a++) {
            for ($b = $a + 1; $b < $count - 1; $b++) {
                if (! ($pairCompatible[$a][$b] ?? false)) {
                    continue;
                }

                for ($c = $b + 1; $c < $count; $c++) {
                    if (
                        ! ($pairCompatible[$a][$c] ?? false)
                        || ! ($pairCompatible[$b][$c] ?? false)
                    ) {
                        continue;
                    }

                    $group = [$teams[$a], $teams[$b], $teams[$c]];

                    if (! $this->isRoleOverlapAllowed(
                        $group,
                        $strictBorrowRules
                    )) {
                        continue;
                    }

                    $teamIds = [
                        (int) $teams[$a]['id'],
                        (int) $teams[$b]['id'],
                        (int) $teams[$c]['id'],
                    ];
                    $canonicalIds = $teamIds;
                    sort($canonicalIds);
                    $key = implode(',', $canonicalIds);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $groupById = [];

                    foreach ($group as $team) {
                        $groupById[$team['id']] = $team;
                    }

                    usort($teamIds, function (int $left, int $right) use ($groupById): int {
                        $leftBoss = (int) $groupById[$left]['boss'];
                        $rightBoss = (int) $groupById[$right]['boss'];

                        return $leftBoss <=> $rightBoss;
                    });

                    $score = 0.0;

                    foreach ($teamIds as $teamId) {
                        $boss = (int) $groupById[$teamId]['boss'];
                        $score += (int) $groupById[$teamId]['damage']
                            * (self::MULTIPLIER_RATES[$boss] ?? 0);
                    }

                    $combinations[] = [
                        'team_ids' => $teamIds,
                        'score' => $score,
                    ];
                }
            }
        }

        usort($combinations, function (array $left, array $right): int {
            return $right['score'] <=> $left['score'];
        });

        return array_map(
            static fn (array $combination): array => $combination['team_ids'],
            $combinations
        );
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function isPairCompatible(
        array $left,
        array $right,
        bool $strictBorrowRules = false
    ): bool {
        if ($strictBorrowRules) {
            return $this->findStrictBorrowAssignment(
                [$left, $right]
            ) !== null;
        }

        return $left['inactive_count']
            + $right['inactive_count']
            + $this->countSharedActiveRoles($left, $right) <= 2;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function countSharedActiveRoles(array $left, array $right): int
    {
        if (count($left['active_roles']) <= count($right['active_roles'])) {
            $smaller = $left['active_roles'];
            $larger = $right['active_roles'];
        } else {
            $smaller = $right['active_roles'];
            $larger = $left['active_roles'];
        }

        $shared = 0;

        foreach ($smaller as $roleId => $_) {
            if (isset($larger[$roleId])) {
                $shared++;
            }
        }

        return $shared;
    }

    /**
     * @param  array<int, array<string, mixed>>  $group
     */
    private function isRoleOverlapAllowed(
        array $group,
        bool $strictBorrowRules = false
    ): bool {
        if ($strictBorrowRules) {
            return $this->findStrictBorrowAssignment($group) !== null;
        }

        $roleCounts = [];
        $inactiveCount = 0;

        foreach ($group as $team) {
            $inactiveCount += $team['inactive_count'];

            foreach ($team['roles'] as $roleId => $_) {
                $roleCounts[$roleId] = ($roleCounts[$roleId] ?? 0) + 1;
            }
        }

        $threeTeamRoles = 0;
        $twoTeamRoles = 0;

        foreach ($roleCounts as $count) {
            if ($count === 3) {
                $threeTeamRoles++;
            } elseif ($count === 2) {
                $twoTeamRoles++;
            }
        }

        if ($threeTeamRoles >= 2) {
            return false;
        }

        $borrowCost = $inactiveCount
            + ($threeTeamRoles === 1 ? 2 : 0)
            + $twoTeamRoles;

        return $borrowCost <= 3;
    }

    /**
     * @param  array<int, array<int, int>>  $combinations
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<int, int>>
     */
    private function filterCombinations(
        array $combinations,
        array $snapshots,
        array $bossMap,
        int $atkType,
        array $lockedIds,
        array $hiddenIds
    ): array {
        $selectedBosses = $this->hitFilters->normalizeBosses($bossMap);

        $result = [];

        foreach ($combinations as $teamIds) {
            if (
                $lockedIds
                && count(array_intersect($teamIds, $lockedIds)) < count($lockedIds)
            ) {
                continue;
            }

            if ($hiddenIds && array_intersect($teamIds, $hiddenIds)) {
                continue;
            }

            $comboBosses = [];
            $physicalCount = 0;
            $magicCount = 0;

            foreach ($teamIds as $teamId) {
                $team = $snapshots[$teamId];
                $comboBosses[] = $team['boss'];

                if ($team['atk_value'] >= 0) {
                    $physicalCount++;
                }

                if ($team['atk_value'] <= 0) {
                    $magicCount++;
                }
            }

            if (! $this->hitFilters->passesAttackFilter(
                $atkType,
                $physicalCount,
                $magicCount
            )) {
                continue;
            }

            if (! $this->hitFilters->passesBossFilter(
                $comboBosses,
                $selectedBosses
            )) {
                continue;
            }

            $result[] = $teamIds;

            if (count($result) >= self::RESULT_LIMIT) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $teamIds
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, int>|null  $borrows
     * @param  array<int, bool>|null  $accountRoles
     * @return array<int, array<string, mixed>>
     */
    private function presentCombination(
        array $teamIds,
        array $snapshots,
        bool $strictBorrowRules = false,
        ?array $borrows = null,
        ?array $accountRoles = null
    ): array {
        $borrows ??= $strictBorrowRules
            ? $this->assignStrictBorrow($teamIds, $snapshots)
            : $this->assignBorrow($teamIds, $snapshots);
        return $this->teamPresenter->presentTeams(
            $teamIds,
            $snapshots,
            $borrows,
            $accountRoles
        );
    }

    /**
     * 优先保留现有借用分配；不满足用户三刀严格规则时重新寻找。
     *
     * @param  array<int, int>  $teamIds
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, int>
     */
    private function assignStrictBorrow(
        array $teamIds,
        array $snapshots
    ): array {
        $group = array_map(
            static fn (int $teamId): array => $snapshots[$teamId],
            $teamIds
        );
        $legacyBorrows = $this->assignBorrow($teamIds, $snapshots);

        if ($this->isStrictBorrowAssignmentValid(
            $group,
            array_values($legacyBorrows)
        )) {
            return $legacyBorrows;
        }

        $assignment = $this->findStrictBorrowAssignment($group);

        if ($assignment === null) {
            throw new LogicException('用户三刀组合未通过借用校验');
        }

        $borrows = [];

        foreach ($teamIds as $index => $teamId) {
            $borrows[$teamId] = (int) $assignment[$index];
        }

        return $borrows;
    }

    /**
     * 在内存中寻找满足严格借用规则的一组分配。
     *
     * @param  array<int, array<string, mixed>>  $group
     * @return array<int, int>|null
     */
    private function findStrictBorrowAssignment(array $group): ?array
    {
        $candidateLists = [];

        foreach ($group as $team) {
            $candidateLists[] = array_merge(
                [0],
                array_map('intval', array_keys($team['roles']))
            );
        }

        $assignment = array_fill(0, count($group), 0);
        $search = function (int $index) use (
            &$search,
            &$assignment,
            $candidateLists,
            $group
        ): ?array {
            if ($index === count($group)) {
                return $this->isStrictBorrowAssignmentValid(
                    $group,
                    $assignment
                )
                    ? $assignment
                    : null;
            }

            foreach ($candidateLists[$index] as $roleId) {
                $assignment[$index] = (int) $roleId;
                $result = $search($index + 1);

                if ($result !== null) {
                    return $result;
                }
            }

            return null;
        };

        return $search(0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $group
     * @param  array<int, int>  $assignment
     */
    private function isStrictBorrowAssignmentValid(
        array $group,
        array $assignment
    ): bool {
        $roleOccurrences = [];

        foreach ($group as $teamIndex => $team) {
            $borrow = (int) ($assignment[$teamIndex] ?? 0);
            $mandatoryRoles = [];

            foreach ($team['roles'] as $roleId => $status) {
                $roleId = (int) $roleId;
                $status = (int) $status;

                if ($status === 0) {
                    $mandatoryRoles[] = $roleId;
                }

                $roleOccurrences[$roleId][] = [
                    'team_index' => $teamIndex,
                    'status' => $status,
                ];
            }

            if (count($mandatoryRoles) > 1) {
                return false;
            }

            if ($mandatoryRoles !== []) {
                if ($borrow !== $mandatoryRoles[0]) {
                    return false;
                }
            } elseif ($borrow !== 0 && ! isset($team['roles'][$borrow])) {
                return false;
            }
        }

        foreach ($roleOccurrences as $roleId => $occurrences) {
            $activeCount = 0;
            $normalCount = 0;

            foreach ($occurrences as $occurrence) {
                if ($occurrence['status'] !== 0) {
                    $activeCount++;
                }

                if (
                    (int) ($assignment[$occurrence['team_index']] ?? 0)
                    !== (int) $roleId
                ) {
                    $normalCount++;
                }
            }

            $requiredNormalCount = $activeCount > 0 ? 1 : 0;

            if ($normalCount !== $requiredNormalCount) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int>  $teamIds
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, int>
     */
    private function assignBorrow(array $teamIds, array $snapshots): array
    {
        $borrows = array_fill_keys($teamIds, 0);
        $statusZeroGroups = [];

        foreach ($teamIds as $teamId) {
            foreach ($snapshots[$teamId]['roles'] as $roleId => $status) {
                if ((int) $status === 0) {
                    $borrows[$teamId] = (int) $roleId;
                    $statusZeroGroups[$teamId] = true;
                    break;
                }
            }
        }

        $intersections = [];

        for ($a = 0; $a < 3; $a++) {
            for ($b = $a + 1; $b < 3; $b++) {
                $rolesA = array_keys($snapshots[$teamIds[$a]]['roles']);
                $rolesB = array_keys($snapshots[$teamIds[$b]]['roles']);
                $shared = array_values(array_intersect($rolesA, $rolesB));
                $intersections[$a][$b] = $shared;
                $intersections[$b][$a] = $shared;
            }
        }

        $assignPair = function (int $a, int $b) use (
            &$borrows,
            &$intersections,
            $teamIds
        ): void {
            $teamA = $teamIds[$a];
            $teamB = $teamIds[$b];

            if ($borrows[$teamA] !== 0 && $borrows[$teamB] !== 0) {
                return;
            }

            $sameRoles = $intersections[$a][$b];

            if (! $sameRoles) {
                return;
            }

            if ($borrows[$teamA] !== 0 && $borrows[$teamB] === 0) {
                $sameRoles = array_values(array_filter(
                    $sameRoles,
                    static fn (int $roleId): bool => $roleId !== $borrows[$teamA]
                ));

                if ($sameRoles) {
                    $borrows[$teamB] = (int) reset($sameRoles);
                }

                return;
            }

            if ($borrows[$teamA] === 0 && $borrows[$teamB] !== 0) {
                $sameRoles = array_values(array_filter(
                    $sameRoles,
                    static fn (int $roleId): bool => $roleId !== $borrows[$teamB]
                ));

                if ($sameRoles) {
                    $borrows[$teamA] = (int) reset($sameRoles);
                }

                return;
            }

            $borrows[$teamA] = (int) reset($sameRoles);
            $secondRole = next($sameRoles);
            $borrows[$teamB] = $secondRole === false ? 0 : (int) $secondRole;
        };

        $twoSharedPair = null;

        for ($a = 0; $a < 2; $a++) {
            for ($b = $a + 1; $b < 3; $b++) {
                if (count($intersections[$a][$b]) === 2) {
                    $twoSharedPair = [$a, $b];
                    break 2;
                }
            }
        }

        if ($twoSharedPair !== null) {
            $third = 3 - $twoSharedPair[0] - $twoSharedPair[1];
            $assignPair($twoSharedPair[0], $twoSharedPair[1]);
            $assignPair($twoSharedPair[0], $third);
            $assignPair($twoSharedPair[1], $third);
        } elseif ($statusZeroGroups) {
            $key = array_key_first($statusZeroGroups);
            $keyIndex = array_search($key, $teamIds, true);
            $others = array_values(array_filter(
                [0, 1, 2],
                static fn (int $index): bool => $index !== $keyIndex
            ));
            $assignPair($keyIndex, $others[0]);
            $assignPair($keyIndex, $others[1]);
            $assignPair($others[0], $others[1]);
        } else {
            $assignPair(0, 1);
            $assignPair(0, 2);
            $assignPair(1, 2);
        }

        return $borrows;
    }
}
