<?php

namespace App\Services;

use App\Models\GameRole;
use App\Models\Team;
use App\Models\TeamLink;
use App\Models\TeamRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamService
{
    private const BOSS_HEALTH = [
        2 => [0, 4200, 4800, 5000, 5200, 6000],
        3 => [0, 7000, 8000, 9000, 10000, 12000],
    ];

    /** Return one row per source link, with the owning team and its roles. */
    public function list(array $filters): Collection
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

        $query->whereHas('team', function (Builder $teamQuery) use ($filters): void {
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

            if (!empty($filters['month'])) {
                [$year, $month] = explode('-', $filters['month']);
                $teamQuery->whereYear('created_at', (int) $year)
                    ->whereMonth('created_at', (int) $month);
            }
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

        return $links->map(fn (TeamLink $link) => $this->presentLink($link))->values();
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
        return DB::transaction(function () use ($data): int {
            $linkCount = 0;
            $monthStart = now()->startOfMonth();
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
                    $linkCount++;
                }
            }

            return $linkCount;
        }, 3);
    }

    /** Update only the selected source link and keep team fields shared/read-only. */
    public function updateLink(Team $team, TeamLink $link, array $data): array
    {
        return DB::transaction(function () use ($team, $link, $data): array {
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
    }

    public function setLinkStatus(TeamLink $link, int $status): void
    {
        $link->update(['status' => $status]);
    }

    /** Legacy team-level status endpoint; apply the status to all its links. */
    public function setStatus(Team $team, int $status): void
    {
        DB::transaction(function () use ($team, $status): void {
            $team->update(['status' => $status]);
            $team->links()->update(['status' => $status]);
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
