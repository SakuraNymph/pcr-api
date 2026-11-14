<?php

namespace App\Services;

final class SystemSixHitPlanner
{
    public const ENDPOINT_CURRENT = 1;

    public const ENDPOINT_PREVIOUS = 2;

    private const RESULT_LIMIT = 10;

    // 先走旧项目的前少量候选快路径，合法结果不足时逐级扩大。
    private const CANDIDATE_LIMITS = [5, 10, 20, 40];

    private const MAX_CANDIDATE_LIMIT = 40;

    private const SIDE_LOW = 1;

    private const SIDE_HIGH = 2;

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
        private readonly SystemTriplePlanner $systemTriplePlanner,
        private readonly SystemHitFilters $hitFilters,
        private readonly SystemTeamRoleOptions $teamRoleOptions
    ) {}

    /**
     * @param  array{snapshots: array<int, array<string, mixed>>}  $lowState
     * @param  array{snapshots: array<int, array<string, mixed>>}  $highState
     * @param  array<int, bool>|null  $accountRoles
     * @param  array<int, int>  $lowBosses
     * @param  array<int, int>  $highBosses
     * @param  array<int, int>  $lowLockedIds
     * @param  array<int, int>  $highLockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<string, mixed>>
     */
    public function plan(
        array $lowState,
        array $highState,
        ?array $accountRoles,
        int $stage,
        int $atkType,
        array $lowBosses,
        array $highBosses,
        array $lowLockedIds,
        array $highLockedIds,
        array $hiddenIds,
        int $endpoint
    ): array {
        $snapshots = ($lowState['snapshots'] ?? [])
            + ($highState['snapshots'] ?? []);
        $roleMask = new SystemRoleMask(
            $this->teamRoleOptions->collectRoleIds($snapshots)
        );
        $publicTeams = $this->teamRoleOptions->buildProfiles(
            $snapshots,
            null,
            $roleMask
        );
        $accountTeams = $accountRoles === null
            ? $publicTeams
            : $this->teamRoleOptions->buildProfiles(
                $snapshots,
                $accountRoles,
                $roleMask
            );
        $usableTeamIds = $accountRoles === null
            ? null
            : $this->usableTeamIds($accountTeams);
        $lowCombinations = $this->buildPlannerCombinations($lowState);
        $highCombinations = $this->buildPlannerCombinations($highState);
        $lowCandidates = $this->buildTripleCandidates(
            $lowCombinations,
            self::SIDE_LOW,
            $stage,
            0,
            $lowBosses,
            $snapshots,
            $usableTeamIds,
            self::MAX_CANDIDATE_LIMIT,
            $lowLockedIds,
            $hiddenIds
        );
        $highCandidates = $this->buildTripleCandidates(
            $highCombinations,
            self::SIDE_HIGH,
            0,
            $atkType,
            $highBosses,
            $snapshots,
            $usableTeamIds,
            self::MAX_CANDIDATE_LIMIT,
            $highLockedIds,
            $hiddenIds
        );

        return $this->searchTopPackages(
            $lowCandidates,
            $highCandidates,
            $snapshots,
            $roleMask,
            $publicTeams,
            $accountTeams,
            $endpoint
        );
    }

    /**
     * @param  array{snapshots: array<int, array<string, mixed>>}  $state
     * @return array<int, array<int, int>>
     */
    private function buildPlannerCombinations(array $state): array
    {
        $built = $this->systemTriplePlanner->build(
            array_values($state['snapshots']),
            false
        );

        return array_map(
            static fn (array $record): array => array_map(
                'intval',
                $record['team_ids']
            ),
            $built['combinations']
        );
    }

    /**
     * @param  array<int, array<int, int>>  $combinations
     * @param  array<int, int>  $selectedBosses
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>|null  $usableTeamIds
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<string, mixed>>
     */
    private function buildTripleCandidates(
        array $combinations,
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
        $selectedBosses = $this->hitFilters->normalizeBosses($selectedBosses);
        $lockedLookup = array_fill_keys($lockedIds, true);
        $hiddenLookup = array_fill_keys($hiddenIds, true);
        $lockedCount = count($lockedLookup);
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
            $stageCounts = [2 => 0, 3 => 0, 4 => 0];
            $atkPositiveCount = 0;
            $atkNonpositiveCount = 0;
            $scoreTenths = 0;

            foreach ($orderedIds as $teamId) {
                $snapshot = $snapshots[$teamId];
                $boss = (int) $snapshot['boss'];
                $stageCounts[(int) $snapshot['stage']] =
                    ($stageCounts[(int) $snapshot['stage']] ?? 0) + 1;
                $scoreTenths += (int) $snapshot['damage']
                    * (self::MULTIPLIER_TENTHS[$boss] ?? 0);

                if ((int) $snapshot['atk_value'] >= 0) {
                    $atkPositiveCount++;
                }

                if ((int) $snapshot['atk_value'] <= 0) {
                    $atkNonpositiveCount++;
                }
            }

            if ($side === self::SIDE_LOW) {
                if ($stage === 2 && ($stageCounts[2] ?? 0) !== 3) {
                    continue;
                }

                if ($stage === 3 && ($stageCounts[3] ?? 0) !== 3) {
                    continue;
                }
            } else {
                if (! $this->hitFilters->passesAttackFilter(
                    $atkType,
                    $atkPositiveCount,
                    $atkNonpositiveCount
                )) {
                    continue;
                }
            }

            $rows[] = [
                'team_ids' => $orderedIds,
                'canonical_ids' => $canonicalIds,
                'score_tenths' => $scoreTenths,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $scoreComparison = $right['score_tenths']
                <=> $left['score_tenths'];

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            foreach ([0, 1, 2] as $index) {
                $comparison = $left['canonical_ids'][$index]
                    <=> $right['canonical_ids'][$index];

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        $candidates = [];

        foreach ($rows as $row) {
            $teamIds = $row['team_ids'];

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

            if (! $this->hitFilters->passesBossFilter(
                $comboBosses,
                $selectedBosses
            )) {
                continue;
            }

            $candidates[] = [
                'team_ids' => $teamIds,
                'canonical_ids' => $row['canonical_ids'],
                'score_tenths' => $row['score_tenths'],
            ];

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
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
        SystemRoleMask $roleMask,
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
        SystemRoleMask $roleMask,
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
        SystemRoleMask $roleMask,
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
        SystemRoleMask $roleMask,
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
        SystemRoleMask $roleMask
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
        SystemRoleMask $roleMask
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
}
