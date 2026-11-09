<?php

namespace App\Services;

use App\Exceptions\GuildException;
use App\Models\Guild;
use App\Models\GuildLog;
use App\Models\GuildMember;
use App\Models\GuildMonth;
use App\Models\GuildMonthMember;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class GuildScheduleService
{
    private const MAIN_FIELDS = ['team_1', 'team_2', 'team_3'];

    private const RETURN_FIELDS = [
        'team_1_return',
        'team_2_return',
        'team_3_return',
    ];

    public function __construct(
        private readonly GuildService $guildService,
        private readonly GuildLogService $guildLogService,
        private readonly TeamQueryService $teamQueryService,
        private readonly UserProfileService $userProfileService
    ) {}

    public function show(int $guildId, int $uid, ?string $month): array
    {
        $guild = Guild::query()
            ->with('owner:id,nickname,avatar,status')
            ->whereKey($guildId)
            ->where('status', Guild::STATUS_ACTIVE)
            ->first();

        if (! $guild) {
            throw GuildException::notFound();
        }

        $membership = $this->guildService->requireMembership($guild, $uid);
        $currentMonth = now()->format('Y-m');
        $monthKey = $this->normalizeMonth($month);
        $isCurrentMonth = $monthKey === $currentMonth;

        if ($isCurrentMonth) {
            $monthRecord = DB::transaction(function () use (
                $guildId,
                $guild
            ): GuildMonth {
                $lockedGuild = $this->guildService->lockActiveGuild($guildId);
                $month = $this->ensureCurrentMonth($lockedGuild);
                $this->syncCurrentMonthRows($lockedGuild, $month);
                $this->cleanupDStageReturns($month);

                return $month;
            });
        } else {
            $monthRecord = GuildMonth::query()
                ->where('guild_id', $guildId)
                ->where('year_month', $monthKey)
                ->first();
        }

        $rows = $monthRecord
            ? GuildMonthMember::query()
                ->where('month_id', $monthRecord->id)
                ->orderBy('role')
                ->orderBy('member_slot')
                ->get()
            : collect();

        $currentNicknames = $isCurrentMonth
            ? User::query()
                ->whereIn('id', $rows->pluck('uid')->all())
                ->pluck('nickname', 'id')
            : collect();

        $teamIds = $rows
            ->flatMap(fn (GuildMonthMember $row) => [
                $row->team_1,
                $row->team_1_return,
                $row->team_2,
                $row->team_2_return,
                $row->team_3,
                $row->team_3_return,
            ])
            ->filter()
            ->all();

        $snapshots = $this->teamQueryService->getGuildTeamSnapshots($teamIds);
        $stages = $this->teamStages($teamIds);
        $viewerRole = (int) $membership->role;
        $canEditOthers = in_array($viewerRole, [
            GuildMember::ROLE_OWNER,
            GuildMember::ROLE_VICE,
        ], true);

        return [
            'guild' => [
                'id' => (int) $guild->id,
                'name' => $guild->name,
                'avatar_url' => $this->userProfileService->avatarUrl(
                    $guild->owner?->avatar !== null
                        ? (int) $guild->owner->avatar
                        : null
                ),
                'owner_nickname' => $guild->owner?->nickname,
            ],
            'month' => $monthKey,
            'is_current_month' => $isCurrentMonth,
            'initialized' => $monthRecord !== null,
            'available_months' => $this->availableMonths($guild),
            'permissions' => [
                ...$this->guildService->permissions($viewerRole),
                'can_edit_others' => $isCurrentMonth && $canEditOthers,
                'can_edit_self' => $isCurrentMonth,
                'can_clear_schedule' => $isCurrentMonth
                    && $viewerRole === GuildMember::ROLE_OWNER,
            ],
            'members' => $rows->map(function (GuildMonthMember $row) use (
                $snapshots,
                $stages,
                $isCurrentMonth,
                $uid,
                $canEditOthers,
                $currentNicknames
            ): array {
                $canEditRow = $isCurrentMonth
                    && ((int) $row->uid === $uid || $canEditOthers);
                $nickname = $isCurrentMonth
                    ? $currentNicknames->get($row->uid, $row->nickname)
                    : $row->nickname;

                return $this->memberPayload(
                    $row,
                    $snapshots,
                    $stages,
                    $canEditRow,
                    $isCurrentMonth,
                    $nickname
                );
            })->values()->all(),
        ];
    }

    public function save(
        int $guildId,
        int $actorUid,
        int $targetUid,
        array $data
    ): array {
        DB::transaction(function () use (
            $guildId,
            $actorUid,
            $targetUid,
            $data
        ): void {
            $guild = $this->guildService->lockActiveGuild($guildId);
            $actor = $this->guildService->requireMembership($guild, $actorUid);
            $this->guildService->requireMembership($guild, $targetUid);

            if (
                isset($data['month'])
                && $data['month'] !== now()->format('Y-m')
            ) {
                throw GuildException::conflict(
                    '当前月份已切换，请刷新公会排刀后重试'
                );
            }

            $actorRole = (int) $actor->role;
            if (
                $actorUid !== $targetUid
                && ! in_array($actorRole, [
                    GuildMember::ROLE_OWNER,
                    GuildMember::ROLE_VICE,
                ], true)
            ) {
                throw GuildException::forbidden('普通成员只能编辑自己的排刀');
            }

            $month = $this->ensureCurrentMonth($guild);
            $this->syncCurrentMonthRows($guild, $month);
            $this->cleanupDStageReturns($month);

            $row = GuildMonthMember::query()
                ->where('month_id', $month->id)
                ->where('uid', $targetUid)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw GuildException::conflict('该成员当前月排刀记录不存在，请刷新后重试');
            }

            $requestedVersion = (int) $data['version'];
            if ((int) $row->version !== $requestedVersion) {
                throw GuildException::conflict(
                    '排刀已被其他成员修改，请刷新后重试',
                    ['current_version' => (int) $row->version]
                );
            }

            $proposed = [];
            foreach ([...self::MAIN_FIELDS, ...self::RETURN_FIELDS] as $field) {
                $proposed[$field] = array_key_exists($field, $data)
                    ? ($data[$field] === null ? null : (int) $data[$field])
                    : ($row->{$field} !== null ? (int) $row->{$field} : null);
            }

            $allTeamIds = collect($proposed)->filter()->values()->all();
            $metas = $this->teamMetas($allTeamIds);

            foreach (self::MAIN_FIELDS as $index => $field) {
                $mainId = $proposed[$field];
                $existingId = $row->{$field} !== null ? (int) $row->{$field} : null;
                $returnField = self::RETURN_FIELDS[$index];
                $returnId = $proposed[$returnField];
                $existingReturnId = $row->{$returnField} !== null
                    ? (int) $row->{$returnField}
                    : null;

                if ($mainId !== null && $mainId !== $existingId) {
                    $this->assertSelectableTeam($mainId);
                }

                $mainMeta = $mainId !== null ? ($metas[$mainId] ?? null) : null;
                $mainDeleted = $mainId !== null
                    && (! $mainMeta || (int) $mainMeta->status !== 1);
                $mainStage = ! $mainDeleted && $mainMeta
                    ? (int) $mainMeta->stage
                    : null;

                if ($mainId === null) {
                    $proposed[$returnField] = null;
                    continue;
                }

                if ($mainDeleted) {
                    if ($returnId !== $existingReturnId) {
                        throw GuildException::invalid('主刀作业已删除，无法编辑对应返还刀');
                    }

                    continue;
                }

                if ($mainStage === 4) {
                    $proposed[$returnField] = null;
                    continue;
                }

                if ($returnId !== null && $returnId !== $existingReturnId) {
                    $this->assertSelectableTeam($returnId);
                }
            }

            $row->fill($proposed);
            $row->version = (int) $row->version + 1;
            $row->save();
        });

        return $this->show($guildId, $actorUid, null);
    }

    public function copy(
        int $guildId,
        int $actorUid,
        int $sourceUid,
        int $targetUid
    ): array {
        DB::transaction(function () use (
            $guildId,
            $actorUid,
            $sourceUid,
            $targetUid
        ): void {
            $guild = $this->guildService->lockActiveGuild($guildId);
            $this->guildService->requireManager($guild, $actorUid);
            $this->guildService->requireMembership($guild, $sourceUid);
            $this->guildService->requireMembership($guild, $targetUid);

            if ($sourceUid === $targetUid) {
                throw GuildException::invalid('来源成员与目标成员不能相同');
            }

            $month = $this->ensureCurrentMonth($guild);
            $this->syncCurrentMonthRows($guild, $month);
            $this->cleanupDStageReturns($month);

            $rows = GuildMonthMember::query()
                ->where('month_id', $month->id)
                ->whereIn('uid', [$sourceUid, $targetUid])
                ->orderBy('uid')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (GuildMonthMember $row) => (int) $row->uid);

            $source = $rows->get($sourceUid);
            $target = $rows->get($targetUid);

            if (! $source || ! $target) {
                throw GuildException::conflict(
                    '排刀记录不存在，请刷新后重试'
                );
            }

            $fields = [...self::MAIN_FIELDS, ...self::RETURN_FIELDS];
            $hasAnyTeam = collect($fields)
                ->contains(
                    fn (string $field): bool => $source->{$field} !== null
                );

            if (! $hasAnyTeam) {
                throw GuildException::invalid('来源成员暂无排刀数据，无法复制');
            }

            $proposed = [];
            foreach ($fields as $field) {
                $proposed[$field] = $source->{$field} !== null
                    ? (int) $source->{$field}
                    : null;
            }

            $target->fill($proposed);
            $target->version = (int) $target->version + 1;
            $target->save();

            $this->cleanupDStageReturns($month);

            $this->guildLogService->write(
                $guildId,
                $actorUid,
                GuildLog::ACTION_SCHEDULE_COPIED,
                $targetUid,
                [
                    'source_uid' => $sourceUid,
                    'year_month' => $month->year_month,
                ]
            );
        });

        return $this->show($guildId, $actorUid, null);
    }

    public function clear(
        int $guildId,
        int $uid,
        ?string $requestedMonth = null
    ): array
    {
        DB::transaction(function () use (
            $guildId,
            $uid,
            $requestedMonth
        ): void {
            $guild = $this->guildService->lockActiveGuild($guildId);
            $this->guildService->requireOwner($guild, $uid);

            if (
                $requestedMonth !== null
                && $requestedMonth !== now()->format('Y-m')
            ) {
                throw GuildException::conflict(
                    '当前月份已切换，请刷新公会排刀后重试'
                );
            }

            $monthRecord = $this->ensureCurrentMonth($guild);
            $this->syncCurrentMonthRows($guild, $monthRecord);

            $rows = GuildMonthMember::query()
                ->where('month_id', $monthRecord->id)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->fill([
                    'team_1' => null,
                    'team_1_return' => null,
                    'team_2' => null,
                    'team_2_return' => null,
                    'team_3' => null,
                    'team_3_return' => null,
                ]);
                $row->version = (int) $row->version + 1;
                $row->save();
            }

            $this->guildLogService->write(
                $guildId,
                $uid,
                GuildLog::ACTION_SCHEDULE_CLEARED,
                null,
                [
                    'year_month' => $monthRecord->year_month,
                    'member_count' => $rows->count(),
                ]
            );
        });

        return $this->show($guildId, $uid, null);
    }

    public function teamOptions(int $guildId, int $uid): array
    {
        $guild = Guild::query()
            ->whereKey($guildId)
            ->where('status', Guild::STATUS_ACTIVE)
            ->first();

        if (! $guild) {
            throw GuildException::notFound();
        }

        $this->guildService->requireMembership($guild, $uid);

        return [
            'stages' => [
                ['stage' => 2, 'name' => 'B阶段'],
                ['stage' => 3, 'name' => 'C阶段'],
                ['stage' => 4, 'name' => 'D阶段'],
            ],
            'teams' => $this->teamQueryService->getGuildTeamOptions(),
        ];
    }

    private function ensureCurrentMonth(Guild $guild): GuildMonth
    {
        $monthKey = now()->format('Y-m');

        $month = GuildMonth::query()
            ->where('guild_id', $guild->id)
            ->where('year_month', $monthKey)
            ->first();

        if ($month) {
            return $month;
        }

        try {
            return GuildMonth::query()->create([
                'guild_id' => $guild->id,
                'year_month' => $monthKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            return GuildMonth::query()
                ->where('guild_id', $guild->id)
                ->where('year_month', $monthKey)
                ->firstOrFail();
        }
    }

    private function syncCurrentMonthRows(Guild $guild, GuildMonth $month): void
    {
        $members = GuildMember::query()
            ->with('user:id,nickname')
            ->where('guild_id', $guild->id)
            ->where('status', GuildMember::STATUS_ACTIVE)
            ->orderBy('member_slot')
            ->get();

        $rows = GuildMonthMember::query()
            ->where('month_id', $month->id)
            ->get()
            ->keyBy(fn (GuildMonthMember $row) => (int) $row->uid);

        $activeUids = [];

        foreach ($members as $member) {
            $uid = (int) $member->uid;
            $activeUids[] = $uid;
            $row = $rows->get($uid);

            if (! $row) {
                $row = new GuildMonthMember([
                    'month_id' => $month->id,
                    'guild_id' => $guild->id,
                    'uid' => $uid,
                    'version' => 0,
                ]);
            }

            $row->nickname = $member->user?->nickname;
            $row->role = (int) $member->role;
            $row->member_slot = (int) $member->member_slot;
            $row->save();
        }

        $staleIds = $rows
            ->keys()
            ->diff($activeUids)
            ->values()
            ->all();

        if ($staleIds !== []) {
            GuildMonthMember::query()
                ->where('month_id', $month->id)
                ->whereIn('uid', $staleIds)
                ->delete();
        }
    }

    private function cleanupDStageReturns(GuildMonth $month): void
    {
        $rows = GuildMonthMember::query()
            ->where('month_id', $month->id)
            ->get();

        $mainIds = $rows
            ->flatMap(fn (GuildMonthMember $row) => [
                $row->team_1,
                $row->team_2,
                $row->team_3,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $metas = $this->teamMetas($mainIds);

        foreach ($rows as $row) {
            $changed = false;

            foreach (self::MAIN_FIELDS as $index => $field) {
                $mainId = $row->{$field} !== null ? (int) $row->{$field} : null;
                $returnField = self::RETURN_FIELDS[$index];
                $returnId = $row->{$returnField};

                if ($mainId === null) {
                    if ($returnId !== null) {
                        $row->{$returnField} = null;
                        $changed = true;
                    }
                    continue;
                }

                $meta = $metas[$mainId] ?? null;

                // 主刀已删除时无法判断阶段，保留返还刀并禁止编辑。
                if (
                    $meta
                    && (int) $meta->status === 1
                    && (int) $meta->stage === 4
                    && $returnId !== null
                ) {
                    $row->{$returnField} = null;
                    $changed = true;
                }
            }

            if ($changed) {
                $row->version = (int) $row->version + 1;
                $row->save();
            }
        }
    }

    private function memberPayload(
        GuildMonthMember $row,
        array $snapshots,
        array $stages,
        bool $canEditRow,
        bool $isCurrentMonth,
        ?string $nickname
    ): array {
        $slots = [];

        foreach (self::MAIN_FIELDS as $index => $field) {
            $position = $index + 1;
            $returnField = self::RETURN_FIELDS[$index];
            $mainId = $row->{$field} !== null ? (int) $row->{$field} : null;
            $returnId = $row->{$returnField} !== null
                ? (int) $row->{$returnField}
                : null;
            $mainMeta = $mainId !== null ? ($stages[$mainId] ?? null) : null;
            $mainDeleted = $mainId !== null
                && (! $mainMeta || (int) $mainMeta['status'] !== 1);
            $mainStage = ! $mainDeleted && $mainMeta
                ? (int) $mainMeta['stage']
                : null;
            $returnEditable = $isCurrentMonth
                && $canEditRow
                && $mainId !== null
                && ! $mainDeleted
                && in_array($mainStage, [2, 3], true);

            $slots[] = [
                'key' => $field,
                'kind' => 'main',
                'position' => $position,
                'team_id' => $mainId,
                'team' => $mainId !== null ? ($snapshots[$mainId] ?? null) : null,
                'deleted' => $mainDeleted,
                'editable' => $isCurrentMonth && $canEditRow,
                'return_editable' => $returnEditable,
            ];
            $slots[] = [
                'key' => $returnField,
                'kind' => 'return',
                'position' => $position,
                'team_id' => $returnId,
                'team' => $returnId !== null ? ($snapshots[$returnId] ?? null) : null,
                'deleted' => $returnId !== null && (
                    ! isset($snapshots[$returnId])
                    || (bool) ($snapshots[$returnId]['deleted'] ?? false)
                ),
                'editable' => $returnEditable,
                'return_editable' => $returnEditable,
            ];
        }

        return [
            'uid' => (int) $row->uid,
            'nickname' => $nickname,
            'role' => (int) $row->role,
            'role_name' => GuildMember::roleName((int) $row->role),
            'member_slot' => (int) $row->member_slot,
            'version' => (int) $row->version,
            'can_edit' => $isCurrentMonth && $canEditRow,
            'team_1' => $row->team_1,
            'team_1_return' => $row->team_1_return,
            'team_2' => $row->team_2,
            'team_2_return' => $row->team_2_return,
            'team_3' => $row->team_3,
            'team_3_return' => $row->team_3_return,
            'slots' => $slots,
        ];
    }

    private function assertSelectableTeam(int $teamId): void
    {
        $startOfMonth = now()->startOfMonth();
        $startOfNextMonth = $startOfMonth
            ->copy()
            ->addMonthNoOverflow()
            ->startOfMonth();

        $exists = Team::query()
            ->whereKey($teamId)
            ->where('uid', 0)
            ->where('status', 1)
            ->whereIn('stage', [2, 3, 4])
            ->where('created_at', '>=', $startOfMonth)
            ->where('created_at', '<', $startOfNextMonth)
            ->exists();

        if (! $exists) {
            throw GuildException::invalid('所选作业不存在、已删除或不属于当前月份');
        }
    }

    private function teamMetas(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        return Team::query()
            ->whereIn('id', $teamIds)
            ->get(['id', 'stage', 'status'])
            ->keyBy(fn (Team $team) => (int) $team->id)
            ->all();
    }

    private function teamStages(array $teamIds): array
    {
        $metas = $this->teamMetas($teamIds);

        return collect($metas)
            ->mapWithKeys(fn (Team $team, int $id) => [
                $id => [
                    'stage' => (int) $team->stage,
                    'status' => (int) $team->status,
                ],
            ])
            ->all();
    }

    private function availableMonths(Guild $guild): array
    {
        $start = $guild->created_at
            ? Carbon::parse($guild->created_at)->startOfMonth()
            : now()->startOfMonth();
        $end = now()->startOfMonth();
        $months = [];

        while ($start->lessThanOrEqualTo($end)) {
            $months[] = $start->format('Y-m');
            $start->addMonthNoOverflow();
        }

        return array_reverse($months);
    }

    private function normalizeMonth(?string $month): string
    {
        $value = $month !== null && trim($month) !== ''
            ? trim($month)
            : now()->format('Y-m');

        try {
            $date = Carbon::createFromFormat('!Y-m', $value);
        } catch (Throwable) {
            throw GuildException::invalid('月份格式必须为 YYYY-MM');
        }

        if (! $date || $date->format('Y-m') !== $value) {
            throw GuildException::invalid('月份格式必须为 YYYY-MM');
        }

        if ($value > now()->format('Y-m')) {
            throw GuildException::invalid('不能查看未来月份');
        }

        return $value;
    }
}
