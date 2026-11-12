<?php

namespace App\Services;

use App\Models\SystemSixHitCacheSet;
use App\Models\SystemSixHitResultCache;
use App\Models\SystemSixHitTriple;
use App\Models\Team;
use App\Models\UserRolePool;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SystemSixHitService
{
    private const ALGORITHM_VERSION = 6;

    private const RESULT_LIMIT = 10;

    // 先走旧项目的前少量候选快路径，合法结果不足时逐级扩大。
    private const CANDIDATE_LIMITS = [5, 10, 20, 40];

    private const MAX_CANDIDATE_LIMIT = 40;

    private const SIDE_LOW = 1;

    private const SIDE_HIGH = 2;

    private const ENDPOINT_CURRENT = 1;

    private const ENDPOINT_PREVIOUS = 2;

    private const MULTIPLIER_TENTHS = [0, 45, 45, 47, 48, 50];

    /**
     * 六种排列按低阶段索引映射到高阶段索引。
     *
     * @var array<int, array<int, int>>
     */
    private const PERMUTATIONS = [
        [0, 1, 2],
        [0, 2, 1],
        [1, 0, 2],
        [1, 2, 0],
        [2, 0, 1],
        [2, 1, 0],
    ];

    public function __construct(
        private readonly SystemTripleService $systemTripleService
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
        [$start, $end, $yearMonth] = $this->monthRange(false);

        return $this->getGroups(
            $start,
            $end,
            $yearMonth,
            $difficulty,
            $lowBosses,
            $highBosses,
            $stage,
            $atkType,
            self::ENDPOINT_CURRENT,
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
        [$start, $end, $yearMonth] = $this->monthRange(true);

        return $this->getGroups(
            $start,
            $end,
            $yearMonth,
            $difficulty,
            $lowBosses,
            $highBosses,
            $stage,
            $atkType,
            self::ENDPOINT_PREVIOUS,
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
        Carbon $start,
        Carbon $end,
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
        $teams = $this->queryTeams($difficulty, $start, $end);
        $lowState = $this->systemTripleService->buildSnapshotState(
            $teams,
            [2, 3]
        );
        $highState = $this->systemTripleService->buildSnapshotState(
            $teams,
            [4]
        );
        $snapshots = $lowState['snapshots'] + $highState['snapshots'];
        $lockedIds = $this->normalizeTeamIds($lockedIds);
        $hiddenIds = $this->normalizeTeamIds($hiddenIds);
        [$lowLockedIds, $highLockedIds] = $this->splitLockedIdsByStage(
            $lockedIds,
            $snapshots
        );
        $sourceSignature = $this->sourceSignature(
            $teams,
            $lowState,
            $highState
        );
        $cacheSet = $this->getOrCreateCacheSet(
            $yearMonth,
            $difficulty,
            $sourceSignature,
            $lowState,
            $highState
        );

        $filters = $this->normalizeFilters(
            $lowBosses,
            $highBosses,
            $difficulty,
            $stage,
            $atkType,
            $lockedIds,
            $hiddenIds
        );
        $filterHash = $this->filterHash($filters);
        $cacheKey = $this->resultCacheKey(
            $cacheSet->id,
            $endpoint,
            $uid,
            $accountId,
            $rolePoolHash,
            $filterHash
        );
        $cached = $this->findResultCache($cacheKey);

        if ($cached !== null) {
            return $this->presentResults(
                $cached->results,
                $snapshots,
                $accountRoles
            );
        }

        $roleMask = new SystemSixHitRoleMask(
            $this->collectRoleIds($snapshots)
        );
        $publicTeams = $this->makeTeamComputationData(
            $snapshots,
            $roleMask,
            null
        );
        $accountTeams = $accountRoles === null
            ? $publicTeams
            : $this->makeTeamComputationData(
                $snapshots,
                $roleMask,
                $accountRoles
            );
        $usableTeamIds = $accountRoles === null
            ? null
            : $this->usableTeamIds($accountTeams);

        $lowCandidates = $this->loadTripleCandidates(
            $cacheSet->id,
            self::SIDE_LOW,
            $stage,
            0,
            $filters['lowBosses'],
            $snapshots,
            $usableTeamIds,
            self::MAX_CANDIDATE_LIMIT,
            $lowLockedIds,
            $hiddenIds
        );
        $highCandidates = $this->loadTripleCandidates(
            $cacheSet->id,
            self::SIDE_HIGH,
            0,
            $atkType,
            $filters['highBosses'],
            $snapshots,
            $usableTeamIds,
            self::MAX_CANDIDATE_LIMIT,
            $highLockedIds,
            $hiddenIds
        );

        $results = $this->searchTopPackages(
            $lowCandidates,
            $highCandidates,
            $snapshots,
            $roleMask,
            $publicTeams,
            $accountTeams,
            $endpoint
        );

        $this->storeResultCache(
            $cacheSet->id,
            $endpoint,
            $uid,
            $accountId,
            $rolePoolHash,
            $filterHash,
            $cacheKey,
            $filters,
            $results
        );

        return $this->presentResults($results, $snapshots, $accountRoles);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function monthRange(bool $previousMonth): array
    {
        $start = now()->startOfMonth();

        if ($previousMonth) {
            $start = $start->copy()->subMonthNoOverflow()->startOfMonth();
        }

        $end = $start->copy()->addMonthNoOverflow()->startOfMonth();

        return [$start, $end, $start->format('Ym')];
    }

    private function queryTeams(
        int $difficulty,
        Carbon $start,
        Carbon $end
    ): EloquentCollection {
        $teams = Team::query()
            ->where('uid', 0)
            ->where('status', 1)
            ->whereIn('stage', [2, 3, 4])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->with([
                'teamRoles' => function ($query): void {
                    $query->orderBy('id')->with('role');
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

        return $teams
            ->filter(
                fn (Team $team): bool => (int) $team->stage !== 4
                    || $this->systemTripleService->teamHasDifficulty(
                        $team,
                        $difficulty
                    )
            )
            ->values();
    }

    /**
     * @param  array<string, mixed>  $lowState
     * @param  array<string, mixed>  $highState
     */
    private function sourceSignature(
        EloquentCollection $teams,
        array $lowState,
        array $highState
    ): string {
        $createdAt = $teams
            ->mapWithKeys(static fn (Team $team): array => [
                (int) $team->id => $team->created_at?->format('Y-m-d H:i:s.u'),
            ])
            ->all();

        return hash('sha256', json_encode([
            'algorithm_version' => self::ALGORITHM_VERSION,
            'low' => $lowState['signature'],
            'high' => $highState['signature'],
            'created_at' => $createdAt,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $lowState
     * @param  array<string, mixed>  $highState
     */
    private function getOrCreateCacheSet(
        string $yearMonth,
        int $difficulty,
        string $sourceSignature,
        array $lowState,
        array $highState
    ): SystemSixHitCacheSet {
        $existing = SystemSixHitCacheSet::query()
            ->where('year_month', $yearMonth)
            ->where('mode', $difficulty)
            ->where('source_signature', $sourceSignature)
            ->first();

        if ($existing !== null && $existing->generated_at !== null) {
            return $existing;
        }

        $lowCombinations = $this->systemTripleService
            ->buildCombinationsForState($lowState);
        $highCombinations = $this->systemTripleService
            ->buildCombinationsForState($highState);
        $now = now();

        DB::transaction(function () use (
            $yearMonth,
            $difficulty,
            $sourceSignature,
            $lowState,
            $highState,
            $lowCombinations,
            $highCombinations,
            $now
        ): void {
            DB::table('system_six_hit_cache_sets')->insertOrIgnore([
                'year_month' => $yearMonth,
                'mode' => $difficulty,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'source_signature' => $sourceSignature,
                'low_team_count' => 0,
                'high_team_count' => 0,
                'low_triple_count' => 0,
                'high_triple_count' => 0,
                'generated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $cacheSet = SystemSixHitCacheSet::query()
                ->where('year_month', $yearMonth)
                ->where('mode', $difficulty)
                ->where('source_signature', $sourceSignature)
                ->lockForUpdate()
                ->firstOrFail();

            if ($cacheSet->generated_at !== null) {
                return;
            }

            $rows = array_merge(
                $this->makeTripleRows(
                    $cacheSet->id,
                    self::SIDE_LOW,
                    $lowState,
                    $lowCombinations,
                    $now
                ),
                $this->makeTripleRows(
                    $cacheSet->id,
                    self::SIDE_HIGH,
                    $highState,
                    $highCombinations,
                    $now
                )
            );

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('system_six_hit_triples')->insert($chunk);
            }

            $cacheSet->forceFill([
                'low_team_count' => $lowState['team_count'],
                'high_team_count' => $highState['team_count'],
                'low_triple_count' => count($lowCombinations),
                'high_triple_count' => count($highCombinations),
                'generated_at' => $now,
            ])->save();
        }, 3);

        return SystemSixHitCacheSet::query()
            ->where('year_month', $yearMonth)
            ->where('mode', $difficulty)
            ->where('source_signature', $sourceSignature)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<int, array<int, int>>  $combinations
     * @return array<int, array<string, mixed>>
     */
    private function makeTripleRows(
        int $cacheSetId,
        int $side,
        array $state,
        array $combinations,
        Carbon $now
    ): array {
        $snapshots = $state['snapshots'];
        $rows = [];

        foreach ($combinations as $teamIds) {
            $orderedIds = array_map(
                static fn (mixed $teamId): int => (int) $teamId,
                $teamIds
            );
            usort($orderedIds, function (int $left, int $right) use ($snapshots): int {
                $bossComparison = (int) $snapshots[$left]['boss']
                    <=> (int) $snapshots[$right]['boss'];

                return $bossComparison !== 0
                    ? $bossComparison
                    : $left <=> $right;
            });
            $canonicalIds = $orderedIds;
            sort($canonicalIds, SORT_NUMERIC);
            $bosses = [];
            $stageCounts = [2 => 0, 3 => 0, 4 => 0];
            $atkPositiveCount = 0;
            $atkNonpositiveCount = 0;
            $scoreTenths = 0;

            foreach ($orderedIds as $teamId) {
                $snapshot = $snapshots[$teamId];
                $boss = (int) $snapshot['boss'];
                $stage = (int) $snapshot['stage'];
                $atkValue = (int) $snapshot['atk_value'];
                $bosses[] = $boss;
                $stageCounts[$stage] = ($stageCounts[$stage] ?? 0) + 1;
                $scoreTenths += (int) $snapshot['damage']
                    * (self::MULTIPLIER_TENTHS[$boss] ?? 0);

                if ($atkValue >= 0) {
                    $atkPositiveCount++;
                }

                if ($atkValue <= 0) {
                    $atkNonpositiveCount++;
                }
            }

            $rows[] = [
                'cache_set_id' => $cacheSetId,
                'side' => $side,
                'team_1_id' => $canonicalIds[0],
                'team_2_id' => $canonicalIds[1],
                'team_3_id' => $canonicalIds[2],
                'team_ids' => json_encode($orderedIds, JSON_THROW_ON_ERROR),
                'boss_1' => $bosses[0],
                'boss_2' => $bosses[1],
                'boss_3' => $bosses[2],
                'score_tenths' => $scoreTenths,
                'stage_2_count' => $stageCounts[2] ?? 0,
                'stage_3_count' => $stageCounts[3] ?? 0,
                'stage_4_count' => $stageCounts[4] ?? 0,
                'atk_positive_count' => $atkPositiveCount,
                'atk_nonpositive_count' => $atkNonpositiveCount,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, int>  $selectedBosses
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>|null  $usableTeamIds
     * @return array<int, array<string, mixed>>
     */
    private function loadTripleCandidates(
        int $cacheSetId,
        int $side,
        int $stage,
        int $atkType,
        array $selectedBosses,
        array $snapshots,
        ?array $usableTeamIds,
        int $limit,
        array $lockedIds = [],
        array $hiddenIds = []
    ): array {
        $query = SystemSixHitTriple::query()
            ->where('cache_set_id', $cacheSetId)
            ->where('side', $side);

        if ($side === self::SIDE_LOW && $stage === 2) {
            $query->where('stage_2_count', 3);
        } elseif ($side === self::SIDE_LOW && $stage === 3) {
            $query->where('stage_3_count', 3);
        }

        if ($side === self::SIDE_HIGH) {
            match ($atkType) {
                1 => $query
                    ->where('atk_positive_count', 3),
                2 => $query
                    ->where('atk_positive_count', 2)
                    ->where('atk_nonpositive_count', 1),
                3 => $query
                    ->where('atk_positive_count', 1)
                    ->where('atk_nonpositive_count', 2),
                4 => $query
                    ->where('atk_nonpositive_count', 3),
                default => null,
            };
        }

        $selectedBosses = array_values(array_filter(
            array_map('intval', $selectedBosses),
            static fn (int $boss): bool => $boss > 0
        ));
        sort($selectedBosses, SORT_NUMERIC);
        $lockedLookup = array_fill_keys($lockedIds, true);
        $hiddenLookup = array_fill_keys($hiddenIds, true);
        $lockedCount = count($lockedLookup);
        $candidates = [];

        $rows = $query
            ->orderByDesc('score_tenths')
            ->orderBy('team_1_id')
            ->orderBy('team_2_id')
            ->orderBy('team_3_id')
            ->cursor();

        foreach ($rows as $row) {
            $teamIds = array_map(
                static fn (mixed $teamId): int => (int) $teamId,
                $row->team_ids
            );

            if ($hiddenLookup !== []) {
                $hasHiddenTeam = false;

                foreach ($teamIds as $teamId) {
                    if (isset($hiddenLookup[$teamId])) {
                        $hasHiddenTeam = true;
                        break;
                    }
                }

                if ($hasHiddenTeam) {
                    continue;
                }
            }

            if ($lockedCount > 0) {
                $lockedMatchCount = 0;

                foreach ($teamIds as $teamId) {
                    if (isset($lockedLookup[$teamId])) {
                        $lockedMatchCount++;
                    }
                }

                if ($lockedMatchCount < $lockedCount) {
                    continue;
                }
            }

            if ($usableTeamIds !== null) {
                $hasUnusableTeam = false;

                foreach ($teamIds as $teamId) {
                    if (! isset($usableTeamIds[$teamId])) {
                        $hasUnusableTeam = true;
                        break;
                    }
                }

                if ($hasUnusableTeam) {
                    continue;
                }
            }

            $comboBosses = [];

            foreach ($teamIds as $teamId) {
                $comboBosses[] = (int) $snapshots[$teamId]['boss'];
            }

            if (! $this->passesBossFilter($comboBosses, $selectedBosses)) {
                continue;
            }

            $canonicalIds = $teamIds;
            sort($canonicalIds, SORT_NUMERIC);
            $candidates[] = [
                'team_ids' => $teamIds,
                'canonical_ids' => $canonicalIds,
                'score_tenths' => (int) $row->score_tenths,
            ];

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>|null  $accountRoles
     * @return array<int, array<string, mixed>>
     */
    private function makeTeamComputationData(
        array $snapshots,
        SystemSixHitRoleMask $roleMask,
        ?array $accountRoles
    ): array {
        $result = [];

        foreach ($snapshots as $teamId => $snapshot) {
            $roles = $snapshot['roles'];
            $rawRoles = [];
            $activeRoles = [];
            $mandatoryRoles = [];
            $optionalRoles = [];

            foreach ($roles as $roleId => $status) {
                $roleId = (int) $roleId;
                $status = (int) $status;
                $rawRoles[] = $roleId;

                if ($status === 0) {
                    $mandatoryRoles[] = $roleId;
                    continue;
                }

                if ($accountRoles === null) {
                    $activeRoles[] = $roleId;
                    $optionalRoles[] = $roleId;
                    continue;
                }

                if (isset($accountRoles[$roleId])) {
                    $activeRoles[] = $roleId;
                    $optionalRoles[] = $roleId;
                } else {
                    $mandatoryRoles[] = $roleId;
                }
            }

            $mandatoryRoles = array_values(array_unique($mandatoryRoles));
            $optionalRoles = array_values(array_unique($optionalRoles));
            sort($mandatoryRoles, SORT_NUMERIC);
            sort($optionalRoles, SORT_NUMERIC);
            $effectiveMask = $roleMask->maskFromRoleIds($activeRoles);

            $result[(int) $teamId] = [
                'raw_mask' => $roleMask->maskFromRoleIds($rawRoles),
                'effective_mask' => $effectiveMask,
                'mandatory_roles' => $mandatoryRoles,
                'optional_roles' => $optionalRoles,
                'borrow_options' => $this->makeBorrowOptions(
                    $roleMask,
                    $effectiveMask,
                    $mandatoryRoles,
                    $optionalRoles
                ),
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $mandatoryRoles
     * @param  array<int, int>  $optionalRoles
     * @return array<int, array{role: int, mask: array<int, int>, count: int}>
     */
    private function makeBorrowOptions(
        SystemSixHitRoleMask $roleMask,
        array $effectiveMask,
        array $mandatoryRoles,
        array $optionalRoles
    ): array {
        if (count($mandatoryRoles) > 1) {
            return [];
        }

        if (count($mandatoryRoles) === 1) {
            $roleId = (int) $mandatoryRoles[0];

            return [[
                'role' => $roleId,
                'mask' => $roleMask->withoutRole($effectiveMask, $roleId),
                'count' => 1,
            ]];
        }

        $options = [[
            'role' => 0,
            'mask' => $effectiveMask,
            'count' => 0,
        ]];

        foreach ($optionalRoles as $roleId) {
            $options[] = [
                'role' => (int) $roleId,
                'mask' => $roleMask->withoutRole(
                    $effectiveMask,
                    (int) $roleId
                ),
                'count' => 1,
            ];
        }

        return $options;
    }

    /**
     * @param  array<int, array<string, mixed>>  $teamData
     * @return array<int, bool>
     */
    private function usableTeamIds(array $teamData): array
    {
        $result = [];

        foreach ($teamData as $teamId => $team) {
            if (count($team['mandatory_roles']) <= 1) {
                $result[(int) $teamId] = true;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lowCandidates
     * @param  array<int, array<string, mixed>>  $highCandidates
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, array<string, mixed>>  $publicTeams
     * @param  array<int, array<string, mixed>>  $accountTeams
     * @return array<int, array<string, mixed>>
     */
    private function searchTopPackages(
        array $lowCandidates,
        array $highCandidates,
        array $snapshots,
        SystemSixHitRoleMask $roleMask,
        array $publicTeams,
        array $accountTeams,
        int $endpoint
    ): array {
        if ($lowCandidates === [] || $highCandidates === []) {
            return [];
        }

        $packages = [];
        $pairCache = [
            'public' => [],
            'account' => [],
        ];

        foreach (self::CANDIDATE_LIMITS as $limit) {
            $limitedLow = array_slice(
                $lowCandidates,
                0,
                min($limit, count($lowCandidates))
            );
            $limitedHigh = array_slice(
                $highCandidates,
                0,
                min($limit, count($highCandidates))
            );
            $packages = $this->searchTopPackagesForCandidates(
                $limitedLow,
                $limitedHigh,
                $snapshots,
                $roleMask,
                $publicTeams,
                $accountTeams,
                $endpoint,
                $pairCache
            );

            if (count($packages) >= self::RESULT_LIMIT) {
                return $packages;
            }

            if (
                count($lowCandidates) <= $limit
                && count($highCandidates) <= $limit
            ) {
                break;
            }
        }

        return $packages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lowCandidates
     * @param  array<int, array<string, mixed>>  $highCandidates
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, array<string, mixed>>  $publicTeams
     * @param  array<int, array<string, mixed>>  $accountTeams
     * @param  array{public: array<string, array<string, mixed>>, account: array<string, array<string, mixed>>}  $pairCache
     * @return array<int, array<string, mixed>>
     */
    private function searchTopPackagesForCandidates(
        array $lowCandidates,
        array $highCandidates,
        array $snapshots,
        SystemSixHitRoleMask $roleMask,
        array $publicTeams,
        array $accountTeams,
        int $endpoint,
        array &$pairCache
    ): array {
        if ($lowCandidates === [] || $highCandidates === []) {
            return [];
        }

        $heap = [];
        $visited = [];
        $this->heapPush($heap, $this->makeHeapNode(
            $lowCandidates,
            $highCandidates,
            0,
            0
        ));
        $visited['0:0'] = true;
        $packages = [];
        $threshold = null;

        while (($node = $this->heapPop($heap)) !== null) {
            if (
                $threshold !== null
                && count($packages) >= self::RESULT_LIMIT
                && $node['score'] < $threshold
            ) {
                break;
            }

            $nextLow = $node['i'] + 1;
            $nextHigh = $node['j'] + 1;

            if ($nextLow < count($lowCandidates)) {
                $key = $nextLow.':'.$node['j'];

                if (! isset($visited[$key])) {
                    $visited[$key] = true;
                    $this->heapPush($heap, $this->makeHeapNode(
                        $lowCandidates,
                        $highCandidates,
                        $nextLow,
                        $node['j']
                    ));
                }
            }

            if ($nextHigh < count($highCandidates)) {
                $key = $node['i'].':'.$nextHigh;

                if (! isset($visited[$key])) {
                    $visited[$key] = true;
                    $this->heapPush($heap, $this->makeHeapNode(
                        $lowCandidates,
                        $highCandidates,
                        $node['i'],
                        $nextHigh
                    ));
                }
            }

            $package = $this->evaluatePair(
                $lowCandidates[$node['i']],
                $highCandidates[$node['j']],
                $snapshots,
                $roleMask,
                $publicTeams,
                $accountTeams,
                $endpoint,
                $pairCache
            );

            if ($package === null) {
                continue;
            }

            $packages[] = $package;

            if (count($packages) >= self::RESULT_LIMIT) {
                $this->sortPackages($packages);
                $packages = array_slice($packages, 0, self::RESULT_LIMIT);
                $threshold = $packages[self::RESULT_LIMIT - 1]['score_tenths'];
            }
        }

        $this->sortPackages($packages);

        return array_slice($packages, 0, self::RESULT_LIMIT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lowCandidates
     * @param  array<int, array<string, mixed>>  $highCandidates
     * @return array<string, mixed>
     */
    private function makeHeapNode(
        array $lowCandidates,
        array $highCandidates,
        int $lowIndex,
        int $highIndex
    ): array {
        $low = $lowCandidates[$lowIndex];
        $high = $highCandidates[$highIndex];

        return [
            'i' => $lowIndex,
            'j' => $highIndex,
            'score' => $low['score_tenths'] + $high['score_tenths'],
            'low_key' => $low['canonical_ids'],
            'high_key' => $high['canonical_ids'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $heap
     * @param  array<string, mixed>  $node
     */
    private function heapPush(array &$heap, array $node): void
    {
        $heap[] = $node;
        $index = count($heap) - 1;

        while ($index > 0) {
            $parent = intdiv($index - 1, 2);

            if (! $this->heapNodeGreater($heap[$index], $heap[$parent])) {
                break;
            }

            [$heap[$index], $heap[$parent]] = [$heap[$parent], $heap[$index]];
            $index = $parent;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $heap
     * @return array<string, mixed>|null
     */
    private function heapPop(array &$heap): ?array
    {
        if ($heap === []) {
            return null;
        }

        $root = $heap[0];
        $last = array_pop($heap);

        if ($heap === []) {
            return $root;
        }

        $heap[0] = $last;
        $index = 0;
        $count = count($heap);

        while (true) {
            $left = $index * 2 + 1;
            $right = $left + 1;
            $largest = $index;

            if (
                $left < $count
                && $this->heapNodeGreater($heap[$left], $heap[$largest])
            ) {
                $largest = $left;
            }

            if (
                $right < $count
                && $this->heapNodeGreater($heap[$right], $heap[$largest])
            ) {
                $largest = $right;
            }

            if ($largest === $index) {
                break;
            }

            [$heap[$index], $heap[$largest]] = [$heap[$largest], $heap[$index]];
            $index = $largest;
        }

        return $root;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function heapNodeGreater(array $left, array $right): bool
    {
        if ($left['score'] !== $right['score']) {
            return $left['score'] > $right['score'];
        }

        foreach (['low_key', 'high_key'] as $key) {
            foreach ($left[$key] as $index => $value) {
                $other = $right[$key][$index];

                if ($value !== $other) {
                    return $value < $other;
                }
            }
        }

        if ($left['i'] !== $right['i']) {
            return $left['i'] < $right['i'];
        }

        return $left['j'] < $right['j'];
    }

    /**
     * @param  array<string, mixed>  $lowCandidate
     * @param  array<string, mixed>  $highCandidate
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, array<string, mixed>>  $publicTeams
     * @param  array<int, array<string, mixed>>  $accountTeams
     * @param  array{public: array<string, array<string, mixed>>, account: array<string, array<string, mixed>>}  $pairCache
     * @return array<string, mixed>|null
     */
    private function evaluatePair(
        array $lowCandidate,
        array $highCandidate,
        array $snapshots,
        SystemSixHitRoleMask $roleMask,
        array $publicTeams,
        array $accountTeams,
        int $endpoint,
        array &$pairCache
    ): ?array {
        $lowIds = $lowCandidate['team_ids'];
        $highIds = $highCandidate['team_ids'];
        $best = null;
        $checks = [];

        foreach (self::PERMUTATIONS as $permutationIndex => $permutation) {
            $pairPairs = [];
            $overlap = 0;
            $publicPairOptionSets = [];

            foreach ($permutation as $lowIndex => $highIndex) {
                $lowId = $lowIds[$lowIndex];
                $highId = $highIds[$highIndex];
                $pairPairs[] = [$lowId, $highId];
                $pairData = $this->pairComputation(
                    $lowId,
                    $highId,
                    $publicTeams,
                    $roleMask,
                    $pairCache['public']
                );
                $overlap += $pairData['overlap'];
                $publicPairOptionSets[] = $pairData['options'];
            }

            $publicAssignment = $this->solvePairBorrow(
                $publicPairOptionSets,
                $roleMask
            );
            $accountAssignment = null;

            if ($publicAssignment !== null && $endpoint === self::ENDPOINT_CURRENT) {
                $accountPairOptionSets = [];

                foreach ($pairPairs as [$lowId, $highId]) {
                    $accountPairOptionSets[] = $this->pairComputation(
                        $lowId,
                        $highId,
                        $accountTeams,
                        $roleMask,
                        $pairCache['account']
                    )['options'];
                }

                $accountAssignment = $this->solvePairBorrow(
                    $accountPairOptionSets,
                    $roleMask
                );
            }

            $checks[] = [
                'permutation' => $permutationIndex,
                'overlap' => $overlap,
                'public_valid' => $publicAssignment !== null,
                'account_valid' => $endpoint === self::ENDPOINT_CURRENT
                    ? $accountAssignment !== null
                    : null,
                'borrow_count' => $endpoint === self::ENDPOINT_CURRENT
                    ? ($accountAssignment['count'] ?? null)
                    : ($publicAssignment['count'] ?? null),
            ];

            $assignment = $endpoint === self::ENDPOINT_CURRENT
                ? $accountAssignment
                : $publicAssignment;

            if ($assignment === null) {
                continue;
            }

            $isBetter = $best === null;

            if (! $isBetter && $endpoint === self::ENDPOINT_CURRENT) {
                if ($assignment['count'] < $best['borrow_count']) {
                    $isBetter = true;
                } elseif (
                    $assignment['count'] === $best['borrow_count']
                    && $overlap > $best['overlap']
                ) {
                    $isBetter = true;
                } elseif (
                    $assignment['count'] === $best['borrow_count']
                    && $overlap === $best['overlap']
                    && $permutationIndex < $best['permutation']
                ) {
                    $isBetter = true;
                }
            } elseif (! $isBetter) {
                if ($overlap > $best['overlap']) {
                    $isBetter = true;
                } elseif (
                    $overlap === $best['overlap']
                    && $permutationIndex < $best['permutation']
                ) {
                    $isBetter = true;
                }
            }

            if (! $isBetter) {
                continue;
            }

            $best = [
                'low_team_ids' => $lowIds,
                'high_team_ids' => $highIds,
                'low_canonical_ids' => $lowCandidate['canonical_ids'],
                'high_canonical_ids' => $highCandidate['canonical_ids'],
                'pairs' => $pairPairs,
                'permutation' => $permutationIndex,
                'borrow_count' => $assignment['count'],
                'overlap' => $overlap,
                'score_tenths' => $lowCandidate['score_tenths']
                    + $highCandidate['score_tenths'],
                'borrows' => $assignment['borrows'],
                'permutation_checks' => $checks,
            ];
        }

        if ($best !== null) {
            $best['permutation_checks'] = $checks;
        }

        return $best;
    }

    /**
     * 同一请求内，低阶段作业与高阶段作业的配对数据只计算一次。
     *
     * @param  array<int, array<string, mixed>>  $teamData
     * @param  array<string, array<string, mixed>>  $cache
     * @return array{
     *     overlap: int,
     *     options: array<int, array{
     *         mask: array<int, int>,
     *         count: int,
     *         borrows: array<int, int>
     *     }>
     * }
     */
    private function pairComputation(
        int $lowId,
        int $highId,
        array $teamData,
        SystemSixHitRoleMask $roleMask,
        array &$cache
    ): array {
        $key = $lowId.':'.$highId;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $low = $teamData[$lowId] ?? null;
        $high = $teamData[$highId] ?? null;
        $overlap = 0;
        $options = [];

        if (is_array($low) && is_array($high)) {
            $overlap = $roleMask->countIntersection(
                $low['raw_mask'],
                $high['raw_mask']
            );
            $seenMasks = [];

            foreach ($low['borrow_options'] as $lowOption) {
                foreach ($high['borrow_options'] as $highOption) {
                    $mask = $roleMask->union(
                        $lowOption['mask'],
                        $highOption['mask']
                    );
                    $maskKey = implode(',', $mask);

                    if (isset($seenMasks[$maskKey])) {
                        continue;
                    }

                    $seenMasks[$maskKey] = true;
                    $options[] = [
                        'mask' => $mask,
                        'count' => $lowOption['count'] + $highOption['count'],
                        'borrows' => [
                            $lowId => (int) $lowOption['role'],
                            $highId => (int) $highOption['role'],
                        ],
                    ];
                }
            }
        }

        $cache[$key] = [
            'overlap' => $overlap,
            'options' => $options,
        ];

        return $cache[$key];
    }

    /**
     * 按三个配对求解借人，先找借人数最少的稳定方案。
     *
     * @param  array<int, array<int, array{
     *     mask: array<int, int>,
     *     count: int,
     *     borrows: array<int, int>
     * }>>  $pairOptionSets
     * @return array{borrows: array<int, int>, count: int}|null
     */
    private function solvePairBorrow(
        array $pairOptionSets,
        SystemSixHitRoleMask $roleMask
    ): ?array {
        if (count($pairOptionSets) !== 3) {
            return null;
        }

        $grouped = [];
        $minCounts = [];
        $maxCounts = [];

        foreach ($pairOptionSets as $pairIndex => $options) {
            if ($options === []) {
                return null;
            }

            $groups = [];

            foreach ($options as $option) {
                $groups[$option['count']][] = $option;
            }

            $grouped[$pairIndex] = $groups;
            $counts = array_keys($groups);
            $minCounts[$pairIndex] = min($counts);
            $maxCounts[$pairIndex] = max($counts);
        }

        if (
            isset($grouped[0][0][0], $grouped[1][0][0], $grouped[2][0][0])
        ) {
            $zeroOptions = [
                $grouped[0][0][0],
                $grouped[1][0][0],
                $grouped[2][0][0],
            ];

            if (! $this->pairMasksConflict($zeroOptions, $roleMask)) {
                return [
                    'borrows' => $this->mergePairBorrows($zeroOptions),
                    'count' => 0,
                ];
            }
        }

        $minimum = array_sum($minCounts);
        $maximum = array_sum($maxCounts);

        for ($target = $minimum; $target <= $maximum; $target++) {
            for (
                $firstCount = $minCounts[0];
                $firstCount <= $maxCounts[0];
                $firstCount++
            ) {
                for (
                    $secondCount = $minCounts[1];
                    $secondCount <= $maxCounts[1];
                    $secondCount++
                ) {
                if (
                    ! isset($grouped[0][$firstCount])
                    || ! isset($grouped[1][$secondCount])
                ) {
                    continue;
                }

                    $thirdCount = $target - $firstCount - $secondCount;

                    if (
                        $thirdCount < $minCounts[2]
                        || $thirdCount > $maxCounts[2]
                    || ! isset($grouped[2][$thirdCount])
                    ) {
                        continue;
                    }

                    foreach ($grouped[0][$firstCount] as $firstOption) {
                        foreach ($grouped[1][$secondCount] as $secondOption) {
                            if ($roleMask->intersects(
                                $firstOption['mask'],
                                $secondOption['mask']
                            )) {
                                continue;
                            }

                            $firstTwoMask = $roleMask->union(
                                $firstOption['mask'],
                                $secondOption['mask']
                            );

                            foreach ($grouped[2][$thirdCount] as $thirdOption) {
                                if ($roleMask->intersects(
                                    $firstTwoMask,
                                    $thirdOption['mask']
                                )) {
                                    continue;
                                }

                                $selected = [
                                    $firstOption,
                                    $secondOption,
                                    $thirdOption,
                                ];

                                return [
                                    'borrows' => $this->mergePairBorrows($selected),
                                    'count' => $target,
                                ];
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{
     *     mask: array<int, int>,
     *     count: int,
     *     borrows: array<int, int>
     * }>  $options
     */
    private function pairMasksConflict(
        array $options,
        SystemSixHitRoleMask $roleMask
    ): bool {
        $count = count($options);

        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                if ($roleMask->intersects(
                    $options[$left]['mask'],
                    $options[$right]['mask']
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{
     *     mask: array<int, int>,
     *     count: int,
     *     borrows: array<int, int>
     * }>  $options
     * @return array<int, int>
     */
    private function mergePairBorrows(array $options): array
    {
        $borrows = [];

        foreach ($options as $option) {
            foreach ($option['borrows'] as $teamId => $roleId) {
                $borrows[(int) $teamId] = (int) $roleId;
            }
        }

        return $borrows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $packages
     */
    private function sortPackages(array &$packages): void
    {
        usort($packages, function (array $left, array $right): int {
            $scoreComparison = $right['score_tenths']
                <=> $left['score_tenths'];

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $lowComparison = $left['low_canonical_ids']
                <=> $right['low_canonical_ids'];

            if ($lowComparison !== 0) {
                return $lowComparison;
            }

            $highComparison = $left['high_canonical_ids']
                <=> $right['high_canonical_ids'];

            if ($highComparison !== 0) {
                return $highComparison;
            }

            return $left['permutation'] <=> $right['permutation'];
        });
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
                    'low' => $this->presentTeam(
                        $lowId,
                        $snapshots,
                        $borrows,
                        $accountRoles
                    ),
                    'high' => $this->presentTeam(
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
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, int>  $borrows
     * @param  array<int, bool>|null  $accountRoles
     * @return array<string, mixed>
     */
    private function presentTeam(
        int $teamId,
        array $snapshots,
        array $borrows,
        ?array $accountRoles
    ): array {
        $team = $snapshots[$teamId];
        $roles = [];

        foreach ($team['roles'] as $roleId => $roleStatus) {
            $roleId = (int) $roleId;
            $status = (int) $roleStatus;

            if ($accountRoles !== null) {
                $status = $status === 1 && isset($accountRoles[$roleId])
                    ? 1
                    : 0;
            }

            $details = $team['role_details'][$roleId] ?? [
                'image_id' => null,
                'image_url' => null,
            ];
            $roles[] = [
                'image_id' => $details['image_id'],
                'image_url' => $details['image_url'],
                'role_id' => $roleId,
                'status' => $status,
            ];
        }

        return [
            'id' => $teamId,
            'boss' => (int) $team['boss'],
            'damage' => (int) $team['damage'],
            'atk_value' => (int) $team['atk_value'],
            'roles' => $roles,
            'borrow' => (int) ($borrows[$teamId] ?? $borrows[(string) $teamId] ?? 0),
            'remark' => (string) ($team['remark'] ?? ''),
            'stage' => (int) $team['stage'],
            'link' => $team['links'],
        ];
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
        $lowBosses = $this->normalizeBosses($lowBosses);
        $highBosses = $this->normalizeBosses($highBosses);

        return [
            'lowBosses' => $lowBosses,
            'highBosses' => $highBosses,
            'difficulty' => $difficulty,
            'stage' => $stage,
            'atkType' => $atkType,
            'lockedIds' => $this->normalizeTeamIds($lockedIds),
            'hiddenIds' => $this->normalizeTeamIds($hiddenIds),
        ];
    }

    /**
     * @param  array<int, int>  $teamIds
     * @return array<int, int>
     */
    private function normalizeTeamIds(array $teamIds): array
    {
        $teamIds = array_values(array_unique(array_filter(
            array_map('intval', $teamIds),
            static fn (int $teamId): bool => $teamId > 0
        )));
        sort($teamIds, SORT_NUMERIC);

        return $teamIds;
    }

    /**
     * @param  array<int, int>  $lockedIds
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function splitLockedIdsByStage(
        array $lockedIds,
        array $snapshots
    ): array {
        $lowLockedIds = [];
        $highLockedIds = [];

        foreach ($lockedIds as $teamId) {
            $stage = (int) ($snapshots[$teamId]['stage'] ?? 0);

            if ($stage === 2 || $stage === 3) {
                $lowLockedIds[] = $teamId;
            } elseif ($stage === 4) {
                $highLockedIds[] = $teamId;
            } else {
                throw ValidationException::withMessages([
                    'lockedIds' => '锁定作业不存在或不属于当前月份及当前难度',
                ]);
            }
        }

        if (count($lowLockedIds) > 2) {
            throw ValidationException::withMessages([
                'lockedIds' => '低阶段最多锁定 2 个作业',
            ]);
        }

        if (count($highLockedIds) > 2) {
            throw ValidationException::withMessages([
                'lockedIds' => '高阶段最多锁定 2 个作业',
            ]);
        }

        return [$lowLockedIds, $highLockedIds];
    }

    /**
     * @param  array<int, int>  $bosses
     * @return array<int, int>
     */
    private function normalizeBosses(array $bosses): array
    {
        $bosses = array_values(array_filter(
            array_map('intval', $bosses),
            static fn (int $boss): bool => $boss > 0
        ));
        sort($bosses, SORT_NUMERIC);

        return $bosses;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filterHash(array $filters): string
    {
        return hash('sha256', json_encode(
            $filters,
            JSON_THROW_ON_ERROR
        ));
    }

    private function resultCacheKey(
        int $cacheSetId,
        int $endpoint,
        ?int $uid,
        ?int $accountId,
        ?string $rolePoolHash,
        string $filterHash
    ): string {
        return hash('sha256', json_encode([
            'cache_set_id' => $cacheSetId,
            'endpoint' => $endpoint,
            'uid' => $uid,
            'account_id' => $accountId,
            'role_pool_hash' => $rolePoolHash,
            'filter_hash' => $filterHash,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ], JSON_THROW_ON_ERROR));
    }

    private function findResultCache(string $cacheKey): ?SystemSixHitResultCache
    {
        return SystemSixHitResultCache::query()
            ->where('cache_key', $cacheKey)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, array<string, mixed>>  $results
     */
    private function storeResultCache(
        int $cacheSetId,
        int $endpoint,
        ?int $uid,
        ?int $accountId,
        ?string $rolePoolHash,
        string $filterHash,
        string $cacheKey,
        array $filters,
        array $results
    ): void {
        $now = now();

        DB::table('system_six_hit_result_caches')->insertOrIgnore([
            'cache_set_id' => $cacheSetId,
            'endpoint' => $endpoint,
            'uid' => $uid,
            'account_id' => $accountId,
            'role_pool_hash' => $rolePoolHash,
            'filter_hash' => $filterHash,
            'cache_key' => $cacheKey,
            'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
            'results' => json_encode($results, JSON_THROW_ON_ERROR),
            'result_count' => count($results),
            'generated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

        $roleIds = array_values(array_unique($roleIds));
        sort($roleIds, SORT_NUMERIC);

        return $roleIds;
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
            return count(array_intersect(
                $selectedBosses,
                $comboBosses
            )) > 0;
        }

        if ($selectedCount === 2) {
            if (count(array_unique($selectedBosses)) === 1) {
                return count(array_intersect(
                    $comboBosses,
                    $selectedBosses
                )) >= 2;
            }

            return count(array_intersect(
                $selectedBosses,
                $comboBosses
            )) === 2;
        }

        return $comboBosses === $selectedBosses;
    }
}
