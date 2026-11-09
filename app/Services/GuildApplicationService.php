<?php

namespace App\Services;

use App\Exceptions\GuildException;
use App\Models\Guild;
use App\Models\GuildApplication;
use App\Models\GuildLog;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class GuildApplicationService
{
    public function __construct(
        private readonly GuildService $guildService,
        private readonly GuildLogService $guildLogService,
        private readonly UserProfileService $userProfileService
    ) {}

    public function list(int $guildId, int $actorUid): array
    {
        $guild = Guild::query()
            ->whereKey($guildId)
            ->where('status', Guild::STATUS_ACTIVE)
            ->first();

        if (! $guild) {
            throw GuildException::notFound();
        }

        $this->guildService->requireManager($guild, $actorUid);
        $this->guildService->expirePendingApplications($guildId);

        $items = GuildApplication::query()
            ->with('applicant:id,nickname,avatar,status')
            ->where('guild_id', $guildId)
            ->where('status', GuildApplication::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (GuildApplication $application) => $this->applicationData($application))
            ->values()
            ->all();

        return [
            'items' => $items,
            'count' => count($items),
        ];
    }

    public function create(int $guildId, int $applicantUid): array
    {
        try {
            return DB::transaction(function () use (
                $guildId,
                $applicantUid
            ): array {
                $guild = $this->guildService->lockGuild($guildId);

                if ((int) $guild->status !== Guild::STATUS_ACTIVE) {
                    throw GuildException::conflict('该公会已解散');
                }

                // 锁定用户行，串行化同一用户的申请与入会检查。
                $user = User::query()
                    ->whereKey($applicantUid)
                    ->lockForUpdate()
                    ->first();

                if (! $user || (int) $user->status !== 1) {
                    throw GuildException::forbidden(
                        '只有正式注册用户可以申请加入公会'
                    );
                }

                if ($this->guildService->activeMembership($guildId, $applicantUid)) {
                    throw GuildException::conflict('你已经是该公会成员');
                }

                if ($this->guildService->userHasActiveGuild($applicantUid)) {
                    throw GuildException::conflict(
                        '你已有公会，请先退出当前公会后再申请'
                    );
                }

                $this->guildService->expirePendingInvitations($guildId);
                $this->guildService->expirePendingApplications(
                    $guildId,
                    $applicantUid
                );

                if ($this->guildService->hasPendingInvitation($guildId, $applicantUid)) {
                    throw GuildException::conflict(
                        '该公会已向你发送邀请，请前往公会邀请页面处理'
                    );
                }

                if ($this->guildService->hasPendingApplication($guildId, $applicantUid)) {
                    throw GuildException::conflict('已提交过申请，请等待审核');
                }

                if (! $this->guildService->hasAvailableSlot($guildId)) {
                    throw GuildException::conflict('当前公会暂无可用名额');
                }

                $application = GuildApplication::query()->create([
                    'guild_id' => $guildId,
                    'applicant_uid' => $applicantUid,
                    'status' => GuildApplication::STATUS_PENDING,
                    'expires_at' => now()->addDays(
                        GuildApplication::VALID_DAYS
                    ),
                    'handled_at' => null,
                    'handler_uid' => null,
                ]);

                $this->guildLogService->write(
                    $guildId,
                    $applicantUid,
                    GuildLog::ACTION_APPLICATION_SUBMITTED,
                    $applicantUid,
                    ['application_id' => (int) $application->id]
                );

                return $this->viewerApplicationData($application);
            });
        } catch (UniqueConstraintViolationException) {
            throw GuildException::conflict('已提交过申请，请等待审核');
        }
    }

    public function approve(
        int $guildId,
        int $actorUid,
        int $applicationId
    ): array {
        $snapshot = GuildApplication::query()->find($applicationId);

        if (! $snapshot || (int) $snapshot->guild_id !== $guildId) {
            throw GuildException::notFound('申请不存在');
        }

        $expired = false;

        DB::transaction(function () use (
            $guildId,
            $actorUid,
            $applicationId,
            $snapshot,
            &$expired
        ): void {
            $guild = $this->guildService->lockGuild($guildId);

            if ((int) $guild->status !== Guild::STATUS_ACTIVE) {
                throw GuildException::conflict('该公会已解散');
            }

            $this->guildService->requireManager($guild, $actorUid);

            $applicantUid = (int) $snapshot->applicant_uid;

            // 先锁用户行，再锁申请行，避免与其他公会的加入事务互相等待。
            User::query()
                ->whereKey($applicantUid)
                ->lockForUpdate()
                ->first();

            $application = GuildApplication::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw GuildException::notFound('申请不存在');
            }

            if ($this->markExpiredIfNeeded($application)) {
                $expired = true;

                return;
            }

            $this->assertPending($application);

            if ($this->guildService->activeMembership($guildId, $applicantUid)) {
                throw GuildException::conflict('申请人已经是该公会成员');
            }

            if ($this->guildService->userHasActiveGuild($applicantUid)) {
                throw GuildException::conflict('申请人已加入其他公会');
            }

            $latestLeftAt = $this->guildService->latestLeftAt(
                $guildId,
                $applicantUid
            );

            if (
                $latestLeftAt !== null
                && $latestLeftAt->greaterThanOrEqualTo($application->created_at)
            ) {
                throw GuildException::conflict(
                    '该申请创建于成员离开之前，无法加入'
                );
            }

            if (
                $this->guildService->memberCount($guildId) >= Guild::CAPACITY
                || $this->guildService->reservedSlotCount($guildId)
                    > Guild::CAPACITY
            ) {
                throw GuildException::conflict('当前公会暂无可用名额');
            }

            $member = $this->guildService->addMember(
                $guild,
                $applicantUid,
                [
                    'source' => 'application',
                    'application_id' => $applicationId,
                ]
            );

            $application->status = GuildApplication::STATUS_APPROVED;
            $application->handled_at = now();
            $application->handler_uid = $actorUid;
            $application->save();

            $this->guildService->invalidateOtherJoinRequests(
                $applicantUid,
                null,
                $applicationId
            );

            $this->guildLogService->write(
                $guildId,
                $actorUid,
                GuildLog::ACTION_APPLICATION_APPROVED,
                $applicantUid,
                [
                    'application_id' => $applicationId,
                    'member_slot' => (int) $member->member_slot,
                ]
            );
        });

        if ($expired) {
            throw GuildException::conflict('该申请已过期');
        }

        return $this->guildService->show($guildId, $actorUid);
    }

    public function reject(
        int $guildId,
        int $actorUid,
        int $applicationId
    ): array {
        $snapshot = GuildApplication::query()->find($applicationId);

        if (! $snapshot || (int) $snapshot->guild_id !== $guildId) {
            throw GuildException::notFound('申请不存在');
        }

        $expired = false;

        DB::transaction(function () use (
            $guildId,
            $actorUid,
            $applicationId,
            &$expired
        ): void {
            $guild = $this->guildService->lockGuild($guildId);

            if ((int) $guild->status !== Guild::STATUS_ACTIVE) {
                throw GuildException::conflict('该公会已解散');
            }

            $this->guildService->requireManager($guild, $actorUid);

            $application = GuildApplication::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw GuildException::notFound('申请不存在');
            }

            if ($this->markExpiredIfNeeded($application)) {
                $expired = true;

                return;
            }

            $this->assertPending($application);

            $application->status = GuildApplication::STATUS_REJECTED;
            $application->handled_at = now();
            $application->handler_uid = $actorUid;
            $application->save();

            $this->guildLogService->write(
                $guildId,
                $actorUid,
                GuildLog::ACTION_APPLICATION_REJECTED,
                (int) $application->applicant_uid,
                ['application_id' => $applicationId]
            );
        });

        if ($expired) {
            throw GuildException::conflict('该申请已过期');
        }

        return $this->guildService->show($guildId, $actorUid);
    }

    public function withdraw(int $applicationId, int $applicantUid): void
    {
        $snapshot = GuildApplication::query()->find($applicationId);

        if (! $snapshot) {
            throw GuildException::notFound('申请不存在');
        }

        $expired = false;

        DB::transaction(function () use (
            $applicationId,
            $applicantUid,
            $snapshot,
            &$expired
        ): void {
            $this->guildService->lockGuild((int) $snapshot->guild_id);

            $application = GuildApplication::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->first();

            if (! $application) {
                throw GuildException::notFound('申请不存在');
            }

            if ((int) $application->applicant_uid !== $applicantUid) {
                throw GuildException::forbidden('只能撤销自己的加入申请');
            }

            if ($this->markExpiredIfNeeded($application)) {
                $expired = true;

                return;
            }

            $this->assertPending($application);

            $application->status = GuildApplication::STATUS_WITHDRAWN;
            $application->handled_at = now();
            $application->handler_uid = null;
            $application->save();

            $this->guildLogService->write(
                (int) $application->guild_id,
                $applicantUid,
                GuildLog::ACTION_APPLICATION_WITHDRAWN,
                $applicantUid,
                ['application_id' => $applicationId]
            );
        });

        if ($expired) {
            throw GuildException::conflict('该申请已过期');
        }
    }

    private function markExpiredIfNeeded(
        GuildApplication $application
    ): bool {
        if (
            (int) $application->status === GuildApplication::STATUS_PENDING
            && $application->expires_at->lessThanOrEqualTo(now())
        ) {
            $application->status = GuildApplication::STATUS_EXPIRED;
            $application->handled_at = now();
            $application->handler_uid = null;
            $application->save();

            return true;
        }

        return false;
    }

    private function assertPending(GuildApplication $application): void
    {
        if ((int) $application->status !== GuildApplication::STATUS_PENDING) {
            throw GuildException::conflict(
                '该申请已处理或已失效',
                ['status' => (int) $application->status]
            );
        }

        if ($application->expires_at->lessThanOrEqualTo(now())) {
            throw GuildException::conflict('该申请已过期');
        }
    }

    private function applicationData(GuildApplication $application): array
    {
        $applicant = $application->applicant;

        return [
            'id' => (int) $application->id,
            'guild_id' => (int) $application->guild_id,
            'applicant_uid' => (int) $application->applicant_uid,
            'applicant_nickname' => $applicant?->nickname,
            'applicant_avatar_url' => $this->userProfileService->avatarUrl(
                $applicant?->avatar !== null
                    ? (int) $applicant->avatar
                    : null
            ),
            'status' => (int) $application->status,
            'status_name' => GuildApplication::statusName(
                (int) $application->status
            ),
            'created_at' => $application->created_at?->toJSON(),
            'expires_at' => $application->expires_at?->toJSON(),
        ];
    }

    private function viewerApplicationData(
        GuildApplication $application
    ): array {
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
}
