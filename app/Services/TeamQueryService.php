<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamLink;
use Illuminate\Database\Eloquent\Collection;

class TeamQueryService
{
    /**
     * 公会排刀选择器：查询当月 B/C/D 阶段系统作业。
     *
     * 该方法只供公会排刀使用，不改变现有 D 阶段作业列表查询。
     */
    public function getGuildTeamOptions(): array
    {
        $startOfMonth = now()->startOfMonth();
        $startOfNextMonth = $startOfMonth
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth();

        $teams = Team::query()
            ->where('uid', 0)
            ->whereIn('stage', [2, 3, 4])
            ->where('status', 1)
            ->where('created_at', '>=', $startOfMonth)
            ->where('created_at', '<', $startOfNextMonth)
            ->with([
                'teamRoles' => function ($query) {
                    $query->where('status', 1)
                        ->whereHas('role', function ($roleQuery) {
                            $roleQuery->where('status', 1);
                        })
                        ->with('role');
                },
                'links' => function ($query) {
                    $query->where('status', 1)
                        ->orderByDesc('damage')
                        ->orderBy('id');
                },
            ])
            ->orderBy('stage')
            ->orderBy('boss')
            ->orderByDesc('damage')
            ->orderBy('id')
            ->get();

        return $teams->map(function (Team $team): array {
            return $this->formatTeam(
                $team,
                $this->formatLinks($team->links)
            );
        })->values()->all();
    }

    /**
     * 按已保存作业 ID 查询最新作业展示数据。
     *
     * 已隐藏或物理删除的作业返回 deleted 标记，不抛出异常。
     */
    public function getGuildTeamSnapshots(array $teamIds): array
    {
        $teamIds = collect($teamIds)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($teamIds->isEmpty()) {
            return [];
        }

        $teams = Team::query()
            ->whereIn('id', $teamIds)
            ->with([
                'teamRoles' => function ($query) {
                    $query->where('status', 1)
                        ->whereHas('role', function ($roleQuery) {
                            $roleQuery->where('status', 1);
                        })
                        ->with('role');
                },
                'links' => function ($query) {
                    $query->where('status', 1)
                        ->orderByDesc('damage')
                        ->orderBy('id');
                },
            ])
            ->get()
            ->keyBy(fn (Team $team) => (int) $team->id);

        $snapshots = [];

        foreach ($teamIds as $teamId) {
            $team = $teams->get($teamId);

            if (! $team || (int) $team->status !== 1) {
                $snapshots[$teamId] = [
                    'id' => $teamId,
                    'deleted' => true,
                ];
                continue;
            }

            $snapshots[$teamId] = $this->formatTeam(
                $team,
                $this->formatLinks($team->links)
            );
            $snapshots[$teamId]['deleted'] = false;
        }

        return $snapshots;
    }

    /**
     * 获取系统作业列表。
     *
     * 保持原有系统作业查询规则：
     * 1. uid = 0
     * 2. D 阶段
     * 3. 当月创建
     * 4. 作业状态为启用
     * 5. 只返回启用的作业角色和有效角色定义
     * 6. 只返回有效攻略链接
     *
     * @param int|null $uid 当前普通用户 UID
     */
    public function getSystemTeams(?int $uid): array
    {
        $teams = $this->queryTeams(0, true);

        $teams = $this->sortTeams($teams);

        // 查询当前用户已添加的系统作业，以及其中状态为不可用的角色。
        $addedTeamsBySourceId = collect();

        if ($uid !== null) {
            $addedTeamsBySourceId = Team::query()
                ->where('uid', $uid)
                ->where('status', 1)
                ->whereNotNull('source_team_id')
                ->with([
                    'teamRoles' => function ($query) {
                        $query->where('status', 0)
                            ->orderBy('id');
                    },
                ])
                ->get()
                ->keyBy(fn (Team $userTeam) => (int) $userTeam->source_team_id);
        }

        return $teams->map(function (Team $team) use ($addedTeamsBySourceId) {
            $links = $this->formatLinks($team->links);

            $addedUserTeam = $addedTeamsBySourceId->get((int) $team->id);

            return $this->formatTeam(
                $team,
                $links,
                $addedUserTeam,
                true
            );
        })->values()->all();
    }

    /**
     * 获取当前用户的个人作业列表。
     *
     * 查询规则：
     * 1. 只查询当前 UID 的作业
     * 2. 只查询当月创建的作业
     * 3. 只查询 D 阶段
     * 4. 只查询启用的作业
     *
     * 用户作业的角色和来源链接分别处理：
     * - 角色始终读取用户自己的 team_roles，不过滤角色状态。
     * - 不受 game_roles.status 影响。
     * - 自定义作业没有来源链接。
     * - 复制作业读取原系统作业的有效链接。
     * - 原系统作业即使已停用，也不影响来源链接查询。
     * - 原系统作业不存在时，来源链接返回空数组。
     */
    public function getUserTeams(int $uid): array
    {
        $teams = $this->queryTeams($uid, false);

        $teams = $this->sortTeams($teams);

        // 收集复制作业的来源系统作业 ID。
        $sourceTeamIds = $teams
            ->pluck('source_team_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $linksBySourceId = collect();

        if ($sourceTeamIds->isNotEmpty()) {
            // 只确认来源系统作业是否仍然存在。
            // 不过滤 status，因此停用的系统作业仍可提供攻略链接。
            $existingSourceIds = Team::query()
                ->whereIn('id', $sourceTeamIds)
                ->where('uid', 0)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! empty($existingSourceIds)) {
                $linksBySourceId = TeamLink::query()
                    ->whereIn('team_id', $existingSourceIds)
                    ->where('status', 1)
                    ->orderByDesc('damage')
                    ->orderBy('id')
                    ->get()
                    ->groupBy(fn (TeamLink $link) => (int) $link->team_id);
            }
        }

        return $teams->map(function (Team $team) use ($linksBySourceId) {
            $links = [];

            // 自定义作业没有来源系统作业。
            if ($team->source_team_id !== null) {
                $sourceLinks = $linksBySourceId->get(
                    (int) $team->source_team_id,
                    collect()
                );

                $links = $this->formatLinks($sourceLinks);
            }

            // 用户作业不需要 has_add、missing_role_id 字段。
            return $this->formatTeam($team, $links);
        })->values()->all();
    }

    /**
     * 查询指定 UID 的当月 D 阶段有效作业。
     *
     * $systemTeams 为 true 时，使用系统作业的角色和链接过滤规则。
     * $systemTeams 为 false 时，保留用户作业的全部角色状态。
     */
    private function queryTeams(int $uid, bool $systemTeams): Collection
    {
        $startOfMonth = now()->startOfMonth();

        $startOfNextMonth = $startOfMonth
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth();

        $query = Team::query()
            ->where('uid', $uid)
            ->where('stage', 4)
            ->where('status', 1)
            ->where('created_at', '>=', $startOfMonth)
            ->where('created_at', '<', $startOfNextMonth);

        if ($systemTeams) {
            // 系统作业：只保留启用的作业角色，
            // 并且角色定义本身也必须启用。
            $query->with([
                'teamRoles' => function ($query) {
                    $query->where('status', 1)
                        ->whereHas('role', function ($roleQuery) {
                            $roleQuery->where('status', 1);
                        })
                        ->with('role');
                },

                // 只读取有效攻略链接。
                'links' => function ($query) {
                    $query->where('status', 1)
                        ->orderByDesc('damage')
                        ->orderBy('id');
                },
            ]);
        } else {
            // 用户作业：不限制 team_roles.status，
            // 也不限制 game_roles.status。
            $query->with([
                'teamRoles' => function ($query) {
                    $query->with('role');
                },
            ]);
        }

        return $query
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 对作业排序。
     *
     * 第一优先级：作业伤害降序。
     * 第二优先级：作业 ID 升序。
     */
    private function sortTeams(Collection $teams): Collection
    {
        return $teams
            ->sort(function (Team $a, Team $b): int {
                return ((int) $b->damage <=> (int) $a->damage)
                    ?: ((int) $a->id <=> (int) $b->id);
            })
            ->values();
    }

    /**
     * 组装作业角色数据。
     *
     * 不在这里过滤角色状态，由查询入口决定过滤规则。
     */
    private function formatRoles(Team $team): array
    {
        return $team->teamRoles
            ->sortByDesc(
                fn ($teamRole) => (int) (
                    $teamRole->role?->search_area_width ?? 0
                )
            )
            ->values()
            ->map(function ($teamRole) {
                $role = $teamRole->role;

                // 优先使用六星角色 ID，没有则使用三星角色 ID。
                $imageId = $role?->role_id_6 ?? $role?->role_id_3;

                // 由后端生成完整头像地址。
                $imageUrl = $imageId
                    ? url("images/roles/{$imageId}.webp")
                    : null;

                return [
                    'status' => (int) $teamRole->status,
                    'role_id' => (int) $teamRole->role_id,
                    'image_url' => $imageUrl,
                    'search_area_width' => (int) (
                        $role?->search_area_width ?? 0
                    ),
                ];
            })
            ->all();
    }

    /**
     * 组装攻略链接数据。
     */
    private function formatLinks(iterable $links): array
    {
        return collect($links)
            ->map(function ($link) {
                return [
                    'id' => (int) $link->id,
                    'url' => $link->url,
                    'author' => $link->author,
                    'difficulty' => (int) $link->difficulty,
                    'damage' => (int) $link->damage,
                    'note' => $link->note,
                    'status' => (int) $link->status,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 组装统一的作业返回数据。
     *
     * 系统作业可额外返回 has_add 和 missing_role_id；
     * 用户作业不返回这两个系统作业专属字段。
     */
    private function formatTeam(
        Team $team,
        array $links,
        ?Team $addedUserTeam = null,
        bool $includeAddFields = false
    ): array {
        $data = [
            'id' => (int) $team->id,
            'stage' => (int) $team->stage,
            'source_team_id' => $team->source_team_id,
            'uid' => (int) $team->uid,
            'boss' => (int) $team->boss,
            'damage' => (int) $team->damage,
            'team_key' => $team->team_key,
            'team' => $this->formatRoles($team),
            'status' => (int) $team->status,
            'mode' => (int) $team->mode,
            'atk_value' => (int) $team->atk_value,
            'remark' => $team->remark ?? '',
            'link' => $links,
            'import_timestamp' => $team->import_timestamp,
            'created_at' => $team->created_at,
            'updated_at' => $team->updated_at,
        ];

        if ($includeAddFields) {
            $missingRoleId = $addedUserTeam
                ?->teamRoles
                ->first()
                ?->role_id;

            $data['has_add'] = $addedUserTeam !== null ? 1 : 0;

            $data['missing_role_id'] = $missingRoleId === null
                ? null
                : (int) $missingRoleId;
        }

        return $data;
    }
}
