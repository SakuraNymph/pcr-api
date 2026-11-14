<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamLink;
use App\Models\TeamRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

final class SystemTeamContextService
{
    private const SNAPSHOT_SIGNATURE_VERSION = 2;

    private const FORMAL_SOURCE_STAGE = 4;

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public function monthRange(bool $previousMonth): array
    {
        $start = now()->startOfMonth();

        if ($previousMonth) {
            $start = $start->copy()->subMonthNoOverflow()->startOfMonth();
        }

        $end = $start->copy()->addMonthNoOverflow()->startOfMonth();

        return [$start, $end, $start->format('Ym')];
    }

    public function monthStart(string $yearMonth): Carbon
    {
        return now()
            ->setDate(
                (int) substr($yearMonth, 0, 4),
                (int) substr($yearMonth, 4, 2),
                1
            )
            ->startOfDay();
    }

    /**
     * @param  array<int, int>  $stages
     * @return Collection<int, Team>
     */
    public function queryTeams(
        int $uid,
        array $stages,
        Carbon $start,
        Carbon $end
    ): Collection {
        $stages = array_values(array_unique(array_map(
            static fn (mixed $stage): int => (int) $stage,
            $stages
        )));

        return Team::query()
            ->where('uid', $uid)
            ->whereIn('stage', $stages)
            ->where('status', 1)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
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
        $snapshots = $filtered
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
     * 使用源数据行构建指定阶段的作业快照状态。
     *
     * @param  array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }  $rows
     * @param  array<int, int>  $stages
     * @return array{
     *     snapshots: array<int, array<string, mixed>>,
     *     team_ids: string,
     *     team_count: int,
     *     signature: string
     * }
     */
    public function buildSnapshotStateFromRows(
        array $rows,
        array $stages
    ): array {
        $normalizedStages = array_values(array_unique(array_map(
            static fn (mixed $stage): int => (int) $stage,
            $stages
        )));
        $filtered = array_filter(
            $rows['teams'],
            static fn (array $team): bool => in_array(
                (int) $team['stage'],
                $normalizedStages,
                true
            )
        );
        $filteredRows = $rows;
        $filteredRows['teams'] = $filtered;
        $snapshots = $this->buildFormalSnapshots($filteredRows);
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
     * @return array<string, mixed>
     */
    public function makeSnapshot(
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
                'image_url' => $imageId
                    ? url("images/roles/{$imageId}.webp")
                    : null,
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
    public function formatLinks(iterable $links): array
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
    public function signature(array $snapshots): string
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
            'algorithm_version' => self::SNAPSHOT_SIGNATURE_VERSION,
            'teams' => $data,
        ], JSON_THROW_ON_ERROR));
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
        if ($difficulty <= 0) {
            return true;
        }

        foreach ($team->links as $link) {
            if ((int) $link->difficulty === $difficulty) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function snapshotHasDifficulty(
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
    public function queryUserSourceLinks(
        Collection $teams
    ): SupportCollection {
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
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }
     */
    public function queryFormalSourceRows(string $yearMonth): array
    {
        return $this->querySourceRows(
            $yearMonth,
            [self::FORMAL_SOURCE_STAGE]
        );
    }

    /**
     * @param  array<int, int>  $stages
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }
     */
    public function querySourceRows(
        string $yearMonth,
        array $stages
    ): array {
        $stages = array_values(array_unique(array_map(
            static fn (mixed $stage): int => (int) $stage,
            $stages
        )));
        sort($stages, SORT_NUMERIC);
        $startOfMonth = $this->monthStart($yearMonth);
        $startOfNextMonth = $startOfMonth
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth();
        $teamRows = DB::table('teams')
            ->where('uid', 0)
            ->whereIn('stage', $stages)
            ->where('status', 1)
            ->where('created_at', '>=', $startOfMonth)
            ->where('created_at', '<', $startOfNextMonth)
            ->orderBy('id')
            ->get([
                'id',
                'stage',
                'status',
                'mode',
                'boss',
                'damage',
                'atk_value',
                'remark',
                'created_at',
                'updated_at',
            ]);
        $teams = [];

        foreach ($teamRows as $row) {
            $teams[(int) $row->id] = [
                'id' => (int) $row->id,
                'stage' => (int) $row->stage,
                'status' => (int) $row->status,
                'mode' => (int) $row->mode,
                'boss' => (int) $row->boss,
                'damage' => (int) $row->damage,
                'atk_value' => (int) $row->atk_value,
                'remark' => (string) ($row->remark ?? ''),
                'created_at' => $row->created_at === null
                    ? null
                    : (string) $row->created_at,
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
    public function formalSourceSignature(array $rows): string
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
    public function buildFormalSnapshots(array $rows): array
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
                'mode' => (int) ($team['mode'] ?? 0),
                'boss' => (int) $team['boss'],
                'damage' => (int) $team['damage'],
                'atk_value' => (int) $team['atk_value'],
                'remark' => (string) $team['remark'],
                'created_at' => $this->formatTimestamp(
                    $team['created_at'] ?? null
                ),
                'updated_at' => $this->formatTimestamp(
                    $team['updated_at'] ?? null
                ),
                'roles' => $roles,
                'role_details' => $roleDetails,
                'active_roles' => $activeRoles,
                'inactive_count' => $inactiveCount,
                'links' => $rows['links'][$teamId] ?? [],
            ];
        }

        return $snapshots;
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)
            ->format('Y-m-d H:i:s.u');
    }
}
