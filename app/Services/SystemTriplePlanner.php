<?php

namespace App\Services;

final class SystemTriplePlanner
{
    public const CACHE_VERSION = 3;

    public const RESULT_LIMIT = 10;

    public const DIFFICULTIES = [
        1 => 'AUTO',
        2 => 'SIMPLE_SET',
        3 => 'SWITCH_SET',
        4 => 'SIMPLE_EYE',
        5 => 'EYE',
    ];

    private const MULTIPLIER_TENTHS = [0, 45, 45, 47, 48, 50];

    private const BOSS_BITS = 2;

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array{
     *     combinations: array<int, array<string, mixed>>,
     *     groups: array<int|string, array<int, int>>,
     *     stats: array<string, int>
     * }
     */
    public function build(array $snapshots): array
    {
        $teams = array_values($snapshots);
        $count = count($teams);
        $theoretical = $count >= 3
            ? intdiv($count * ($count - 1) * ($count - 2), 6)
            : 0;
        $groups = [
            1 => [],
            2 => [],
            3 => [],
            4 => [],
            5 => [],
            'all' => [],
        ];

        if ($count < 3) {
            return [
                'combinations' => [],
                'groups' => $groups,
                'stats' => [
                    'theoretical' => $theoretical,
                    'triangles' => 0,
                    'pair_rejected' => $theoretical,
                    'group_rejected' => 0,
                    'no_common_difficulty' => 0,
                    'accepted' => 0,
                ],
            ];
        }

        $pairCompatible = [];
        $neighbors = array_fill(0, $count, []);
        $difficultyMasks = [];

        foreach ($teams as $index => $team) {
            $difficultyMasks[$index] = $this->difficultyMask($team);
        }

        for ($left = 0; $left < $count - 1; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                if (! $this->isPairCompatible($teams[$left], $teams[$right])) {
                    continue;
                }

                $pairCompatible[$left][$right] = true;
                $neighbors[$left][] = $right;
            }
        }

        $records = [];
        $triangles = 0;
        $groupRejected = 0;
        $noCommonDifficulty = 0;
        $order = 0;

        for ($first = 0; $first < $count - 2; $first++) {
            foreach ($neighbors[$first] as $second) {
                foreach ($neighbors[$second] as $third) {
                    if ($third <= $second || ! isset($pairCompatible[$first][$third])) {
                        continue;
                    }

                    $triangles++;
                    $group = [$teams[$first], $teams[$second], $teams[$third]];

                    if (! $this->isRoleOverlapAllowed($group)) {
                        $groupRejected++;

                        continue;
                    }

                    $difficultyMask = $difficultyMasks[$first]
                        & $difficultyMasks[$second]
                        & $difficultyMasks[$third];

                    if ($difficultyMask === 0) {
                        $noCommonDifficulty++;

                        continue;
                    }

                    $groupById = [];

                    foreach ($group as $team) {
                        $groupById[(int) $team['id']] = $team;
                    }

                    $teamIds = array_keys($groupById);
                    sort($teamIds, SORT_NUMERIC);
                    usort($teamIds, static function (int $left, int $right) use ($groupById): int {
                        $leftBoss = (int) $groupById[$left]['boss'];
                        $rightBoss = (int) $groupById[$right]['boss'];

                        return $leftBoss <=> $rightBoss ?: $left <=> $right;
                    });

                    $bossMask = 0;
                    $physicalCount = 0;
                    $magicCount = 0;
                    $scoreTenths = 0;

                    foreach ($teamIds as $teamId) {
                        $team = $groupById[$teamId];
                        $boss = (int) $team['boss'];
                        $bossMask += 1 << (($boss - 1) * self::BOSS_BITS);
                        $scoreTenths += (int) $team['damage']
                            * (self::MULTIPLIER_TENTHS[$boss] ?? 0);

                        if ((int) $team['atk_value'] >= 0) {
                            $physicalCount++;
                        }

                        if ((int) $team['atk_value'] <= 0) {
                            $magicCount++;
                        }
                    }

                    $records[] = [
                        'team_ids' => $teamIds,
                        'score_tenths' => $scoreTenths,
                        'boss_mask' => $bossMask,
                        'physical_count' => $physicalCount,
                        'magic_count' => $magicCount,
                        '_difficulty_mask' => $difficultyMask,
                        '_order' => $order++,
                    ];
                }
            }
        }

        usort($records, static function (array $left, array $right): int {
            return $right['score_tenths'] <=> $left['score_tenths']
                ?: $left['_order'] <=> $right['_order'];
        });

        $combinations = [];

        foreach ($records as $index => $record) {
            $difficultyMask = (int) $record['_difficulty_mask'];
            unset($record['_difficulty_mask'], $record['_order']);
            $combinations[$index] = $record;
            $groups['all'][] = $index;

            foreach (array_keys(self::DIFFICULTIES) as $difficulty) {
                if (($difficultyMask & (1 << ($difficulty - 1))) !== 0) {
                    $groups[$difficulty][] = $index;
                }
            }
        }

        $accepted = count($combinations);

        return [
            'combinations' => $combinations,
            'groups' => $groups,
            'stats' => [
                'theoretical' => $theoretical,
                'triangles' => $triangles,
                'pair_rejected' => $theoretical - $triangles,
                'group_rejected' => $groupRejected,
                'no_common_difficulty' => $noCommonDifficulty,
                'accepted' => $accepted,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $combinations
     * @param  array<int|string, array<int, int>>  $groups
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array<int, int>>
     */
    public function filterPublic(
        array $combinations,
        array $groups,
        int $difficulty,
        array $bossMap = [],
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = [],
        ?array &$stats = null
    ): array {
        $stats = [
            'scanned' => 0,
            'locked_hidden_rejected' => 0,
            'boss_rejected' => 0,
            'attack_rejected' => 0,
            'accepted' => 0,
        ];
        $filters = $this->makeFilterState($bossMap, $lockedIds, $hiddenIds);
        $result = [];

        if ($filters['locked_count'] > 3) {
            return $result;
        }

        foreach ($this->groupIndexes($groups, $difficulty) as $index) {
            $record = $combinations[$index] ?? null;

            if (! is_array($record)) {
                continue;
            }

            $stats['scanned']++;

            if (! $this->passesLockedHidden($record['team_ids'], $filters)) {
                $stats['locked_hidden_rejected']++;

                continue;
            }

            if (! $this->passesBossFilter($record['boss_mask'], $filters)) {
                $stats['boss_rejected']++;

                continue;
            }

            if (! $this->passesAttackFilter(
                $atkType,
                (int) $record['physical_count'],
                (int) $record['magic_count']
            )) {
                $stats['attack_rejected']++;

                continue;
            }

            $result[] = array_map('intval', $record['team_ids']);
            $stats['accepted']++;

            if (count($result) >= self::RESULT_LIMIT) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $combinations
     * @param  array<int|string, array<int, int>>  $groups
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>  $accountRoles
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<int, array{team_ids: array<int, int>, borrows: array<int, int>}>
     */
    public function matchAccount(
        array $combinations,
        array $groups,
        int $difficulty,
        array $snapshots,
        array $accountRoles,
        array $bossMap = [],
        int $atkType = 0,
        array $lockedIds = [],
        array $hiddenIds = [],
        ?array &$stats = null
    ): array {
        $stats = [
            'scanned' => 0,
            'locked_hidden_rejected' => 0,
            'boss_rejected' => 0,
            'attack_rejected' => 0,
            'unusable_team_rejected' => 0,
            'borrow_rejected' => 0,
            'borrow_option_trials' => 0,
            'accepted' => 0,
        ];
        $filters = $this->makeFilterState($bossMap, $lockedIds, $hiddenIds);
        $result = [];

        if ($filters['locked_count'] > 3) {
            return $result;
        }

        $roleMask = new SystemSixHitRoleMask($this->collectRoleIds($snapshots));
        $teamOptions = $this->makeAccountTeamOptions(
            $snapshots,
            $accountRoles,
            $roleMask
        );

        foreach ($this->groupIndexes($groups, $difficulty) as $index) {
            $record = $combinations[$index] ?? null;

            if (! is_array($record)) {
                continue;
            }

            $stats['scanned']++;
            $teamIds = array_map('intval', $record['team_ids']);

            if (! $this->passesLockedHidden($teamIds, $filters)) {
                $stats['locked_hidden_rejected']++;

                continue;
            }

            if (! $this->passesBossFilter($record['boss_mask'], $filters)) {
                $stats['boss_rejected']++;

                continue;
            }

            if (! $this->passesAttackFilter(
                $atkType,
                (int) $record['physical_count'],
                (int) $record['magic_count']
            )) {
                $stats['attack_rejected']++;

                continue;
            }

            $optionSets = [];
            $unusable = false;

            foreach ($teamIds as $teamId) {
                $options = $teamOptions[$teamId] ?? [];

                if ($options === []) {
                    $unusable = true;
                    break;
                }

                $optionSets[] = $options;
            }

            if ($unusable) {
                $stats['unusable_team_rejected']++;

                continue;
            }

            $assignment = $this->findBorrowAssignment(
                $optionSets,
                $roleMask,
                $stats['borrow_option_trials']
            );

            if ($assignment === null) {
                $stats['borrow_rejected']++;

                continue;
            }

            $result[] = [
                'team_ids' => $teamIds,
                'borrows' => [
                    $teamIds[0] => (int) $assignment[0]['role'],
                    $teamIds[1] => (int) $assignment[1]['role'],
                    $teamIds[2] => (int) $assignment[2]['role'],
                ],
            ];
            $stats['accepted']++;

            if (count($result) >= self::RESULT_LIMIT) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $combinations
     * @return array<int, array<int, mixed>>
     */
    public function encode(array $combinations): array
    {
        return array_map(static function (array $record): array {
            return [
                array_map('intval', $record['team_ids']),
                (int) $record['score_tenths'],
                (int) $record['boss_mask'],
                (int) $record['physical_count'],
                (int) $record['magic_count'],
            ];
        }, $combinations);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function decode(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $result = [];

        foreach ($payload as $index => $item) {
            if (
                ! is_array($item)
                || count($item) !== 5
                || ! is_array($item[0] ?? null)
                || count($item[0]) !== 3
            ) {
                return null;
            }

            $teamIds = array_values(array_map('intval', $item[0]));

            $result[(int) $index] = [
                'team_ids' => $teamIds,
                'score_tenths' => (int) ($item[1] ?? 0),
                'boss_mask' => (int) ($item[2] ?? 0),
                'physical_count' => (int) ($item[3] ?? 0),
                'magic_count' => (int) ($item[4] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param  array{
     *     combinations: array<int, array<string, mixed>>,
     *     groups: array<int|string, array<int, int>>,
     *     stats: array<string, int>
     * }  $built
     * @return array<string, mixed>
     */
    public function buildFormalPayload(
        array $built,
        string $yearMonth,
        string $signature
    ): array {
        $groups = [];

        foreach (array_keys(self::DIFFICULTIES) as $difficulty) {
            $groups[$difficulty] = array_values(array_map(
                'intval',
                $built['groups'][$difficulty] ?? []
            ));
        }

        return [
            'version' => self::CACHE_VERSION,
            'year_month' => $yearMonth,
            'signature' => $signature,
            'labels' => self::DIFFICULTIES,
            'combinations' => $this->encode($built['combinations']),
            'groups' => $groups,
            'stats' => $built['stats'],
        ];
    }

    /**
     * @return array{
     *     combinations: array<int, array<string, mixed>>,
     *     groups: array<int, array<int, int>>,
     *     stats: array<string, int>
     * }|null
     */
    public function decodeFormalPayload(
        mixed $payload,
        string $yearMonth,
        string $signature
    ): ?array {
        if (
            ! is_array($payload)
            || (int) ($payload['version'] ?? 0) !== self::CACHE_VERSION
            || (string) ($payload['year_month'] ?? '') !== $yearMonth
            || (string) ($payload['signature'] ?? '') !== $signature
            || ! is_array($payload['groups'] ?? null)
        ) {
            return null;
        }

        $groups = [];

        foreach (array_keys(self::DIFFICULTIES) as $difficulty) {
            $indexes = $payload['groups'][$difficulty] ?? null;

            if (! is_array($indexes)) {
                return null;
            }

            $groups[$difficulty] = array_values(array_map('intval', $indexes));
        }

        $combinations = $this->decode($payload['combinations'] ?? null);

        if ($combinations === null) {
            return null;
        }

        foreach ($groups as $indexes) {
            foreach ($indexes as $index) {
                if (! isset($combinations[$index])) {
                    return null;
                }
            }
        }

        $stats = $payload['stats'] ?? null;

        if (! is_array($stats)) {
            return null;
        }

        return [
            'combinations' => $combinations,
            'groups' => $groups,
            'stats' => array_map('intval', $stats),
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function isPairCompatible(array $left, array $right): bool
    {
        return (int) $left['inactive_count']
            + (int) $right['inactive_count']
            + $this->countSharedActiveRoles($left, $right) <= 2;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function countSharedActiveRoles(array $left, array $right): int
    {
        $leftRoles = $left['active_roles'] ?? [];
        $rightRoles = $right['active_roles'] ?? [];

        if (count($leftRoles) <= count($rightRoles)) {
            $smaller = $leftRoles;
            $larger = $rightRoles;
        } else {
            $smaller = $rightRoles;
            $larger = $leftRoles;
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
     * @param  array<string, mixed>  $team
     */
    private function difficultyMask(array $team): int
    {
        $mask = 0;

        foreach (($team['links'] ?? []) as $link) {
            $difficulty = (int) ($link['difficulty'] ?? 0);

            if (
                (int) ($link['status'] ?? 1) === 1
                && isset(self::DIFFICULTIES[$difficulty])
            ) {
                $mask |= 1 << ($difficulty - 1);
            }
        }

        return $mask;
    }

    /**
     * @param  array<int, array<string, mixed>>  $group
     */
    private function isRoleOverlapAllowed(array $group): bool
    {
        $roleCounts = [];
        $inactiveCount = 0;

        foreach ($group as $team) {
            $inactiveCount += (int) $team['inactive_count'];

            foreach (($team['roles'] ?? []) as $roleId => $_) {
                $roleId = (int) $roleId;
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
     * @param  array<int, int>  $bossMap
     * @param  array<int, int>  $lockedIds
     * @param  array<int, int>  $hiddenIds
     * @return array<string, mixed>
     */
    private function makeFilterState(
        array $bossMap,
        array $lockedIds,
        array $hiddenIds
    ): array {
        $bossCounts = array_fill(0, 5, 0);
        $selectedCount = 0;

        foreach ($bossMap as $boss) {
            $boss = (int) $boss;

            if ($boss < 1 || $boss > 5) {
                continue;
            }

            $bossCounts[$boss - 1]++;
            $selectedCount++;
        }

        $selectedMask = 0;

        foreach ($bossCounts as $index => $count) {
            $selectedMask += $count << ($index * self::BOSS_BITS);
        }

        $locked = array_values(array_map('intval', $lockedIds));
        $hidden = array_values(array_map('intval', $hiddenIds));

        return [
            'selected_count' => $selectedCount,
            'selected_mask' => $selectedMask,
            'locked_count' => count($locked),
            'locked' => array_fill_keys($locked, true),
            'hidden' => array_fill_keys($hidden, true),
        ];
    }

    /**
     * @param  array<int|string, array<int, int>>  $groups
     * @return array<int, int>
     */
    private function groupIndexes(array $groups, int $difficulty): array
    {
        if ($difficulty === 0) {
            return array_values(array_map(
                'intval',
                $groups['all'] ?? []
            ));
        }

        return array_values(array_map(
            'intval',
            $groups[$difficulty] ?? []
        ));
    }

    /**
     * @param  array<int, int>  $teamIds
     * @param  array<string, mixed>  $filters
     */
    private function passesLockedHidden(array $teamIds, array $filters): bool
    {
        $matchedLocked = 0;

        foreach ($teamIds as $teamId) {
            $teamId = (int) $teamId;

            if (isset($filters['hidden'][$teamId])) {
                return false;
            }

            if (isset($filters['locked'][$teamId])) {
                $matchedLocked++;
            }
        }

        return $matchedLocked === $filters['locked_count'];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function passesBossFilter(int $comboMask, array $filters): bool
    {
        $selectedCount = (int) $filters['selected_count'];

        if ($selectedCount === 0) {
            return true;
        }

        if ($selectedCount === 1) {
            for ($index = 0; $index < 5; $index++) {
                if (
                    ($filters['selected_mask'] & (1 << ($index * self::BOSS_BITS))) !== 0
                    && ($comboMask & (3 << ($index * self::BOSS_BITS))) !== 0
                ) {
                    return true;
                }
            }

            return false;
        }

        if ($selectedCount === 2) {
            for ($index = 0; $index < 5; $index++) {
                $selected = ($filters['selected_mask'] >> ($index * self::BOSS_BITS)) & 3;

                if ($selected === 0) {
                    continue;
                }

                if ($selected === 1) {
                    $combo = ($comboMask >> ($index * self::BOSS_BITS)) & 3;

                    if ($combo === 0) {
                        return false;
                    }
                } elseif ($selected === 2) {
                    $combo = ($comboMask >> ($index * self::BOSS_BITS)) & 3;

                    if ($combo < 2) {
                        return false;
                    }
                }
            }

            return true;
        }

        return $comboMask === $filters['selected_mask'];
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
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, int>
     */
    private function collectRoleIds(array $snapshots): array
    {
        $roleIds = [];

        foreach ($snapshots as $snapshot) {
            foreach (($snapshot['roles'] ?? []) as $roleId => $_) {
                $roleIds[] = (int) $roleId;
            }
        }

        $roleIds = array_values(array_unique(array_filter(
            $roleIds,
            static fn (int $roleId): bool => $roleId > 0
        )));
        sort($roleIds, SORT_NUMERIC);

        return $roleIds;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>  $accountRoles
     * @return array<int, array<int, array{role: int, mask: array<int, int>, count: int}>>
     */
    private function makeAccountTeamOptions(
        array $snapshots,
        array $accountRoles,
        SystemSixHitRoleMask $roleMask
    ): array {
        $teamOptions = [];

        foreach ($snapshots as $teamId => $team) {
            $mandatoryRoles = [];
            $optionalRoles = [];

            foreach (($team['roles'] ?? []) as $roleId => $status) {
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
                $teamOptions[(int) $teamId] = [];

                continue;
            }

            $effectiveMask = $roleMask->maskFromRoleIds($optionalRoles);

            if (count($mandatoryRoles) === 1) {
                $teamOptions[(int) $teamId] = [[
                    'role' => (int) $mandatoryRoles[0],
                    'mask' => $effectiveMask,
                    'count' => 1,
                ]];

                continue;
            }

            $options = [[
                'role' => 0,
                'mask' => $effectiveMask,
                'count' => 0,
            ]];
            $seenMasks = [implode(',', $effectiveMask) => true];

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

            $teamOptions[(int) $teamId] = $options;
        }

        return $teamOptions;
    }

    /**
     * @param  array<int, array<int, array{role: int, mask: array<int, int>, count: int}>>  $optionSets
     * @return array<int, array{role: int, mask: array<int, int>, count: int}>|null
     */
    private function findBorrowAssignment(
        array $optionSets,
        SystemSixHitRoleMask $roleMask,
        int &$trials
    ): ?array {
        if (count($optionSets) !== 3) {
            return null;
        }

        $best = null;

        foreach ($optionSets[0] as $first) {
            foreach ($optionSets[1] as $second) {
                if ($roleMask->intersects($first['mask'], $second['mask'])) {
                    continue;
                }

                $firstTwoMask = $roleMask->union($first['mask'], $second['mask']);

                foreach ($optionSets[2] as $third) {
                    $trials++;
                    $count = $first['count'] + $second['count'] + $third['count'];

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
                        'count' => $count,
                        'options' => [$first, $second, $third],
                    ];
                }
            }
        }

        return $best === null ? null : $best['options'];
    }

    /**
     * @param  array<int, int>  $teamIds
     * @return array<int, int>
     */
    private function normalizeTeamIds(array $teamIds): array
    {
        $normalized = [];

        foreach ($teamIds as $teamId) {
            $teamId = (int) $teamId;

            if ($teamId > 0) {
                $normalized[$teamId] = $teamId;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
