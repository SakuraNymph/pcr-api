<?php

namespace App\Services;

use App\Models\SystemTripleCache;
use App\Models\SystemTripleAccountCache;
use App\Models\Team;
use App\Models\TeamLink;
use App\Models\TeamRole;
use App\Models\UserRolePool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class SystemTripleService
{
    private const STAGE = 4;

    private const RESULT_LIMIT = 10;

    private const ACCOUNT_ALGORITHM_VERSION = 1;

    private const MULTIPLIER_RATES = [0, 4.5, 4.5, 4.7, 4.8, 5];

    /**
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getGroups(
        int $mode,
        array $bossMap = [],
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = [],
        bool $previousMonth = false
    ): array {
        $context = $this->loadCombinationCache($mode, $previousMonth);
        $snapshots = $context['snapshots'];
        $combinations = $context['combinations'];

        $filtered = $this->filterCombinations(
            $combinations,
            $snapshots,
            $bossMap,
            $atkType,
            $lockedIds,
            $hiddenIds
        );

        return array_map(
            fn (array $teamIds): array => $this->presentCombination($teamIds, $snapshots),
            $filtered
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
        int $mode,
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

        $context = $this->loadCombinationCache($mode, false);
        $snapshots = $context['snapshots'];
        $combinations = $context['combinations'];
        $roleIds = $this->normalizeRoleIds($pool->roles ?? []);
        $accountRoles = array_fill_keys($roleIds, true);
        $normalizedLockedIds = $this->normalizeTeamIds($lockedIds);
        $normalizedHiddenIds = $this->normalizeTeamIds($hiddenIds);
        $filters = [
            'mode' => $mode,
            'boss_map' => array_map(
                static fn (mixed $boss): int => (int) $boss,
                $bossMap
            ),
            'atk_type' => $atkType,
            'locked_ids' => $normalizedLockedIds,
            'hidden_ids' => $normalizedHiddenIds,
        ];
        $filterHash = $this->hashArray($filters);
        $rolePoolHash = $this->hashArray($roleIds);
        $cacheAttributes = [
            'year_month' => $context['year_month'],
            'mode' => $mode,
            'algorithm_version' => self::ACCOUNT_ALGORITHM_VERSION,
            'source_signature' => $context['signature'],
            'uid' => $uid,
            'account_id' => $accountId,
            'role_pool_hash' => $rolePoolHash,
            'filter_hash' => $filterHash,
        ];
        $cached = SystemTripleAccountCache::query()
            ->where($cacheAttributes)
            ->first();

        if ($cached !== null) {
            return $this->presentAccountResults(
                $cached->results,
                $snapshots,
                $accountRoles
            );
        }

        $results = $this->filterAccountCombinations(
            $combinations,
            $snapshots,
            $accountRoles,
            $bossMap,
            $atkType,
            $normalizedLockedIds,
            $normalizedHiddenIds
        );

        DB::table('system_triple_account_caches')->insertOrIgnore([
            ...$cacheAttributes,
            'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
            'results' => json_encode($results, JSON_THROW_ON_ERROR),
            'result_count' => count($results),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->presentAccountResults(
            $results,
            $snapshots,
            $accountRoles
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
        $normalizedStages = array_values(array_unique(array_map(
            static fn (mixed $stage): int => (int) $stage,
            $stages
        )));
        $filtered = $teams
            ->filter(
                static fn (Team $team): bool => in_array(
                    (int) $team->stage,
                    $normalizedStages,
                    true
                )
            )
            ->values();

        return $this->makeCacheState($filtered);
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
     * 获取当前用户本月指定模式的三刀组合，实时计算且不使用缓存。
     *
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getUserGroups(
        int $uid,
        int $mode,
        array $bossMap = [],
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = []
    ): array {
        $startOfMonth = now()->startOfMonth();
        $startOfNextMonth = $startOfMonth
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth();

        $teams = $this->queryUserTeams(
            $uid,
            $mode,
            $startOfMonth,
            $startOfNextMonth
        );
        $sourceLinksByTeamId = $this->queryUserSourceLinks($teams);

        $snapshots = $teams
            ->mapWithKeys(function (Team $team) use ($sourceLinksByTeamId): array {
                $links = $team->source_team_id === null
                    ? $team->links
                    : $sourceLinksByTeamId->get(
                        (int) $team->source_team_id,
                        collect()
                    );

                return [
                    (int) $team->id => $this->makeSnapshot($team, $links),
                ];
            })
            ->all();

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
     *     combinations: array<int, array<int, int>>,
     *     signature: string
     * }
     */
    private function loadCombinationCache(
        int $mode,
        bool $previousMonth
    ): array {
        $currentMonthStart = now()->startOfMonth();
        $startOfMonth = $previousMonth
            ? $currentMonthStart
                ->copy()
                ->subMonthNoOverflow()
                ->startOfMonth()
            : $currentMonthStart->copy();
        $startOfNextMonth = $startOfMonth
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth();
        $yearMonth = $startOfMonth->format('Ym');

        $teams = $this->queryTeams($mode, $startOfMonth, $startOfNextMonth);
        $state = $this->makeCacheState($teams);
        $cache = SystemTripleCache::query()
            ->where('year_month', $yearMonth)
            ->where('mode', $mode)
            ->first();

        if ($cache !== null && $this->cacheMatches($cache, $state)) {
            return [
                'year_month' => $yearMonth,
                'snapshots' => $state['snapshots'],
                'combinations' => $cache->combinations,
                'signature' => $state['signature'],
            ];
        }

        [$snapshots, $combinations, $signature] = DB::transaction(
            function () use (
                $mode,
                $yearMonth,
                $startOfMonth,
                $startOfNextMonth
            ): array {
                $cache = $this->lockCache($yearMonth, $mode);
                $teams = $this->queryTeams(
                    $mode,
                    $startOfMonth,
                    $startOfNextMonth
                );
                $state = $this->makeCacheState($teams);

                if ($this->cacheMatches($cache, $state)) {
                    return [
                        $state['snapshots'],
                        $cache->combinations,
                        $state['signature'],
                    ];
                }

                $combinations = $this->makeCombinations(
                    array_values($state['snapshots'])
                );

                $cache->forceFill([
                    'team_ids' => $state['team_ids'],
                    'team_count' => $state['team_count'],
                    'signature' => $state['signature'],
                    'combinations' => $combinations,
                ])->save();

                return [
                    $state['snapshots'],
                    $combinations,
                    $state['signature'],
                ];
            },
            3
        );

        return [
            'year_month' => $yearMonth,
            'snapshots' => $snapshots,
            'combinations' => $combinations,
            'signature' => $signature,
        ];
    }

    /**
     * @param  array<int, array<int, int>>  $combinations
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>  $accountRoles
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array{team_ids: array<int, int>, borrows: array<int, int>}>
     */
    private function filterAccountCombinations(
        array $combinations,
        array $snapshots,
        array $accountRoles,
        array $bossMap,
        int $atkType,
        array $lockedIds,
        array $hiddenIds
    ): array {
        $roleMask = new SystemSixHitRoleMask(
            $this->collectRoleIds($snapshots)
        );
        $selectedBosses = array_values(array_filter(
            $bossMap,
            static fn (int $boss): bool => $boss > 0
        ));
        sort($selectedBosses);
        $result = [];

        foreach ($combinations as $combination) {
            $teamIds = array_values(array_map(
                static fn (mixed $teamId): int => (int) $teamId,
                $combination
            ));

            if (count($teamIds) !== 3) {
                continue;
            }

            if (
                $lockedIds
                && count(array_intersect($teamIds, $lockedIds))
                    < count($lockedIds)
            ) {
                continue;
            }

            if ($hiddenIds && array_intersect($teamIds, $hiddenIds)) {
                continue;
            }

            $comboBosses = [];
            $physicalCount = 0;
            $magicCount = 0;
            $hasUnknownTeam = false;

            foreach ($teamIds as $teamId) {
                if (! isset($snapshots[$teamId])) {
                    $hasUnknownTeam = true;
                    break;
                }

                $team = $snapshots[$teamId];
                $comboBosses[] = (int) $team['boss'];

                if ((int) $team['atk_value'] >= 0) {
                    $physicalCount++;
                }

                if ((int) $team['atk_value'] <= 0) {
                    $magicCount++;
                }
            }

            if ($hasUnknownTeam) {
                continue;
            }

            if (! $this->passesAttackFilter(
                $atkType,
                $physicalCount,
                $magicCount
            )) {
                continue;
            }

            if (! $this->passesBossFilter($comboBosses, $selectedBosses)) {
                continue;
            }

            $assignment = $this->findAccountBorrowAssignment(
                $teamIds,
                $snapshots,
                $accountRoles,
                $roleMask
            );

            if ($assignment === null) {
                continue;
            }

            $result[] = [
                'team_ids' => $teamIds,
                'borrows' => $assignment['borrows'],
            ];

            if (count($result) >= self::RESULT_LIMIT) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $teamIds
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>  $accountRoles
     * @return array{borrows: array<int, int>, count: int}|null
     */
    private function findAccountBorrowAssignment(
        array $teamIds,
        array $snapshots,
        array $accountRoles,
        SystemSixHitRoleMask $roleMask
    ): ?array {
        $optionSets = [];

        foreach ($teamIds as $teamId) {
            $options = $this->makeAccountBorrowOptions(
                $snapshots[$teamId],
                $accountRoles,
                $roleMask
            );

            if ($options === []) {
                return null;
            }

            $optionSets[] = $options;
        }

        $best = null;

        foreach ($optionSets[0] as $first) {
            foreach ($optionSets[1] as $second) {
                if ($roleMask->intersects($first['mask'], $second['mask'])) {
                    continue;
                }

                $firstTwoMask = $roleMask->union(
                    $first['mask'],
                    $second['mask']
                );

                foreach ($optionSets[2] as $third) {
                    $count = $first['count']
                        + $second['count']
                        + $third['count'];

                    if ($best !== null && $count > $best['count']) {
                        continue;
                    }

                    if ($roleMask->intersects($firstTwoMask, $third['mask'])) {
                        continue;
                    }

                    if ($best !== null && $count === $best['count']) {
                        continue;
                    }

                    $best = [
                        'borrows' => [
                            $teamIds[0] => (int) $first['role'],
                            $teamIds[1] => (int) $second['role'],
                            $teamIds[2] => (int) $third['role'],
                        ],
                        'count' => $count,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $team
     * @param  array<int, bool>  $accountRoles
     * @return array<int, array{role: int, mask: array<int, int>, count: int}>
     */
    private function makeAccountBorrowOptions(
        array $team,
        array $accountRoles,
        SystemSixHitRoleMask $roleMask
    ): array {
        $mandatoryRoles = [];
        $optionalRoles = [];

        foreach ($team['roles'] as $roleId => $status) {
            $roleId = (int) $roleId;
            $status = (int) $status;

            if ($status === 0 || ! isset($accountRoles[$roleId])) {
                $mandatoryRoles[] = $roleId;
            } else {
                $optionalRoles[] = $roleId;
            }
        }

        $mandatoryRoles = array_values(array_unique($mandatoryRoles));
        $optionalRoles = array_values(array_unique($optionalRoles));
        sort($mandatoryRoles, SORT_NUMERIC);
        sort($optionalRoles, SORT_NUMERIC);

        if (count($mandatoryRoles) > 1) {
            return [];
        }

        $effectiveMask = $roleMask->maskFromRoleIds($optionalRoles);

        if (count($mandatoryRoles) === 1) {
            return [[
                'role' => (int) $mandatoryRoles[0],
                'mask' => $effectiveMask,
                'count' => 1,
            ]];
        }

        $options = [[
            'role' => 0,
            'mask' => $effectiveMask,
            'count' => 0,
        ]];
        $seenMasks = [
            implode(',', $effectiveMask) => true,
        ];

        foreach ($optionalRoles as $roleId) {
            $mask = $roleMask->withoutRole($effectiveMask, $roleId);
            $maskKey = implode(',', $mask);

            if (isset($seenMasks[$maskKey])) {
                continue;
            }

            $seenMasks[$maskKey] = true;
            $options[] = [
                'role' => $roleId,
                'mask' => $mask,
                'count' => 1,
            ];
        }

        return $options;
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
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, int>
     */
    private function collectRoleIds(array $snapshots): array
    {
        $roleIds = [];

        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['roles'] as $roleId => $_) {
                $roleIds[] = (int) $roleId;
            }
        }

        return $this->normalizeRoleIds($roleIds);
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
        return $this->normalizeRoleIds($teamIds);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function hashArray(array $value): string
    {
        return hash(
            'sha256',
            json_encode($value, JSON_THROW_ON_ERROR)
        );
    }

    private function queryTeams(
        int $mode,
        Carbon $startOfMonth,
        Carbon $startOfNextMonth
    ): Collection {
        return Team::query()
            ->where('uid', 0)
            ->where('stage', self::STAGE)
            ->where('status', 1)
            ->where('mode', $mode)
            ->where('created_at', '>=', $startOfMonth)
            ->where('created_at', '<', $startOfNextMonth)
            ->with([
                'teamRoles' => function ($query): void {
                    $query
                        ->orderBy('id')
                        ->with('role');
                },
                'links' => function ($query): void {
                    $query
                        ->where('status', 1)
                        ->orderByDesc('damage')
                        ->orderBy('id');
                },
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * 查询当前用户指定模式的本月有效 D 阶段作业。
     */
    private function queryUserTeams(
        int $uid,
        int $mode,
        Carbon $startOfMonth,
        Carbon $startOfNextMonth
    ): Collection {
        return Team::query()
            ->where('uid', $uid)
            ->where('stage', self::STAGE)
            ->where('status', 1)
            ->where('mode', $mode)
            ->where('created_at', '>=', $startOfMonth)
            ->where('created_at', '<', $startOfNextMonth)
            ->with([
                'teamRoles' => function ($query): void {
                    $query
                        ->orderBy('id')
                        ->with('role');
                },
                'links' => function ($query): void {
                    $query
                        ->where('status', 1)
                        ->orderByDesc('damage')
                        ->orderBy('id');
                },
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * 批量读取复制作业的来源系统作业链接。
     *
     * 来源系统作业是否启用、来源链接是否启用都不影响读取。
     */
    private function queryUserSourceLinks(Collection $teams): SupportCollection
    {
        $sourceTeamIds = $teams
            ->pluck('source_team_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($sourceTeamIds->isEmpty()) {
            return collect();
        }

        $existingSourceIds = Team::query()
            ->whereIn('id', $sourceTeamIds)
            ->where('uid', 0)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        if ($existingSourceIds->isEmpty()) {
            return collect();
        }

        return TeamLink::query()
            ->whereIn('team_id', $existingSourceIds)
            ->orderByDesc('damage')
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (TeamLink $link): int => (int) $link->team_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeSnapshot(
        Team $team,
        ?iterable $links = null
    ): array
    {
        $roles = [];
        $roleDetails = [];
        $activeRoles = [];
        $inactiveCount = 0;

        /** @var TeamRole $teamRole */
        foreach ($team->teamRoles as $teamRole) {
            $roleId = (int) $teamRole->role_id;

            if ($roleId <= 0) {
                continue;
            }

            $status = (int) $teamRole->status;
            $roles[$roleId] = $status;

            if ($status === 0) {
                $inactiveCount++;
            } else {
                $activeRoles[$roleId] = true;
            }

            $role = $teamRole->role;
            $imageId = $role?->role_id_6 ?? $role?->role_id_3;

            $roleDetails[$roleId] = [
                'image_id' => $imageId ? (int) $imageId : null,
                'image_url' => $imageId ? url("images/roles/{$imageId}.webp") : null,
            ];
        }

        return [
            'id' => (int) $team->id,
            'stage' => (int) $team->stage,
            'status' => (int) $team->status,
            'mode' => (int) $team->mode,
            'boss' => (int) $team->boss,
            'damage' => (int) $team->damage,
            'atk_value' => (int) $team->atk_value,
            'remark' => (string) ($team->remark ?? ''),
            'updated_at' => $team->updated_at?->format('Y-m-d H:i:s.u'),
            'roles' => $roles,
            'role_details' => $roleDetails,
            'active_roles' => $activeRoles,
            'inactive_count' => $inactiveCount,
            'links' => $this->formatLinks($links ?? $team->links),
        ];
    }

    /**
     * @param  iterable<int, TeamLink>  $links
     * @return array<int, array<string, mixed>>
     */
    private function formatLinks(iterable $links): array
    {
        $result = [];

        foreach ($links as $link) {
            $result[] = [
                'id' => (int) $link->id,
                'url' => $link->url,
                'author' => $link->author,
                'difficulty' => (int) $link->difficulty,
                'damage' => (int) $link->damage,
                'note' => $link->note,
                'status' => (int) $link->status,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     */
    private function signature(array $snapshots): string
    {
        $data = [];

        foreach ($snapshots as $snapshot) {
            $roles = [];

            foreach ($snapshot['roles'] as $roleId => $status) {
                $roles[] = [(int) $roleId, (int) $status];
            }

            $data[] = [
                'id' => $snapshot['id'],
                'stage' => $snapshot['stage'],
                'status' => $snapshot['status'],
                'mode' => $snapshot['mode'],
                'boss' => $snapshot['boss'],
                'damage' => $snapshot['damage'],
                'atk_value' => $snapshot['atk_value'],
                'updated_at' => $snapshot['updated_at'],
                'roles' => $roles,
            ];
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *     snapshots: array<int, array<string, mixed>>,
     *     team_ids: string,
     *     team_count: int,
     *     signature: string
     * }
     */
    private function makeCacheState(Collection $teams): array
    {
        $snapshots = $teams
            ->mapWithKeys(fn (Team $team): array => [
                (int) $team->id => $this->makeSnapshot($team),
            ])
            ->all();
        $teamIds = array_keys($snapshots);

        sort($teamIds, SORT_NUMERIC);

        return [
            'snapshots' => $snapshots,
            'team_ids' => implode(',', $teamIds),
            'team_count' => count($teamIds),
            'signature' => $this->signature($snapshots),
        ];
    }

    /**
     * @param  array{
     *     snapshots: array<int, array<string, mixed>>,
     *     team_ids: string,
     *     team_count: int,
     *     signature: string
     * }  $state
     */
    private function cacheMatches(SystemTripleCache $cache, array $state): bool
    {
        return $cache->team_count === $state['team_count']
            && $cache->team_ids === $state['team_ids']
            && $cache->signature === $state['signature']
            && is_array($cache->combinations);
    }

    private function lockCache(string $yearMonth, int $mode): SystemTripleCache
    {
        $now = now();

        DB::table('system_triple_caches')->insertOrIgnore([
            'year_month' => $yearMonth,
            'mode' => $mode,
            'team_ids' => '',
            'team_count' => 0,
            'signature' => '',
            'combinations' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return SystemTripleCache::query()
            ->where('year_month', $yearMonth)
            ->where('mode', $mode)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $teams
     * @return array<int, array<int, int>>
     */
    private function makeCombinations(
        array $teams,
        bool $strictBorrowRules = false
    ): array
    {
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
    ): bool
    {
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
    ): bool
    {
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
        $selectedBosses = array_values(array_filter(
            $bossMap,
            static fn (int $boss): bool => $boss > 0
        ));
        sort($selectedBosses);

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

            if (! $this->passesAttackFilter($atkType, $physicalCount, $magicCount)) {
                continue;
            }

            if (! $this->passesBossFilter($comboBosses, $selectedBosses)) {
                continue;
            }

            $result[] = $teamIds;

            if (count($result) >= self::RESULT_LIMIT) {
                break;
            }
        }

        return $result;
    }

    private function passesAttackFilter(
        int $atkType,
        int $physicalCount,
        int $magicCount
    ): bool {
        return match ($atkType) {
            0 => true,
            1 => $physicalCount === 3,
            2 => $physicalCount === 2 && $magicCount === 1,
            3 => $physicalCount === 1 && $magicCount === 2,
            4 => $magicCount === 3,
            default => false,
        };
    }

    /**
     * @param  array<int, int>  $comboBosses
     * @param  array<int, int>  $selectedBosses
     */
    private function passesBossFilter(
        array $comboBosses,
        array $selectedBosses
    ): bool {
        $selectedCount = count($selectedBosses);

        if ($selectedCount === 0) {
            return true;
        }

        if ($selectedCount === 1) {
            return count(array_intersect($selectedBosses, $comboBosses)) > 0;
        }

        if ($selectedCount === 2) {
            if (count(array_unique($selectedBosses)) === 1) {
                return count(array_intersect($comboBosses, $selectedBosses)) >= 2;
            }

            return count(array_intersect($selectedBosses, $comboBosses)) === 2;
        }

        return $comboBosses === $selectedBosses;
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
    ): array
    {
        $borrows ??= $strictBorrowRules
            ? $this->assignStrictBorrow($teamIds, $snapshots)
            : $this->assignBorrow($teamIds, $snapshots);
        $result = [];

        foreach ($teamIds as $teamId) {
            $team = $snapshots[$teamId];
            $roles = [];

            foreach ($team['roles'] as $roleId => $status) {
                $details = $team['role_details'][$roleId];
                $displayStatus = (int) $status;

                if ($accountRoles !== null) {
                    $displayStatus = $displayStatus === 1
                        && isset($accountRoles[(int) $roleId])
                            ? 1
                            : 0;
                }

                $roles[] = [
                    'image_id' => $details['image_id'],
                    'image_url' => $details['image_url'],
                    'role_id' => (int) $roleId,
                    'status' => $displayStatus,
                ];
            }

            $result[] = [
                'id' => (int) $teamId,
                'boss' => $team['boss'],
                'damage' => $team['damage'],
                'atk_value' => $team['atk_value'],
                'roles' => $roles,
                'borrow' => (int) ($borrows[$teamId] ?? 0),
                'remark' => $team['remark'],
                'stage' => $team['stage'],
                'link' => $team['links'],
            ];
        }

        return $result;
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
