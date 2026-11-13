<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamLink;
use App\Models\TeamRole;
use App\Models\UserRolePool;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class SystemTripleService
{
    private const STAGE = 4;

    private const RESULT_LIMIT = 10;

    private const CACHE_VERSION = 2;

    private const MULTIPLIER_RATES = [0, 4.5, 4.5, 4.7, 4.8, 5];

    private const FORMAL_SOURCE_CACHE_KEY = 'systemTripleTeams';

    private const FORMAL_MAKE_TEAMS_CACHE_KEY = 'systemTripleTeamsMakeTeams';

    private const PUBLIC_CACHE_TTL_DAYS = 30;

    private const FORMAL_CACHE_LOCK_SECONDS = 120;

    private const FORMAL_CACHE_LOCK_WAIT_SECONDS = 15;

    public function __construct(
        private readonly SystemTriplePlanner $planner
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
            $this->resolveYearMonth($previousMonth),
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
        $context = $this->loadPublicContext(now()->format('Ym'));
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
        array $hiddenIds = []
    ): array {
        $startOfMonth = now()->startOfMonth();
        $startOfNextMonth = $startOfMonth
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth();

        $teams = $this->queryUserTeams(
            $uid,
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

        if ($difficulty > 0) {
            $snapshots = array_filter(
                $snapshots,
                fn (array $snapshot): bool => $this->snapshotHasDifficulty(
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
        $rows = $this->queryFormalSourceRows($yearMonth);
        $signature = $this->formalSourceSignature($rows);
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

                $snapshots = $this->buildFormalSnapshots($rows);
                $built = $this->planner->build(array_values($snapshots));

                return $this->formalContext(
                    $yearMonth,
                    $snapshots,
                    $built,
                    $signature
                );
            }

            $rows = $this->queryFormalSourceRows($yearMonth);
            $signature = $this->formalSourceSignature($rows);
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

            $snapshots = $source ?? $this->buildFormalSnapshots($rows);
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
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }
     */
    private function queryFormalSourceRows(string $yearMonth): array
    {
        $startOfMonth = $this->monthStart($yearMonth);
        $startOfNextMonth = $startOfMonth
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth();
        $teamRows = DB::table('teams')
            ->where('uid', 0)
            ->where('stage', self::STAGE)
            ->where('status', 1)
            ->where('created_at', '>=', $startOfMonth)
            ->where('created_at', '<', $startOfNextMonth)
            ->orderBy('id')
            ->get([
                'id',
                'stage',
                'status',
                'boss',
                'damage',
                'atk_value',
                'remark',
                'updated_at',
            ]);
        $teams = [];

        foreach ($teamRows as $row) {
            $teams[(int) $row->id] = [
                'id' => (int) $row->id,
                'stage' => (int) $row->stage,
                'status' => (int) $row->status,
                'boss' => (int) $row->boss,
                'damage' => (int) $row->damage,
                'atk_value' => (int) $row->atk_value,
                'remark' => (string) ($row->remark ?? ''),
                'updated_at' => $row->updated_at === null
                    ? null
                    : (string) $row->updated_at,
            ];
        }

        if ($teams === []) {
            return [
                'teams' => [],
                'roles' => [],
                'role_meta' => [],
                'links' => [],
            ];
        }

        $teamIds = array_keys($teams);
        $roles = [];
        $roleMeta = [];
        $roleRows = DB::table('team_roles as team_role')
            ->leftJoin(
                'game_roles as game_role',
                'game_role.id',
                '=',
                'team_role.role_id'
            )
            ->whereIn('team_role.team_id', $teamIds)
            ->orderBy('team_role.id')
            ->get([
                'team_role.team_id',
                'team_role.role_id',
                'team_role.status',
                'game_role.role_id_3',
                'game_role.role_id_6',
            ]);

        foreach ($roleRows as $row) {
            $teamId = (int) $row->team_id;
            $roleId = (int) $row->role_id;

            if ($roleId <= 0) {
                continue;
            }

            $roles[$teamId][] = [
                'role_id' => $roleId,
                'status' => (int) $row->status,
            ];
            $imageId = (int) ($row->role_id_6 ?? $row->role_id_3 ?? 0);
            $roleMeta[$roleId] = [
                'image_id' => $imageId > 0 ? $imageId : null,
            ];
        }

        $links = [];
        $linkRows = DB::table('team_links')
            ->whereIn('team_id', $teamIds)
            ->where('status', 1)
            ->orderByDesc('damage')
            ->orderBy('id')
            ->get([
                'id',
                'team_id',
                'url',
                'author',
                'difficulty',
                'damage',
                'note',
                'status',
            ]);

        foreach ($linkRows as $row) {
            $links[(int) $row->team_id][] = [
                'id' => (int) $row->id,
                'url' => $row->url,
                'author' => $row->author,
                'difficulty' => (int) $row->difficulty,
                'damage' => (int) $row->damage,
                'note' => $row->note,
                'status' => (int) $row->status,
            ];
        }

        return [
            'teams' => $teams,
            'roles' => $roles,
            'role_meta' => $roleMeta,
            'links' => $links,
        ];
    }

    /**
     * @param  array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }  $rows
     */
    private function formalSourceSignature(array $rows): string
    {
        $teams = [];

        foreach ($rows['teams'] as $teamId => $team) {
            $roleData = [];

            foreach (($rows['roles'][$teamId] ?? []) as $role) {
                $roleId = (int) $role['role_id'];
                $roleData[] = [
                    $roleId,
                    (int) $role['status'],
                    $rows['role_meta'][$roleId]['image_id'] ?? null,
                ];
            }

            $teams[] = [
                'id' => (int) $teamId,
                'stage' => (int) $team['stage'],
                'status' => (int) $team['status'],
                'boss' => (int) $team['boss'],
                'damage' => (int) $team['damage'],
                'atk_value' => (int) $team['atk_value'],
                'remark' => (string) $team['remark'],
                'updated_at' => $team['updated_at'],
                'roles' => $roleData,
                'links' => $rows['links'][$teamId] ?? [],
            ];
        }

        return hash('sha256', json_encode([
            'algorithm_version' => SystemTriplePlanner::CACHE_VERSION,
            'teams' => $teams,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildFormalSnapshots(array $rows): array
    {
        $snapshots = [];

        foreach ($rows['teams'] as $teamId => $team) {
            $roles = [];
            $roleDetails = [];
            $activeRoles = [];
            $inactiveCount = 0;

            foreach (($rows['roles'][$teamId] ?? []) as $role) {
                $roleId = (int) $role['role_id'];
                $status = (int) $role['status'];
                $roles[$roleId] = $status;

                if ($status === 0) {
                    $inactiveCount++;
                } else {
                    $activeRoles[$roleId] = true;
                }

                $roleDetails[$roleId] = [
                    'image_id' => $rows['role_meta'][$roleId]['image_id'] ?? null,
                    'image_url' => null,
                ];
            }

            $snapshots[(int) $teamId] = [
                'id' => (int) $teamId,
                'stage' => (int) $team['stage'],
                'status' => (int) $team['status'],
                'boss' => (int) $team['boss'],
                'damage' => (int) $team['damage'],
                'atk_value' => (int) $team['atk_value'],
                'remark' => (string) $team['remark'],
                'updated_at' => $team['updated_at'],
                'roles' => $roles,
                'role_details' => $roleDetails,
                'active_roles' => $activeRoles,
                'inactive_count' => $inactiveCount,
                'links' => $rows['links'][$teamId] ?? [],
            ];
        }

        return $snapshots;
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

    private function resolveYearMonth(bool $previousMonth): string
    {
        $month = $previousMonth
            ? now()->subMonthNoOverflow()->startOfMonth()
            : now()->startOfMonth();

        return $month->format('Ym');
    }

    private function monthStart(string $yearMonth): Carbon
    {
        return now()
            ->setDate(
                (int) substr($yearMonth, 0, 4),
                (int) substr($yearMonth, 4, 2),
                1
            )
            ->startOfDay();
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
        return $this->normalizeRoleIds($teamIds);
    }

    /**
     * 查询当前用户本月有效 D 阶段作业。
     */
    private function queryUserTeams(
        int $uid,
        Carbon $startOfMonth,
        Carbon $startOfNextMonth
    ): Collection {
        return Team::query()
            ->where('uid', $uid)
            ->where('stage', self::STAGE)
            ->where('status', 1)
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
     * 按作业可见链接中的难度筛选作业。
     */
    public function filterTeamsByDifficulty(
        Collection $teams,
        int $difficulty
    ): Collection {
        if ($difficulty <= 0) {
            return $teams;
        }

        return $teams
            ->filter(
                fn (Team $team): bool => $this->teamHasDifficulty(
                    $team,
                    $difficulty
                )
            )
            ->values();
    }

    /**
     * 判断作业是否包含指定难度的可见链接。
     */
    public function teamHasDifficulty(Team $team, int $difficulty): bool
    {
        return $difficulty <= 0
            || $this->linksContainDifficulty($team->links, $difficulty);
    }

    /**
     * @param  iterable<int, TeamLink>  $links
     */
    private function linksContainDifficulty(
        iterable $links,
        int $difficulty
    ): bool {
        foreach ($links as $link) {
            if ((int) $link->difficulty === $difficulty) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHasDifficulty(
        array $snapshot,
        int $difficulty
    ): bool {
        foreach ($snapshot['links'] as $link) {
            if ((int) ($link['difficulty'] ?? 0) === $difficulty) {
                return true;
            }
        }

        return false;
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
    ): array {
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

        return hash('sha256', json_encode([
            'algorithm_version' => self::CACHE_VERSION,
            'teams' => $data,
        ], JSON_THROW_ON_ERROR));
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
    ): array {
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
                $imageId = $details['image_id'] ?? null;
                $imageUrl = $details['image_url'] ?? null;

                if ($accountRoles !== null) {
                    $displayStatus = $displayStatus === 1
                        && isset($accountRoles[(int) $roleId])
                            ? 1
                            : 0;
                }

                $roles[] = [
                    'image_id' => $imageId,
                    'image_url' => $imageUrl ?? (
                        $imageId ? url("images/roles/{$imageId}.webp") : null
                    ),
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
