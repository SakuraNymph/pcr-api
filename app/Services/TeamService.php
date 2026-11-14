<?php

namespace App\Services;

use App\Models\GameRole;
use App\Models\Team;
use App\Models\TeamLink;
use App\Models\TeamRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamService
{
    private const ROLE_USAGE_LOCK_YEAR_MONTH = '0000-00';

    private const ROLE_USAGE_LOCK_MODE = 0;

    private const BOSS_HEALTH = [
        2 => [0, 4200, 4800, 5000, 5200, 6000],
        3 => [0, 7000, 8000, 9000, 10000, 12000],
    ];

    public function __construct(
        private readonly SystemTeamSourceService $teamSource
    ) {}

    /**
     * Return one row per source link, with the owning team and its roles.
     *
     * 未指定月份时只查询当前月，并按来源链接分页。
     */
    public function list(array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = TeamLink::query()->with(['team.teamRoles.role']);

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('team_links.status', (int) $filters['status']);
        }

        if (!empty($filters['author'])) {
            $query->where('team_links.author', 'like', '%' . $filters['author'] . '%');
        }

        if (!empty($filters['difficulty'])) {
            $query->where('team_links.difficulty', (int) $filters['difficulty']);
        }

        $month = trim((string) ($filters['month'] ?? ''));
        if ($month === '') {
            $month = now()->format('Y-m');
        }
        [$year, $monthNumber] = array_map('intval', explode('-', $month));

        $query->whereHas('team', function (Builder $teamQuery) use ($filters, $year, $monthNumber): void {
            foreach (['stage', 'boss'] as $field) {
                if (array_key_exists($field, $filters) && $filters[$field] !== null && $filters[$field] !== '') {
                    $teamQuery->where($field, (int) $filters[$field]);
                }
            }

            if (array_key_exists('mode', $filters) && $filters['mode'] !== null && $filters['mode'] !== '') {
                $teamQuery->where('mode', (int) $filters['mode']);
            }

            if (array_key_exists('atk_type', $filters) && $filters['atk_type'] !== null && $filters['atk_type'] !== '') {
                $atkType = (int) $filters['atk_type'];
                if ($atkType === 1) {
                    $teamQuery->where('atk_value', '>=', 0);
                } elseif ($atkType === -1) {
                    $teamQuery->where('atk_value', '<=', 0);
                }
            }

            $teamQuery->whereYear('created_at', $year)
                ->whereMonth('created_at', $monthNumber);
        });

        $links = $query->get();
        $links = $links->sort(function (TeamLink $a, TeamLink $b): int {
            $ta = $a->team;
            $tb = $b->team;
            return ((int) ($tb?->stage ?? 0) <=> (int) ($ta?->stage ?? 0))
                ?: ((int) ($ta?->boss ?? 0) <=> (int) ($tb?->boss ?? 0))
                ?: $this->compareLineups($ta, $tb)
                ?: ((int) $b->difficulty <=> (int) $a->difficulty)
                ?: ((int) $b->damage <=> (int) $a->damage)
                ?: ((int) $b->id <=> (int) $a->id);
        })->values();

        $total = $links->count();
        $items = $links
            ->forPage(max(1, $page), max(1, $perPage))
            ->values()
            ->map(fn (TeamLink $link) => $this->presentLink($link))
            ->values()
            ->all();

        return new LengthAwarePaginator(
            $items,
            $total,
            max(1, $perPage),
            max(1, $page)
        );
    }

    /** Find current-month visible D-stage lineups by the leftmost role nickname prefix. */
    public function dStageLineupOptions(string $prefix): Collection
    {
        $now = now();
        $teams = Team::query()
            ->with(['teamRoles.role'])
            ->where('stage', 4)
            ->where('status', 1)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->whereHas('teamRoles.role', fn (Builder $query) => $query->where('nickname', 'like', $prefix . '%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $seen = [];
        return $teams->filter(function (Team $team) use (&$seen): bool {
            $key = (string) $team->team_key;
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        })->map(function (Team $team) use ($prefix): ?array {
            $roles = $this->presentRoles($team);
            $leftmost = $roles->first();
            if (!$leftmost || !str_starts_with((string) $leftmost['nickname'], $prefix)) return null;
            return ['team_id' => $team->id, 'team_key' => $team->team_key, 'roles' => $roles];
        })->filter()->values();
    }

    private function compareLineups(?Team $a, ?Team $b): int
    {
        $roleIds = static function (?Team $team): array {
            $teamKey = (string) ($team?->team_key ?? '');
            if ($teamKey === '') return [];

            return array_map('intval', explode(',', $teamKey));
        };
        $left = $roleIds($a);
        $right = $roleIds($b);
        for ($i = 0; $i < max(count($left), count($right)); $i++) {
            $cmp = ($left[$i] ?? 0) <=> ($right[$i] ?? 0);
            if ($cmp !== 0) return $cmp;
        }
        return 0;
    }

    /** Create one team and source link per boss; reuse visible admin teams by signature. */
    public function createBatch(array $data): int
    {
        $monthStart = now()->startOfMonth();
        $linkCount = DB::transaction(function () use ($data, $monthStart): int {
            $linkCount = 0;
            $processedRoleUsageKeys = [];
            $nextMonthStart = $monthStart->copy()->addMonthNoOverflow();
            $yearMonth = $monthStart->format('Y-m');

            foreach ($data['records'] as $record) {
                $roles = $this->orderedRoles($record['roles']);
                $teamKey = $roles->pluck('id')->implode(',');
                $atkValue = (int) $roles->sum('atk_type');
                $stage = (int) $record['stage'];
                $difficulty = (int) $record['difficulty'];

                foreach ($record['boss'] as $bossValue) {
                    $boss = (int) $bossValue;
                    $damage = $this->damageFor($stage, $boss, $record['damage'] ?? null);
                    $mode = $this->modeForDifficulty($difficulty);

                    // Serialize matching submissions, including the first insert when no team exists yet.
                    DB::table('team_signature_locks')->insertOrIgnore([
                        'year_month' => $yearMonth,
                        'team_key' => $teamKey,
                        'mode' => $mode,
                        'stage' => $stage,
                        'boss' => $boss,
                    ]);
                    DB::table('team_signature_locks')
                        ->where('year_month', $yearMonth)
                        ->where('team_key', $teamKey)
                        ->where('mode', $mode)
                        ->where('stage', $stage)
                        ->where('boss', $boss)
                        ->lockForUpdate()
                        ->first();

                    $team = Team::query()
                        ->where('uid', 0)
                        ->where('stage', $stage)
                        ->where('boss', $boss)
                        ->where('team_key', $teamKey)
                        ->where('mode', $mode)
                        ->where('created_at', '>=', $monthStart)
                        ->where('created_at', '<', $nextMonthStart)
                        ->orderByDesc('status')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();

                    if (!$team) {
                        $team = Team::create([
                            'stage' => $stage,
                            'boss' => $boss,
                            'uid' => 0,
                            'damage' => $damage,
                            'team_key' => $teamKey,
                            'status' => 1,
                            'mode' => $mode,
                            'atk_value' => $atkValue,
                        ]);

                        foreach ($roles as $role) {
                            TeamRole::create([
                                'team_id' => $team->id,
                                'role_id' => $role->id,
                                'status' => 1,
                            ]);
                        }
                    }

                    $team->links()->create([
                        'url' => $data['link'],
                        'author' => trim($data['author']),
                        'difficulty' => $difficulty,
                        'damage' => $damage,
                        'status' => 1,
                    ]);

                    $teamDamage = (int) ($team->links()->max('damage') ?? $damage);
                    $team->update([
                        'damage' => max($teamDamage, $damage),
                        'status' => 1,
                    ]);
                    $this->recordRoleUsage(
                        $processedRoleUsageKeys,
                        $roles,
                        $stage,
                        $boss,
                        $teamKey,
                    );
                    $linkCount++;
                }
            }

            return $linkCount;
        }, 3);

        DB::afterCommit(function () use ($monthStart): void {
            $this->teamSource->forgetMonth($monthStart->format('Ym'));
        });

        return $linkCount;
    }

    private function recordRoleUsage(
        array &$processedKeys,
        Collection $roles,
        int $stage,
        int $boss,
        string $teamKey,
    ): void
    {
        $key = "{$stage}|{$boss}|{$teamKey}";

        if (isset($processedKeys[$key])) {
            return;
        }

        $processedKeys[$key] = true;

        $inserted = DB::table('team_signature_locks')->insertOrIgnore([
            'year_month' => self::ROLE_USAGE_LOCK_YEAR_MONTH,
            'team_key' => $teamKey,
            'mode' => self::ROLE_USAGE_LOCK_MODE,
            'stage' => $stage,
            'boss' => $boss,
        ]);

        if ($inserted !== 1) {
            return;
        }

        $now = now();

        foreach ($roles as $role) {
            DB::table('game_role_statistics')->insertOrIgnore([
                'game_role_id' => $role->id,
                'use_times' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('game_role_statistics')
                ->where('game_role_id', $role->id)
                ->increment('use_times', 1, ['updated_at' => $now]);
        }
    }

    /**
     * 修改单条作业：共享字段、角色集合与最低 id 的主来源链接。
     */
    public function update(Team $team, array $data): void
    {
        $yearMonth = null;

        DB::transaction(function () use (
            $team,
            $data,
            &$yearMonth
        ): void {
            $locked = Team::query()
                ->whereKey($team->id)
                ->lockForUpdate()
                ->firstOrFail();
            $yearMonth = $locked->created_at?->format('Ym');
            $roles = $this->orderedRoles($data['roles']);
            $stage = (int) $data['stage'];
            $boss = (int) $data['boss'];
            $difficulty = (int) $data['difficulty'];
            $damage = $this->damageFor(
                $stage,
                $boss,
                $data['damage'] ?? null,
            );

            $locked->update([
                'stage' => $stage,
                'boss' => $boss,
                'damage' => $damage,
                'team_key' => $roles->pluck('id')->implode(','),
                'mode' => $this->modeForDifficulty($difficulty),
                'atk_value' => (int) $roles->sum('atk_type'),
            ]);

            $this->syncRoles($locked, $roles);

            $linkData = [
                'author' => trim($data['author']),
                'url' => $data['link'],
                'difficulty' => $difficulty,
                'damage' => $damage,
            ];
            $primaryLink = $locked->links()
                ->orderBy('id')
                ->first();

            if ($primaryLink !== null) {
                $primaryLink->update($linkData);
            } else {
                $locked->links()->create($linkData + ['status' => 1]);
            }

            $locked->update([
                'damage' => (int) (
                    $locked->links()->max('damage') ?? $damage
                ),
            ]);
        });

        if ($yearMonth !== null) {
            DB::afterCommit(function () use ($yearMonth): void {
                $this->teamSource->forgetMonth($yearMonth);
            });
        }
    }

    private function syncRoles(Team $team, Collection $roles): void
    {
        $existing = TeamRole::query()
            ->where('team_id', $team->id)
            ->pluck('status', 'role_id');
        $existingRoleIds = $existing
            ->keys()
            ->map(static fn (mixed $roleId): int => (int) $roleId)
            ->all();
        $desiredRoleIds = $roles
            ->pluck('id')
            ->map(static fn (mixed $roleId): int => (int) $roleId)
            ->all();
        $removedRoleIds = array_values(array_diff(
            $existingRoleIds,
            $desiredRoleIds
        ));

        if ($removedRoleIds !== []) {
            TeamRole::query()
                ->where('team_id', $team->id)
                ->whereIn('role_id', $removedRoleIds)
                ->delete();
        }

        foreach ($roles as $role) {
            if ($existing->has($role->id)) {
                continue;
            }

            TeamRole::create([
                'team_id' => $team->id,
                'role_id' => $role->id,
                'status' => 1,
            ]);
        }
    }

    /** Update only the selected source link and keep team fields shared/read-only. */
    public function updateLink(Team $team, TeamLink $link, array $data): array
    {
        $result = DB::transaction(function () use ($team, $link, $data): array {
            $damage = $this->damageFor(
                (int) $team->stage,
                (int) $team->boss,
                $data['damage'] ?? null,
            );

            $link->update([
                'author' => trim($data['author']),
                'url' => $data['link'],
                'difficulty' => (int) $data['difficulty'],
                'damage' => $damage,
            ]);

            $team->update([
                'damage' => (int) ($team->links()->max('damage') ?? $damage),
            ]);

            return $this->presentLink($link->fresh(['team.teamRoles.role']));
        });

        $this->invalidateTeamMonth($team);

        return $result;
    }

    public function setLinkStatus(TeamLink $link, int $status): void
    {
        $link->update(['status' => $status]);

        $team = $link->team;

        if ($team !== null) {
            $this->invalidateTeamMonth($team);
        }
    }

    /** Legacy team-level status endpoint; apply the status to all its links. */
    public function setStatus(Team $team, int $status): void
    {
        DB::transaction(function () use ($team, $status): void {
            $team->update(['status' => $status]);
            $team->links()->update(['status' => $status]);
        });

        $this->invalidateTeamMonth($team);
    }

    private function invalidateTeamMonth(Team $team): void
    {
        $createdAt = $team->created_at;

        if ($createdAt === null) {
            return;
        }

        $yearMonth = $createdAt->format('Ym');

        DB::afterCommit(function () use ($yearMonth): void {
            $this->teamSource->forgetMonth($yearMonth);
        });
    }

    /** Stable team detail response retained for existing consumers. */
    public function present(Team $team): array
    {
        $team->loadMissing(['teamRoles.role', 'links']);

        $roles = $this->presentRoles($team);
        $links = $team->links
            ->sortBy('id')
            ->values()
            ->map(fn (TeamLink $link): array => [
                'id' => $link->id,
                'team_id' => $link->team_id,
                'author' => $link->author,
                'link' => $link->url,
                'url' => $link->url,
                'difficulty' => $link->difficulty,
                'damage' => $link->damage,
                'status' => $link->status,
            ]);

        return [
            'id' => $team->id,
            'stage' => $team->stage,
            'boss' => $team->boss,
            'mode' => $team->mode,
            'difficulty' => $links->first()['difficulty'] ?? null,
            'damage' => $team->damage,
            'atk_value' => $team->atk_value,
            'status' => $team->status,
            'created_at' => $team->created_at,
            'updated_at' => $team->updated_at,
            'roles' => $roles,
            'links' => $links,
        ];
    }

    private function presentLink(TeamLink $link): array
    {
        $link->loadMissing(['team.teamRoles.role']);
        $team = $link->team;

        return [
            'link_id' => $link->id,
            'team_id' => $link->team_id,
            'stage' => $team?->stage,
            'boss' => $team?->boss,
            'mode' => $this->modeForDifficulty((int) $link->difficulty),
            'difficulty' => $link->difficulty,
            'damage' => $link->damage,
            'team_damage' => $team?->damage,
            'atk_value' => $team?->atk_value,
            'team_key' => $team?->team_key,
            'status' => $link->status,
            'author' => $link->author,
            'link' => $link->url,
            'url' => $link->url,
            'created_at' => $team?->created_at,
            'updated_at' => $team?->updated_at,
            'roles' => $team ? $this->presentRoles($team) : [],
        ];
    }

    private function presentRoles(Team $team): Collection
    {
        return $team->teamRoles
            ->sort(function (TeamRole $a, TeamRole $b): int {
                return ((int) ($b->role?->search_area_width ?? 0) <=> (int) ($a->role?->search_area_width ?? 0))
                    ?: ((int) $a->role_id <=> (int) $b->role_id);
            })
            ->values()
            ->map(function (TeamRole $teamRole, int $index): array {
                $role = $teamRole->role;
                $imageId = $role?->role_id_6 ?? $role?->role_id_3;

                return [
                    'id' => $teamRole->id,
                    'team_id' => $teamRole->team_id,
                    'role_id' => $teamRole->role_id,
                    'nickname' => $role?->nickname,
                    'name' => $role?->name,
                    'image_url' => $imageId ? url("images/roles/{$imageId}.webp") : null,
                    'search_area_width' => $role?->search_area_width,
                    'sort' => $index,
                ];
            });
    }

    private function orderedRoles(array $roleIds): Collection
    {
        $roles = GameRole::query()
            ->whereIn('id', $roleIds)
            ->get()
            ->keyBy('id');

        $missingIds = array_diff(array_map('intval', $roleIds), $roles->keys()->map(fn ($id) => (int) $id)->all());
        if ($missingIds !== []) {
            throw ValidationException::withMessages([
                'roles' => ['所选角色不存在或已被删除，请重新选择。'],
            ]);
        }

        $ordered = array_map(fn ($id) => $roles->get((int) $id), $roleIds);
        usort($ordered, function (GameRole $a, GameRole $b): int {
            return ((int) $b->search_area_width <=> (int) $a->search_area_width)
                ?: ((int) $a->id <=> (int) $b->id);
        });

        return collect($ordered);
    }

    private function damageFor(int $stage, int $boss, mixed $inputDamage): int
    {
        if (isset(self::BOSS_HEALTH[$stage][$boss])) {
            return self::BOSS_HEALTH[$stage][$boss];
        }

        if ($stage !== 4) {
            throw ValidationException::withMessages([
                'stage' => ['当前作业只支持B、C、D阶段。'],
            ]);
        }

        if ($inputDamage === null || $inputDamage === '') {
            throw ValidationException::withMessages([
                'damage' => ['D阶段必须填写伤害。'],
            ]);
        }

        return (int) $inputDamage;
    }

    private function modeForDifficulty(int $difficulty): int
    {
        return $difficulty === 5 ? 2 : 1;
    }
}
