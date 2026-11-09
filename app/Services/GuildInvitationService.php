<?php

namespace App\Services;

use App\Exceptions\GuildException;
use App\Models\Guild;
use App\Models\GuildInvitation;
use App\Models\GuildLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class GuildInvitationService
{
    public function __construct(
        private readonly GuildService $guildService,
        private readonly GuildLogService $guildLogService,
        private readonly UserProfileService $userProfileService
    ) {}

    public function candidate(int $guildId, int $actorUid, int $uid): array
    {
        $guild = Guild::query()
            ->whereKey($guildId)
            ->where('status', Guild::STATUS_ACTIVE)
            ->first();

        if (! $guild) {
            throw GuildException::notFound();
        }

        $this->guildService->requireManager($guild, $actorUid);

        $user = User::query()
            ->whereKey($uid)
            ->where('status', 1)
            ->first();

        if (! $user) {
            return [
                'uid' => $uid,
                'nickname' => null,
                'avatar_url' => null,
                'eligible' => false,
                'reason' => '未找到该注册用户',
            ];
        }

        $reason = null;
        if ($this->guildService->activeMembership($guildId, $uid)) {
            $reason = '该用户已是公会成员';
        } elseif ($this->guildService->userHasActiveGuild($uid)) {
            $reason = '该用户已有公会，无法邀请';
        } elseif ($this->guildService->hasPendingInvitation($guildId, $uid)) {
            $reason = '已向该用户发送有效邀请';
        } elseif ($this->guildService->hasPendingApplication($guildId, $uid)) {
            $reason = '该用户已提交加入申请，请直接在加入申请中审核';
        } elseif (! $this->guildService->hasAvailableSlot($guildId)) {
            $reason = '公会暂无可用邀请名额';
        }

        return [
            'uid' => (int) $user->id,
            'nickname' => $user->nickname,
            'avatar_url' => $this->userProfileService->avatarUrl(
                $user->avatar !== null ? (int) $user->avatar : null
            ),
            'eligible' => $reason === null,
            'reason' => $reason,
        ];
    }

    public function send(int $guildId, int $actorUid, int $inviteeUid): array
    {
        try {
            return DB::transaction(function () use (
                $guildId,
                $actorUid,
                $inviteeUid
            ): array {
                $guild = $this->guildService->lockActiveGuild($guildId);
                $this->guildService->requireManager($guild, $actorUid);
                $this->guildService->expirePendingInvitations($guildId);
                $this->guildService->expirePendingApplications(
                    $guildId,
                    $inviteeUid
                );

                $invitee = User::query()
                    ->whereKey($inviteeUid)
                    ->where('status', 1)
                    ->first();

                if (! $invitee) {
                    throw GuildException::invalid('未找到该注册用户');
                }

                if ($this->guildService->activeMembership($guildId, $inviteeUid)) {
                    throw GuildException::conflict('该用户已是公会成员');
                }

                if ($this->guildService->userHasActiveGuild($inviteeUid)) {
                    throw GuildException::conflict('该用户已有公会，无法邀请');
                }

                if ($this->guildService->hasPendingInvitation($guildId, $inviteeUid)) {
                    throw GuildException::conflict('已向该用户发送有效邀请');
                }

                if ($this->guildService->hasPendingApplication($guildId, $inviteeUid)) {
                    throw GuildException::conflict(
                        '该用户已提交加入申请，请直接在加入申请中审核'
                    );
                }

                if (! $this->guildService->hasAvailableSlot($guildId)) {
                    throw GuildException::conflict('公会暂无可用邀请名额');
                }

                $invitation = GuildInvitation::query()->create([
                    'guild_id' => $guildId,
                    'inviter_uid' => $actorUid,
                    'invitee_uid' => $inviteeUid,
                    'status' => GuildInvitation::STATUS_PENDING,
                    'expires_at' => now()->addDays(GuildInvitation::VALID_DAYS),
                    'handled_at' => null,
                ]);

                $this->guildLogService->write(
                    $guildId,
                    $actorUid,
                    GuildLog::ACTION_INVITATION_SENT,
                    $inviteeUid,
                    ['invitation_id' => (int) $invitation->id]
                );

                return $this->invitationData(
                    $invitation->load(['guild.owner', 'inviter', 'invitee'])
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw GuildException::conflict('已向该用户发送有效邀请');
        }
    }

    public function list(int $uid, string $type): array
    {
        $this->expireForUser($uid, $type);

        $query = GuildInvitation::query()
            ->with(['guild.owner', 'inviter', 'invitee'])
            ->where('status', GuildInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->whereHas('guild', function (Builder $query): void {
                $query->where('status', Guild::STATUS_ACTIVE);
            });

        if ($type === 'received') {
            $query->where('invitee_uid', $uid);
        } else {
            $query->where('inviter_uid', $uid);
        }

        $items = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (GuildInvitation $invitation) => $this->invitationData($invitation))
            ->values()
            ->all();

        return [
            'items' => $items,
            'count' => count($items),
        ];
    }

    public function revoke(int $invitationId, int $uid): void
    {
        $snapshot = GuildInvitation::query()->find($invitationId);

        if (! $snapshot) {
            throw GuildException::notFound('邀请不存在');
        }

        $expired = false;

        DB::transaction(function () use (
            $invitationId,
            $uid,
            $snapshot,
            &$expired
        ): void {
            $this->guildService->lockActiveGuild((int) $snapshot->guild_id);

            $invitation = GuildInvitation::query()
                ->whereKey($invitationId)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                throw GuildException::notFound('邀请不存在');
            }

            if ((int) $invitation->inviter_uid !== $uid) {
                throw GuildException::forbidden('只能撤销自己发出的邀请');
            }

            if ($this->markExpiredIfNeeded($invitation)) {
                $expired = true;

                return;
            }

            $this->assertPending($invitation);

            $invitation->status = GuildInvitation::STATUS_REVOKED;
            $invitation->handled_at = now();
            $invitation->save();

            $this->guildLogService->write(
                (int) $invitation->guild_id,
                $uid,
                GuildLog::ACTION_INVITATION_REVOKED,
                (int) $invitation->invitee_uid,
                ['invitation_id' => $invitationId]
            );
        });

        if ($expired) {
            throw GuildException::conflict('该邀请已过期');
        }
    }

    public function reject(int $invitationId, int $uid): void
    {
        $snapshot = GuildInvitation::query()->find($invitationId);

        if (! $snapshot) {
            throw GuildException::notFound('邀请不存在');
        }

        $expired = false;

        DB::transaction(function () use (
            $invitationId,
            $uid,
            $snapshot,
            &$expired
        ): void {
            $this->guildService->lockActiveGuild((int) $snapshot->guild_id);

            $invitation = GuildInvitation::query()
                ->whereKey($invitationId)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                throw GuildException::notFound('邀请不存在');
            }

            if ((int) $invitation->invitee_uid !== $uid) {
                throw GuildException::forbidden('只能处理发给自己的邀请');
            }

            if ($this->markExpiredIfNeeded($invitation)) {
                $expired = true;

                return;
            }

            $this->assertPending($invitation);

            $invitation->status = GuildInvitation::STATUS_REJECTED;
            $invitation->handled_at = now();
            $invitation->save();

            $this->guildLogService->write(
                (int) $invitation->guild_id,
                $uid,
                GuildLog::ACTION_INVITATION_REJECTED,
                $uid,
                ['invitation_id' => $invitationId]
            );
        });

        if ($expired) {
            throw GuildException::conflict('该邀请已过期');
        }
    }

    public function accept(int $invitationId, int $uid): array
    {
        $snapshot = GuildInvitation::query()->find($invitationId);

        if (! $snapshot) {
            throw GuildException::notFound('邀请不存在');
        }

        $expired = false;

        $result = DB::transaction(function () use (
            $invitationId,
            $uid,
            $snapshot,
            &$expired
        ): ?array {
            $guild = $this->guildService->lockActiveGuild((int) $snapshot->guild_id);

            // 先锁用户行，再锁邀请行，避免与其他公会的加入事务互相等待。
            User::query()
                ->whereKey($uid)
                ->lockForUpdate()
                ->first();

            $invitation = GuildInvitation::query()
                ->whereKey($invitationId)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                throw GuildException::notFound('邀请不存在');
            }

            if ((int) $invitation->invitee_uid !== $uid) {
                throw GuildException::forbidden('只能处理发给自己的邀请');
            }

            if ($this->markExpiredIfNeeded($invitation)) {
                $expired = true;

                return null;
            }

            $this->assertPending($invitation);

            if ($this->guildService->userHasActiveGuild($uid)) {
                throw GuildException::conflict('你已经加入了其他公会');
            }

            if (! $this->guildService->activeMembership(
                (int) $guild->id,
                (int) $invitation->inviter_uid
            )) {
                throw GuildException::conflict('邀请人已不在该公会，邀请已失效');
            }

            $latestLeftAt = $this->guildService->latestLeftAt(
                (int) $guild->id,
                $uid
            );

            if (
                $latestLeftAt !== null
                && $latestLeftAt->greaterThanOrEqualTo($invitation->created_at)
            ) {
                throw GuildException::conflict('请使用离开公会之后重新发送的邀请');
            }

            if (
                $this->guildService->memberCount((int) $guild->id)
                    >= Guild::CAPACITY
                || $this->guildService->reservedSlotCount((int) $guild->id)
                    > Guild::CAPACITY
            ) {
                throw GuildException::conflict('公会成员已满');
            }

            $this->guildService->addMember($guild, $uid, [
                'source' => 'invitation',
                'invitation_id' => $invitationId,
            ]);

            $invitation->status = GuildInvitation::STATUS_ACCEPTED;
            $invitation->handled_at = now();
            $invitation->save();

            // 用户已加入一个公会，其他待处理邀请和申请自动失效并释放预留名额。
            $this->guildService->invalidateOtherJoinRequests(
                $uid,
                $invitationId,
                null
            );

            $this->guildLogService->write(
                (int) $guild->id,
                $uid,
                GuildLog::ACTION_INVITATION_ACCEPTED,
                $uid,
                ['invitation_id' => $invitationId]
            );
            return $this->guildService->show((int) $guild->id, $uid);
        });

        if ($expired) {
            throw GuildException::conflict('该邀请已过期');
        }

        return $result;
    }

    private function markExpiredIfNeeded(GuildInvitation $invitation): bool
    {
        if (
            (int) $invitation->status === GuildInvitation::STATUS_PENDING
            && $invitation->expires_at->lessThanOrEqualTo(now())
        ) {
            $invitation->status = GuildInvitation::STATUS_EXPIRED;
            $invitation->handled_at = now();
            $invitation->save();

            return true;
        }

        return false;
    }

    private function assertPending(GuildInvitation $invitation): void
    {
        if ((int) $invitation->status !== GuildInvitation::STATUS_PENDING) {
            throw GuildException::conflict(
                '该邀请已处理或已失效',
                ['status' => (int) $invitation->status]
            );
        }

        if ($invitation->expires_at->lessThanOrEqualTo(now())) {
            throw GuildException::conflict('该邀请已过期');
        }
    }

    private function expireForUser(int $uid, string $type): void
    {
        $column = $type === 'received' ? 'invitee_uid' : 'inviter_uid';

        GuildInvitation::query()
            ->where($column, $uid)
            ->where('status', GuildInvitation::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => GuildInvitation::STATUS_EXPIRED,
                'handled_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function invitationData(GuildInvitation $invitation): array
    {
        $guild = $invitation->guild;
        $owner = $guild?->owner;

        return [
            'id' => (int) $invitation->id,
            'guild_id' => (int) $invitation->guild_id,
            'guild_name' => $guild?->name,
            'guild_avatar_url' => $this->userProfileService->avatarUrl(
                $owner?->avatar !== null ? (int) $owner->avatar : null
            ),
            'inviter_uid' => (int) $invitation->inviter_uid,
            'inviter_nickname' => $invitation->inviter?->nickname,
            'invitee_uid' => (int) $invitation->invitee_uid,
            'invitee_nickname' => $invitation->invitee?->nickname,
            'status' => (int) $invitation->status,
            'status_name' => GuildInvitation::statusName((int) $invitation->status),
            'created_at' => $invitation->created_at?->toJSON(),
            'expires_at' => $invitation->expires_at?->toJSON(),
        ];
    }
}
