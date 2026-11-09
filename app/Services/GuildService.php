<?php

namespace App\Services;

use App\Exceptions\GuildException;
use App\Models\Guild;
use App\Models\GuildApplication;
use App\Models\GuildInvitation;
use App\Models\GuildLog;
use App\Models\GuildMember;
use App\Models\GuildMonth;
use App\Models\GuildMonthMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class GuildService
{
    public function __construct(
        private readonly GuildLogService $guildLogService,
        private readonly UserProfileService $userProfileService
    ) {}

    public function search(string $keyword, int $page): array
    {
        $keyword = trim($keyword);

        $paginator = Guild::query()
            ->with('owner:id,nickname,avatar,status')
            ->withCount([
                'activeMembers as member_count',
                'invitations as pending_invitation_count' => function (Builder $query): void {
                    $query
                        ->where('status', GuildInvitation::STATUS_PENDING)
                        ->where('expires_at', '>', now());
                },
                'applications as pending_application_count' => function (Builder $query): void {
                    $query
                        ->where('status', GuildApplication::STATUS_PENDING)
                        ->where('expires_at', '>', now());
                },
            ])
            ->where('status', Guild::STATUS_ACTIVE)
            ->when($keyword !== '', function (Builder $query) use ($keyword): void {
                $query->where('name', 'like', '%'.$keyword.'%');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', max(1, $page));

        return [
            'items' => collect($paginator->items())
                ->map(fn (Guild $guild) => $this->summary($guild))
                ->values()
                ->all(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function create(int $uid, array $data): array
    {
        try {
            return DB::transaction(function () use ($uid, $data): array {
                $user = User::query()
                    ->whereKey($uid)
                    ->lockForUpdate()
                    ->first();

                if (! $user || (int) $user->status !== 1) {
                    throw GuildException::forbidden('只有正式注册用户可以创建公会');
                }

                if ($this->userHasActiveGuild($uid)) {
                    throw GuildException::conflict('你已经加入了一个公会，无法创建新公会');
                }

                if (
                    Guild::query()
                        ->where('owner_uid', $uid)
                        ->where('status', Guild::STATUS_ACTIVE)
                        ->exists()
                ) {
                    throw GuildException::conflict('你已经拥有一个正常运营的公会');
                }

                $guild = Guild::query()->create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'owner_uid' => $uid,
                    'status' => Guild::STATUS_ACTIVE,
                ]);

                GuildMember::query()->create([
                    'guild_id' => $guild->id,
                    'uid' => $uid,
                    'role' => GuildMember::ROLE_OWNER,
                    'member_slot' => 1,
                    'vice_slot' => null,
                    'status' => GuildMember::STATUS_ACTIVE,
                    'joined_at' => now(),
                    'left_at' => null,
                ]);

                $this->guildLogService->write(
                    (int) $guild->id,
                    $uid,
                    GuildLog::ACTION_CREATED,
                    $uid,
                    ['name' => $guild->name]
                );

                return $this->show((int) $guild->id, $uid);
            });
        } catch (UniqueConstraintViolationException) {
            throw GuildException::conflict('你已加入或拥有其他正常运营的公会');
        }
    }

    public function overview(int $uid): array
    {
        $membership = GuildMember::query()
            ->with('guild.owner:id,nickname,avatar,status')
            ->where('uid', $uid)
            ->where('status', GuildMember::STATUS_ACTIVE)
            ->first();

        if (
            ! $membership
            || ! $membership->guild
            || (int) $membership->guild->status !== Guild::STATUS_ACTIVE
        ) {
            return [
                'guild' => null,
                'membership' => null,
                'permissions' => $this->permissions(null),
                'received_invitation_count' => $this->receivedInvitationCount($uid),
                'sent_invitation_count' => $this->sentInvitationCount($uid),
            ];
        }

        $guild = $membership->guild;
        $memberCount = $this->memberCount((int) $guild->id);
        $pendingCount = $this->pendingInvitationCount((int) $guild->id);
        $pendingApplicationCount = $this->pendingApplicationCount((int) $guild->id);

        return [
            'guild' => $this->summary(
                $guild,
                $memberCount,
                $pendingCount,
                $pendingApplicationCount
            ),
            'membership' => [
                'role' => (int) $membership->role,
                'role_name' => GuildMember::roleName((int) $membership->role),
                'member_slot' => (int) $membership->member_slot,
                'joined_at' => $membership->joined_at?->toJSON(),
            ],
            'permissions' => $this->permissions((int) $membership->role),
            'received_invitation_count' => $this->receivedInvitationCount($uid),
            'sent_invitation_count' => $this->sentInvitationCount($uid),
        ];
    }

    public function show(int $guildId, int $uid): array
    {
        $guild = Guild::query()
            ->with('owner:id,nickname,avatar,status')
            ->whereKey($guildId)
            ->where('status', Guild::STATUS_ACTIVE)
            ->first();

        if (! $guild) {
            throw GuildException::notFound();
        }

        $membership = $this->activeMembership($guildId, $uid);
        $memberCount = $this->memberCount($guildId);
        $pendingCount = $this->pendingInvitationCount($guildId);
        $pendingApplicationCount = $this->pendingApplicationCount($guildId);
        $role = $membership ? (int) $membership->role : null;

        $data = $this->summary(
            $guild,
            $memberCount,
            $pendingCount,
            $pendingApplicationCount
        );
        $data['viewer_membership'] = $membership
            ? [
                'role' => $role,
                'role_name' => GuildMember::roleName((int) $role),
                'member_slot' => (int) $membership->member_slot,
                'joined_at' => $membership->joined_at?->toJSON(),
            ]
            : null;
        $data['permissions'] = $this->permissions($role);

        $viewerApplication = null;
        $viewerHasGuild = $membership !== null || $this->userHasActiveGuild($uid);

        if (! $membership) {
            $viewerApplication = GuildApplication::query()
                ->where('guild_id', $guildId)
                ->where('applicant_uid', $uid)
                ->where('status', GuildApplication::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->first();
        }

        $hasPendingInvitation = ! $membership
            && $this->hasPendingInvitation($guildId, $uid);

        $applyBlockedReason = null;
        if ($membership) {
            $applyBlockedReason = 'member';
        } elseif ($viewerApplication) {
            $applyBlockedReason = 'pending_application';
        } elseif ($viewerHasGuild) {
            $applyBlockedReason = 'has_guild';
        } elseif ($hasPendingInvitation) {
            $applyBlockedReason = 'pending_invitation';
        } elseif ((int) $data['remaining_slots'] <= 0) {
            $applyBlockedReason = 'no_slots';
        }

        $data['viewer_has_guild'] = $viewerHasGuild;
        $data['viewer_application'] = $viewerApplication
            ? $this->viewerApplicationData($viewerApplication)
            : null;
        $data['can_apply'] = $applyBlockedReason === null;
        $data['apply_blocked_reason'] = $applyBlockedReason;

        if ($membership) {
            $data['members'] = GuildMember::query()
                ->with('user:id,nickname,avatar,status')
                ->where('guild_id', $guildId)
                ->where('status', GuildMember::STATUS_ACTIVE)
                ->orderBy('role')
                ->orderBy('member_slot')
                ->get()
                ->map(fn (GuildMember $member) => $this->memberData($member))
                ->values()
                ->all();
        }

        return $data;
    }

    public function update(int $guildId, int $uid, array $data): array
    {
        DB::transaction(function () use ($guildId, $uid, $data): void {
            $guild = $this->lockActiveGuild($guildId);
            $this->requireOwner($guild, $uid);

            $changes = [];
            if (array_key_exists('name', $data) && $guild->name !== $data['name']) {
                $changes['name'] = [$guild->name, $data['name']];
            }
            if (
                array_key_exists('description', $data)
                && $guild->description !== ($data['description'] ?? null)
            ) {
                $changes['description'] = [
                    $guild->description,
                    $data['description'] ?? null,
                ];
            }

            $guild->fill([
                'name' => $data['name'] ?? $guild->name,
                'description' => array_key_exists('description', $data)
                    ? $data['description']
                    : $guild->description,
            ])->save();

            $this->guildLogService->write(
                $guildId,
                $uid,
                GuildLog::ACTION_UPDATED,
                null,
                ['changes' => $changes]
            );
        });

        return $this->show($guildId, $uid);
    }

    public function dissolve(int $guildId, int $uid): void
    {
        DB::transaction(function () use ($guildId, $uid): void {
            $guild = $this->lockActiveGuild($guildId);
            $this->requireOwner($guild, $uid);
            $now = now();

            GuildMember::query()
                ->where('guild_id', $guildId)
                ->where('status', GuildMember::STATUS_ACTIVE)
                ->update([
                    'status' => GuildMember::STATUS_LEFT,
                    'left_at' => $now,
                    'vice_slot' => null,
                    'updated_at' => $now,
                ]);

            GuildInvitation::query()
                ->where('guild_id', $guildId)
                ->where('status', GuildInvitation::STATUS_PENDING)
                ->update([
                    'status' => GuildInvitation::STATUS_INVALID,
                    'handled_at' => $now,
                    'updated_at' => $now,
                ]);

            GuildApplication::query()
                ->where('guild_id', $guildId)
                ->where('status', GuildApplication::STATUS_PENDING)
                ->update([
                    'status' => GuildApplication::STATUS_INVALID,
                    'handled_at' => $now,
                    'updated_at' => $now,
                ]);

            $guild->status = Guild::STATUS_DISSOLVED;
            $guild->save();

            $this->guildLogService->write(
                $guildId,
                $uid,
                GuildLog::ACTION_DISSOLVED,
                $uid,
                ['name' => $guild->name]
            );
        });
    }

    public function leave(int $guildId, int $uid): void
    {
        DB::transaction(function () use ($guildId, $uid): void {
            $guild = $this->lockActiveGuild($guildId);
            $member = $this->requireMembership($guild, $uid, true);

            if ((int) $member->role === GuildMember::ROLE_OWNER) {
                throw GuildException::conflict('会长不能直接退出，请先转让会长或解散公会');
            }

            $this->removeMember(
                $guild,
                $member,
                $uid,
                GuildLog::ACTION_MEMBER_LEFT
            );
        });
    }

    public function kick(int $guildId, int $actorUid, int $targetUid): void
    {
        DB::transaction(function () use ($guildId, $actorUid, $targetUid): void {
            $guild = $this->lockActiveGuild($guildId);
            $this->requireOwner($guild, $actorUid);

            if ($actorUid === $targetUid) {
                throw GuildException::conflict('会长不能踢出自己');
            }

            $target = $this->requireMembership($guild, $targetUid, true);

            if ((int) $target->role === GuildMember::ROLE_OWNER) {
                throw GuildException::conflict('不能踢出会长');
            }

            $this->removeMember(
                $guild,
                $target,
                $actorUid,
                GuildLog::ACTION_MEMBER_KICKED
            );
        });
    }

    public function appointVice(int $guildId, int $actorUid, int $targetUid): array
    {
        try {
            DB::transaction(function () use (
                $guildId,
                $actorUid,
                $targetUid
            ): void {
                $guild = $this->lockActiveGuild($guildId);
                $this->requireOwner($guild, $actorUid);
                $target = $this->requireMembership($guild, $targetUid, true);

                if ((int) $target->role !== GuildMember::ROLE_MEMBER) {
                    throw GuildException::conflict('只能从普通成员中任命副会长');
                }

                $usedSlots = GuildMember::query()
                    ->where('guild_id', $guildId)
                    ->where('status', GuildMember::STATUS_ACTIVE)
                    ->where('role', GuildMember::ROLE_VICE)
                    ->pluck('vice_slot')
                    ->map(fn ($slot) => (int) $slot)
                    ->all();

                if (count($usedSlots) >= 2) {
                    throw GuildException::conflict('该公会已有2名副会长');
                }

                $viceSlot = collect([1, 2])
                    ->first(fn (int $slot) => ! in_array($slot, $usedSlots, true));

                $target->role = GuildMember::ROLE_VICE;
                $target->vice_slot = $viceSlot;
                $target->save();

                $this->syncCurrentMonthMembership($guild, $target);
                $this->guildLogService->write(
                    $guildId,
                    $actorUid,
                    GuildLog::ACTION_VICE_APPOINTED,
                    $targetUid,
                    ['vice_slot' => $viceSlot]
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw GuildException::conflict('副会长名额已被占用，请刷新后重试');
        }

        return $this->show($guildId, $actorUid);
    }

    public function revokeVice(int $guildId, int $actorUid, int $targetUid): array
    {
        DB::transaction(function () use ($guildId, $actorUid, $targetUid): void {
            $guild = $this->lockActiveGuild($guildId);
            $this->requireOwner($guild, $actorUid);
            $target = $this->requireMembership($guild, $targetUid, true);

            if ((int) $target->role !== GuildMember::ROLE_VICE) {
                throw GuildException::conflict('该成员不是副会长');
            }

            $target->role = GuildMember::ROLE_MEMBER;
            $target->vice_slot = null;
            $target->save();

            $this->syncCurrentMonthMembership($guild, $target);
            $this->guildLogService->write(
                $guildId,
                $actorUid,
                GuildLog::ACTION_VICE_REVOKED,
                $targetUid
            );
        });

        return $this->show($guildId, $actorUid);
    }

    public function transferOwner(int $guildId, int $actorUid, int $targetUid): array
    {
        try {
            DB::transaction(function () use (
                $guildId,
                $actorUid,
                $targetUid
            ): void {
                $guild = $this->lockActiveGuild($guildId);
                $actor = $this->requireOwner($guild, $actorUid);

                if ($actorUid === $targetUid) {
                    throw GuildException::conflict('不能把会长转让给自己');
                }

                $target = $this->requireMembership($guild, $targetUid, true);

                // 先释放原会长身份，避免唯一约束冲突。
                $actor->role = GuildMember::ROLE_MEMBER;
                $actor->vice_slot = null;
                $actor->save();

                $target->role = GuildMember::ROLE_OWNER;
                $target->vice_slot = null;
                $target->save();

                $guild->owner_uid = $targetUid;
                $guild->save();

                $this->syncCurrentMonthMembership($guild, $actor);
                $this->syncCurrentMonthMembership($guild, $target);

                $this->guildLogService->write(
                    $guildId,
                    $actorUid,
                    GuildLog::ACTION_OWNER_TRANSFERRED,
                    $targetUid,
                    ['previous_owner_uid' => $actorUid]
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw GuildException::conflict('会长身份变更冲突，请刷新后重试');
        }

        return $this->show($guildId, $actorUid);
    }

    public function lockActiveGuild(int $guildId): Guild
    {
        $guild = $this->lockGuild($guildId);

        if ((int) $guild->status !== Guild::STATUS_ACTIVE) {
            throw GuildException::notFound();
        }

        return $guild;
    }

    public function lockGuild(int $guildId): Guild
    {
        $guild = Guild::query()
            ->whereKey($guildId)
            ->lockForUpdate()
            ->first();

        if (! $guild) {
            throw GuildException::notFound();
        }

        return $guild;
    }

    public function activeMembership(
        int $guildId,
        int $uid,
        bool $lock = false
    ): ?GuildMember {
        $query = GuildMember::query()
            ->where('guild_id', $guildId)
            ->where('uid', $uid)
            ->where('status', GuildMember::STATUS_ACTIVE);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function requireMembership(
        Guild $guild,
        int $uid,
        bool $lock = false
    ): GuildMember {
        $member = $this->activeMembership((int) $guild->id, $uid, $lock);

        if (! $member) {
            throw GuildException::forbidden('你不是该公会的当前成员');
        }

        return $member;
    }

    public function requireManager(Guild $guild, int $uid): GuildMember
    {
        $member = $this->requireMembership($guild, $uid);

        if (! in_array((int) $member->role, [
            GuildMember::ROLE_OWNER,
            GuildMember::ROLE_VICE,
        ], true)) {
            throw GuildException::forbidden('只有会长或副会长可以执行该操作');
        }

        return $member;
    }

    public function requireOwner(Guild $guild, int $uid): GuildMember
    {
        $member = $this->requireMembership($guild, $uid);

        if ((int) $member->role !== GuildMember::ROLE_OWNER) {
            throw GuildException::forbidden('只有会长可以执行该操作');
        }

        return $member;
    }

    public function nextMemberSlot(int $guildId): int
    {
        $usedSlots = GuildMember::query()
            ->where('guild_id', $guildId)
            ->where('status', GuildMember::STATUS_ACTIVE)
            ->pluck('member_slot')
            ->map(fn ($slot) => (int) $slot)
            ->all();

        if (count($usedSlots) >= Guild::CAPACITY) {
            throw GuildException::conflict('公会成员已满');
        }

        for ($slot = 1; $slot <= Guild::CAPACITY; $slot++) {
            if (! in_array($slot, $usedSlots, true)) {
                return $slot;
            }
        }

        throw GuildException::conflict('公会成员已满');
    }

    public function addMember(
        Guild $guild,
        int $uid,
        array $context = []
    ): GuildMember {
        // 锁定用户行，串行化同一用户在不同公会的入会检查。
        $user = User::query()
            ->whereKey($uid)
            ->lockForUpdate()
            ->first();

        if (! $user || (int) $user->status !== 1) {
            throw GuildException::conflict('该用户不是正式注册用户，无法加入');
        }

        if ($this->userHasActiveGuild($uid)) {
            throw GuildException::conflict('该用户已有公会，无法加入');
        }

        $slot = $this->nextMemberSlot((int) $guild->id);

        try {
            $member = GuildMember::query()->create([
                'guild_id' => $guild->id,
                'uid' => $uid,
                'role' => GuildMember::ROLE_MEMBER,
                'member_slot' => $slot,
                'vice_slot' => null,
                'status' => GuildMember::STATUS_ACTIVE,
                'joined_at' => now(),
                'left_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw GuildException::conflict('该用户已经加入其他公会，或公会成员已满');
        }

        $this->syncCurrentMonthMembership($guild, $member);

        $this->guildLogService->write(
            (int) $guild->id,
            $uid,
            GuildLog::ACTION_MEMBER_JOINED,
            $uid,
            array_merge(
                ['member_slot' => (int) $member->member_slot],
                $context
            )
        );

        return $member;
    }

    public function syncCurrentMonthMembership(
        Guild $guild,
        GuildMember $member
    ): void {
        $month = GuildMonth::query()
            ->where('guild_id', $guild->id)
            ->where('year_month', now()->format('Y-m'))
            ->first();

        if (! $month) {
            return;
        }

        $user = User::query()->find($member->uid);
        $now = now();

        GuildMonthMember::query()->updateOrCreate(
            [
                'month_id' => $month->id,
                'uid' => $member->uid,
            ],
            [
                'guild_id' => $guild->id,
                'nickname' => $user?->nickname,
                'role' => $member->role,
                'member_slot' => $member->member_slot,
                'updated_at' => $now,
            ]
        );
    }

    public function removeCurrentAndFutureScheduleRows(
        int $guildId,
        int $uid
    ): void {
        $currentMonth = now()->format('Y-m');

        GuildMonthMember::query()
            ->where('guild_id', $guildId)
            ->where('uid', $uid)
            ->whereHas('month', function (Builder $query) use ($currentMonth): void {
                $query->where('year_month', '>=', $currentMonth);
            })
            ->delete();
    }

    public function invalidatePendingInvitations(
        int $guildId,
        ?int $inviterUid = null
    ): void {
        $query = GuildInvitation::query()
            ->where('guild_id', $guildId)
            ->where('status', GuildInvitation::STATUS_PENDING);

        if ($inviterUid !== null) {
            $query->where('inviter_uid', $inviterUid);
        }

        $query->update([
            'status' => GuildInvitation::STATUS_INVALID,
            'handled_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function expirePendingInvitations(int $guildId): void
    {
        GuildInvitation::query()
            ->where('guild_id', $guildId)
            ->where('status', GuildInvitation::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => GuildInvitation::STATUS_EXPIRED,
                'handled_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function pendingInvitationCount(int $guildId): int
    {
        return GuildInvitation::query()
            ->where('guild_id', $guildId)
            ->where('status', GuildInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->count();
    }

    public function hasPendingInvitation(int $guildId, int $uid): bool
    {
        return GuildInvitation::query()
            ->where('guild_id', $guildId)
            ->where('invitee_uid', $uid)
            ->where('status', GuildInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function expirePendingApplications(
        int $guildId,
        ?int $applicantUid = null
    ): void {
        $query = GuildApplication::query()
            ->where('guild_id', $guildId)
            ->where('status', GuildApplication::STATUS_PENDING)
            ->where('expires_at', '<=', now());

        if ($applicantUid !== null) {
            $query->where('applicant_uid', $applicantUid);
        }

        $query->update([
            'status' => GuildApplication::STATUS_EXPIRED,
            'handled_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function pendingApplicationCount(int $guildId): int
    {
        return GuildApplication::query()
            ->where('guild_id', $guildId)
            ->where('status', GuildApplication::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->count();
    }

    public function hasPendingApplication(int $guildId, int $uid): bool
    {
        return GuildApplication::query()
            ->where('guild_id', $guildId)
            ->where('applicant_uid', $uid)
            ->where('status', GuildApplication::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function reservedSlotCount(int $guildId): int
    {
        return $this->memberCount($guildId)
            + $this->pendingInvitationCount($guildId)
            + $this->pendingApplicationCount($guildId);
    }

    public function hasAvailableSlot(int $guildId): bool
    {
        return $this->reservedSlotCount($guildId) < Guild::CAPACITY;
    }

    public function latestLeftAt(int $guildId, int $uid): ?Carbon
    {
        $value = GuildMember::query()
            ->where('guild_id', $guildId)
            ->where('uid', $uid)
            ->whereNotNull('left_at')
            ->max('left_at');

        return $value !== null ? Carbon::parse($value) : null;
    }

    public function invalidateOtherJoinRequests(
        int $uid,
        ?int $exceptInvitationId = null,
        ?int $exceptApplicationId = null
    ): void {
        $now = now();

        $invitations = GuildInvitation::query()
            ->where('invitee_uid', $uid)
            ->where('status', GuildInvitation::STATUS_PENDING);

        if ($exceptInvitationId !== null) {
            $invitations->whereKeyNot($exceptInvitationId);
        }

        $invitations->update([
            'status' => GuildInvitation::STATUS_INVALID,
            'handled_at' => $now,
            'updated_at' => $now,
        ]);

        $applications = GuildApplication::query()
            ->where('applicant_uid', $uid)
            ->where('status', GuildApplication::STATUS_PENDING);

        if ($exceptApplicationId !== null) {
            $applications->whereKeyNot($exceptApplicationId);
        }

        $applications->update([
            'status' => GuildApplication::STATUS_INVALID,
            'handled_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function memberCount(int $guildId): int
    {
        return GuildMember::query()
            ->where('guild_id', $guildId)
            ->where('status', GuildMember::STATUS_ACTIVE)
            ->count();
    }

    public function userHasActiveGuild(int $uid): bool
    {
        return GuildMember::query()
            ->where('uid', $uid)
            ->where('status', GuildMember::STATUS_ACTIVE)
            ->exists();
    }

    public function permissions(?int $role): array
    {
        $owner = $role === GuildMember::ROLE_OWNER;
        $manager = in_array($role, [
            GuildMember::ROLE_OWNER,
            GuildMember::ROLE_VICE,
        ], true);

        return [
            'is_member' => $role !== null,
            'can_view_members' => $role !== null,
            'can_edit_self' => $role !== null,
            'can_edit_others' => $manager,
            'can_invite' => $manager,
            'can_appoint_vice' => $owner,
            'can_revoke_vice' => $owner,
            'can_kick' => $owner,
            'can_clear_schedule' => $owner,
            'can_transfer_owner' => $owner,
            'can_dissolve' => $owner,
            'can_update_profile' => $owner,
            'can_review_applications' => $manager,
        ];
    }

    private function removeMember(
        Guild $guild,
        GuildMember $member,
        int $actorUid,
        string $action
    ): void {
        $targetUid = (int) $member->uid;
        $role = (int) $member->role;
        $now = now();

        $member->status = GuildMember::STATUS_LEFT;
        $member->left_at = $now;
        $member->vice_slot = null;
        $member->save();

        // 邀请人失去成员身份后，其发出的待处理邀请自动失效。
        $this->invalidatePendingInvitations((int) $guild->id, $targetUid);
        $this->removeCurrentAndFutureScheduleRows((int) $guild->id, $targetUid);

        $this->guildLogService->write(
            (int) $guild->id,
            $actorUid,
            $action,
            $targetUid,
            ['role' => $role]
        );
    }

    private function summary(
        Guild $guild,
        ?int $memberCount = null,
        ?int $pendingCount = null,
        ?int $pendingApplicationCount = null
    ): array {
        $memberCount ??= isset($guild->member_count)
            ? (int) $guild->member_count
            : $this->memberCount((int) $guild->id);
        $pendingCount ??= isset($guild->pending_invitation_count)
            ? (int) $guild->pending_invitation_count
            : $this->pendingInvitationCount((int) $guild->id);
        $pendingApplicationCount ??= isset($guild->pending_application_count)
            ? (int) $guild->pending_application_count
            : $this->pendingApplicationCount((int) $guild->id);

        $remainingSlots = max(
            0,
            Guild::CAPACITY
                - $memberCount
                - $pendingCount
                - $pendingApplicationCount
        );

        if ($memberCount >= Guild::CAPACITY) {
            $capacityStatus = 'full';
        } elseif ($remainingSlots === 0) {
            $capacityStatus = 'reserved';
        } else {
            $capacityStatus = 'available';
        }

        $owner = $guild->owner;

        return [
            'id' => (int) $guild->id,
            'name' => $guild->name,
            'description' => $guild->description ?? '',
            'avatar_url' => $this->userProfileService->avatarUrl(
                $owner?->avatar !== null ? (int) $owner->avatar : null
            ),
            'owner_uid' => (int) $guild->owner_uid,
            'owner_nickname' => $owner?->nickname,
            'member_count' => $memberCount,
            'pending_invitation_count' => $pendingCount,
            'pending_application_count' => $pendingApplicationCount,
            'remaining_slots' => $remainingSlots,
            'capacity_status' => $capacityStatus,
            'status' => (int) $guild->status,
            'created_at' => $guild->created_at?->toJSON(),
        ];
    }

    private function viewerApplicationData(GuildApplication $application): array
    {
        return [
            'id' => (int) $application->id,
            'status' => (int) $application->status,
            'status_name' => GuildApplication::statusName(
                (int) $application->status
            ),
            'created_at' => $application->created_at?->toJSON(),
            'expires_at' => $application->expires_at?->toJSON(),
        ];
    }

    private function memberData(GuildMember $member): array
    {
        return [
            'uid' => (int) $member->uid,
            'nickname' => $member->user?->nickname,
            'avatar_url' => $this->userProfileService->avatarUrl(
                $member->user?->avatar !== null
                    ? (int) $member->user->avatar
                    : null
            ),
            'role' => (int) $member->role,
            'role_name' => GuildMember::roleName((int) $member->role),
            'member_slot' => (int) $member->member_slot,
            'joined_at' => $member->joined_at?->toJSON(),
        ];
    }

    private function receivedInvitationCount(int $uid): int
    {
        return GuildInvitation::query()
            ->where('invitee_uid', $uid)
            ->where('status', GuildInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->whereHas('guild', function (Builder $query): void {
                $query->where('status', Guild::STATUS_ACTIVE);
            })
            ->count();
    }

    private function sentInvitationCount(int $uid): int
    {
        return GuildInvitation::query()
            ->where('inviter_uid', $uid)
            ->where('status', GuildInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->whereHas('guild', function (Builder $query): void {
                $query->where('status', Guild::STATUS_ACTIVE);
            })
            ->count();
    }
}
