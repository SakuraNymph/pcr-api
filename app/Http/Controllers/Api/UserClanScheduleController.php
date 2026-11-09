<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClanSchedule\JoinClanScheduleRequest;
use App\Http\Requests\ClanSchedule\UpdateClanScheduleRequest;
use App\Models\User;
use App\Models\UserClanSchedule;
use App\Traits\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserClanScheduleController extends Controller
{
    use ApiResponse;

    private const PHASE_ORDER = [
        'B' => 1,
        'C' => 2,
        'D' => 3,
    ];

    private const SHARE_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const SHARE_CODE_LENGTH = 8;

    /**
     * 获取当前用户可访问的排刀表列表。
     */
    public function index(): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $own = UserClanSchedule::query()
            ->with('owner:id,nickname')
            ->where('uid', $uid)
            ->first();

        $shared = UserClanSchedule::query()
            ->with('owner:id,nickname')
            ->whereHas('members', function ($query) use ($uid): void {
                $query->where('user_clan_schedule_members.uid', $uid);
            })
            ->orderBy('id')
            ->get()
            ->map(fn (UserClanSchedule $schedule) => $this->metadata(
                $schedule,
                $uid,
                'member'
            ))
            ->values()
            ->all();

        return $this->success([
            'own' => $this->metadata($own, $uid, 'owner'),
            'shared' => $shared,
        ]);
    }

    /**
     * 获取当前用户的自有排刀表。
     */
    public function show(): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = UserClanSchedule::query()
            ->with('owner:id,nickname')
            ->where('uid', $uid)
            ->first();

        return $this->success($this->payload($schedule, $uid, 'owner'));
    }

    /**
     * 保存当前用户的自有排刀表。
     */
    public function update(UpdateClanScheduleRequest $request): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = $this->findOrCreateOwnSchedule($uid);
        $this->ensureShareCode($schedule);
        $this->saveSnapshot($schedule, $request);

        return $this->success($this->payload($schedule, $uid, 'owner'), '排刀表已保存');
    }

    /**
     * 清空当前用户的自有排刀表，保留分享码和成员关系。
     */
    public function destroy(): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = UserClanSchedule::query()
            ->where('uid', $uid)
            ->first();

        if ($schedule) {
            $this->clearSnapshot($schedule);
        }

        return $this->success(null, '排刀表已清空');
    }

    /**
     * 为空的自有排刀表提前生成分享码。
     */
    public function generateShareCode(): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = $this->findOrCreateOwnSchedule($uid);
        $this->ensureShareCode($schedule);

        return $this->success($this->payload($schedule, $uid, 'owner'), '分享码已生成');
    }

    /**
     * 通过分享码加入他人的排刀表。
     */
    public function join(JoinClanScheduleRequest $request): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = UserClanSchedule::query()
            ->with('owner:id,nickname')
            ->where('share_code', $request->validated('share_code'))
            ->first();

        if (! $schedule) {
            return $this->error('分享码无效或排刀表不存在', 404);
        }

        if ((int) $schedule->uid === $uid) {
            return $this->success($this->payload($schedule, $uid, 'owner'), '已加入排刀表');
        }

        DB::table('user_clan_schedule_members')->insertOrIgnore([
            'schedule_id' => $schedule->id,
            'uid' => $uid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success($this->payload($schedule, $uid, 'member'), '已加入排刀表');
    }

    /**
     * 获取有权限访问的排刀表。
     */
    public function showShared(int $scheduleId): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = $this->findAccessibleSchedule($scheduleId, $uid);
        if (! $schedule) {
            return $this->error('排刀表不存在或无权访问', 404);
        }

        return $this->success($this->payload($schedule, $uid));
    }

    /**
     * 保存创建者或成员有权限访问的排刀表。
     */
    public function updateShared(
        UpdateClanScheduleRequest $request,
        int $scheduleId
    ): JsonResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = $this->findAccessibleSchedule($scheduleId, $uid);
        if (! $schedule) {
            return $this->error('排刀表不存在或无权访问', 404);
        }

        $this->saveSnapshot($schedule, $request);

        return $this->success($this->payload($schedule, $uid), '排刀表已保存');
    }

    /**
     * 清空有权限访问的排刀表，仅创建者可以操作。
     */
    public function destroyShared(int $scheduleId): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = $this->findAccessibleSchedule($scheduleId, $uid);
        if (! $schedule) {
            return $this->error('排刀表不存在或无权访问', 404);
        }

        if ((int) $schedule->uid !== $uid) {
            return $this->error('只有创建者可以清空排刀表', 403);
        }

        $this->clearSnapshot($schedule);

        return $this->success(null, '排刀表已清空');
    }

    /**
     * 退出共享排刀表，不删除排刀表本身。
     */
    public function leaveShared(int $scheduleId): JsonResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $schedule = $this->findAccessibleSchedule($scheduleId, $uid);
        if (! $schedule) {
            return $this->error('排刀表不存在或无权访问', 404);
        }

        if ((int) $schedule->uid === $uid) {
            return $this->error('创建者不能退出自己的排刀表', 403);
        }

        DB::table('user_clan_schedule_members')
            ->where('schedule_id', $schedule->id)
            ->where('uid', $uid)
            ->delete();

        return $this->success(null, '已退出共享排刀表');
    }

    private function findOrCreateOwnSchedule(int $uid): UserClanSchedule
    {
        $schedule = UserClanSchedule::query()->where('uid', $uid)->first();
        if ($schedule) {
            return $schedule;
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                return UserClanSchedule::query()->create([
                    'uid' => $uid,
                    'share_code' => $this->generateUniqueShareCode(),
                    'cells' => [],
                    'player_order' => [],
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                $schedule = UserClanSchedule::query()->where('uid', $uid)->first();
                if ($schedule) {
                    return $schedule;
                }
            }
        }

        throw new \RuntimeException('无法生成唯一的排刀表分享码');
    }

    private function ensureShareCode(UserClanSchedule $schedule): void
    {
        if ($schedule->share_code) {
            return;
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $schedule->share_code = $this->generateUniqueShareCode();
                $schedule->save();

                return;
            } catch (UniqueConstraintViolationException $exception) {
                $schedule->refresh();
                if ($schedule->share_code) {
                    return;
                }
            }
        }

        throw new \RuntimeException('无法生成唯一的排刀表分享码');
    }

    private function generateUniqueShareCode(): string
    {
        do {
            $code = '';
            $alphabetLength = strlen(self::SHARE_CODE_ALPHABET);

            for ($index = 0; $index < self::SHARE_CODE_LENGTH; $index++) {
                $code .= self::SHARE_CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
            }
        } while (
            UserClanSchedule::query()
                ->where('share_code', $code)
                ->exists()
        );

        return $code;
    }

    private function findAccessibleSchedule(int $scheduleId, int $uid): ?UserClanSchedule
    {
        return UserClanSchedule::query()
            ->with('owner:id,nickname')
            ->whereKey($scheduleId)
            ->where(function ($query) use ($uid): void {
                $query
                    ->where('user_clan_schedules.uid', $uid)
                    ->orWhereHas('members', function ($memberQuery) use ($uid): void {
                        $memberQuery->where(
                            'user_clan_schedule_members.uid',
                            $uid
                        );
                    });
            })
            ->first();
    }

    private function saveSnapshot(
        UserClanSchedule $schedule,
        UpdateClanScheduleRequest $request
    ): void {
        $cells = $this->sortCells($request->validated('cells') ?? []);
        $playerOrder = $this->normalizePlayerOrder(
            $cells,
            $request->validated('player_order') ?? []
        );

        $schedule->cells = $cells;
        $schedule->player_order = $playerOrder;
        $schedule->save();
    }

    private function clearSnapshot(UserClanSchedule $schedule): void
    {
        $schedule->cells = [];
        $schedule->player_order = [];
        $schedule->save();
    }

    private function payload(
        ?UserClanSchedule $schedule,
        int $uid,
        ?string $role = null
    ): array {
        $cells = $this->sortCells($schedule?->cells ?? []);

        return [
            ...$this->metadata($schedule, $uid, $role),
            'cells' => $cells,
            'player_order' => $this->normalizePlayerOrder(
                $cells,
                $schedule?->player_order ?? []
            ),
        ];
    }

    private function metadata(
        ?UserClanSchedule $schedule,
        int $uid,
        ?string $role = null
    ): array {
        $isOwner = $schedule
            ? (int) $schedule->uid === $uid
            : true;

        if ($schedule) {
            $schedule->loadMissing('owner:id,nickname');
        }

        return [
            'id' => $schedule?->id,
            'share_code' => $schedule?->share_code,
            'owner_nickname' => $schedule
                ? $schedule->owner?->nickname
                : $this->currentNickname(),
            'role' => $role ?? ($isOwner ? 'owner' : 'member'),
            'can_clear' => $isOwner,
            'updated_at' => $schedule?->updated_at?->toIso8601String(),
        ];
    }

    private function currentNickname(): ?string
    {
        $user = request()->user('sanctum');

        return $user instanceof User ? $user->nickname : null;
    }

    /**
     * 按阶段、周目、Boss 排序，保证保存和读取顺序稳定。
     */
    private function sortCells(array $cells): array
    {
        $normalized = [];

        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            $phase = $cell['phase'] ?? null;
            $round = $cell['round'] ?? null;
            $boss = $cell['boss'] ?? null;
            $player = $cell['player'] ?? null;
            $slot = $cell['slot'] ?? null;

            if (! is_string($phase) || ! isset(self::PHASE_ORDER[$phase])) {
                continue;
            }

            if (! is_int($round) && ! is_numeric($round)) {
                continue;
            }

            if (! is_int($boss) && ! is_numeric($boss)) {
                continue;
            }

            if (! is_string($player) || $player === '') {
                continue;
            }

            if (! is_int($slot) && ! is_numeric($slot)) {
                continue;
            }

            $normalized[] = [
                'phase' => $phase,
                'round' => (int) $round,
                'boss' => (int) $boss,
                'player' => $player,
                'slot' => (int) $slot,
            ];
        }

        usort($normalized, function (array $left, array $right): int {
            return [
                self::PHASE_ORDER[$left['phase']],
                $left['round'],
                $left['boss'],
            ] <=> [
                self::PHASE_ORDER[$right['phase']],
                $right['round'],
                $right['boss'],
            ];
        });

        return $normalized;
    }

    /**
     * 丢弃不存在的名称、去重，并把缺失玩家追加到末尾。
     */
    private function normalizePlayerOrder(array $cells, array $playerOrder): array
    {
        $names = [];
        foreach ($cells as $cell) {
            $name = $cell['player'];
            if (! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        $normalized = [];
        foreach ($playerOrder as $name) {
            if (! is_string($name) || ! in_array($name, $names, true)) {
                continue;
            }

            if (! in_array($name, $normalized, true)) {
                $normalized[] = $name;
            }
        }

        foreach ($names as $name) {
            if (! in_array($name, $normalized, true)) {
                $normalized[] = $name;
            }
        }

        return $normalized;
    }
}
