<?php

namespace App\Services;

use App\Models\GameRole;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UserProfileService
{
    public function __construct(
        private readonly GuildNicknameSyncService $guildNicknameSyncService
    ) {}

    public function userData(User $user): array
    {
        $avatar = $user->avatar !== null ? (int) $user->avatar : null;

        return [
            'id' => (int) $user->id,
            'nickname' => $user->nickname,
            'status' => (int) $user->status,
            'is_subscribe' => (bool) $user->is_subscribe,
            'email' => $user->email,
            'public_uid' => $user->public_uid !== null
                ? (int) $user->public_uid
                : null,
            'country' => $user->country,
            'province' => $user->province,
            'city' => $user->city,
            'avatar' => $avatar,
            'avatar_url' => (int) $user->status === 1
                ? $this->avatarUrl($avatar)
                : null,
            'nickname_updated_at' => $user->nickname_updated_at?->toJSON(),
        ];
    }

    public function avatarOptions(): array
    {
        return $this->normalRoleQuery()
            ->orderBy('element')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'nickname',
                'element',
                'role_id_1',
                'role_id_3',
                'role_id_6',
            ])
            ->map(function (GameRole $role): array {
                return [
                    'id' => (int) $role->id,
                    'name' => (string) $role->name,
                    'nickname' => (string) ($role->nickname ?? ''),
                    'element' => (int) $role->element,
                    'avatar_1' => $this->avatarVariant($role->role_id_1),
                    'avatar_3' => $this->avatarVariant($role->role_id_3),
                    'avatar_6' => $this->avatarVariant($role->role_id_6),
                ];
            })
            ->values()
            ->all();
    }

    public function updateProfile(User $user, array $data): array
    {
        $nicknameProvided = array_key_exists('nickname', $data);
        $avatarProvided = array_key_exists('avatar', $data);
        $avatarId = $avatarProvided ? (int) $data['avatar'] : null;

        if ($avatarProvided) {
            $this->assertAvatarAllowed($avatarId);
        }

        if (! $nicknameProvided) {
            if ($avatarProvided) {
                $this->updateAvatar($user, $avatarId);
            }

            return $this->userData($user->fresh());
        }

        $nickname = (string) $data['nickname'];
        $currentNickname = Str::trim((string) $user->nickname);

        if ($nickname === $currentNickname) {
            if ($avatarProvided) {
                $this->updateAvatar($user, $avatarId);
            }

            return $this->userData($user->fresh());
        }

        $now = now();
        $cooldownDays = (int) config('profile.nickname_cooldown_days', 30);
        $cutoff = $now->copy()->subDays($cooldownDays);

        if (
            $user->nickname_updated_at !== null
            && $user->nickname_updated_at->gt($cutoff)
        ) {
            $this->throwNicknameCooldown(
                $user->nickname_updated_at,
                $cooldownDays
            );
        }

        $updates = [
            'nickname' => $nickname,
            'nickname_updated_at' => $now,
            'updated_at' => $now,
        ];

        if ($avatarProvided) {
            $updates['avatar'] = $avatarId;
        }

        $affected = User::query()
            ->whereKey($user->getKey())
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->whereNull('nickname_updated_at')
                    ->orWhere('nickname_updated_at', '<=', $cutoff);
            })
            ->update($updates);

        if ($affected === 0) {
            $user->refresh();
            $this->throwNicknameCooldown(
                $user->nickname_updated_at,
                $cooldownDays
            );
        }

        $user->refresh();
        $this->guildNicknameSyncService->syncCurrentMonth(
            (int) $user->id,
            $user->nickname
        );

        return $this->userData($user);
    }

    public function updatePassword(User $user, array $data): void
    {
        if (
            ! is_string($user->password)
            || ! Hash::check((string) $data['current_password'], $user->password)
        ) {
            throw ValidationException::withMessages([
                'current_password' => '原密码不正确',
            ]);
        }

        if (Hash::check((string) $data['new_password'], $user->password)) {
            throw ValidationException::withMessages([
                'new_password' => '新密码不能与原密码相同',
            ]);
        }

        $user->password = $data['new_password'];
        $user->save();
    }

    public function defaultAvatarId(): int
    {
        $avatarId = (int) config('profile.default_avatar_id', 105811);

        if (! is_file($this->avatarPath($avatarId))) {
            throw new RuntimeException(
                "默认头像资源不存在：images/roles/{$avatarId}.webp"
            );
        }

        return $avatarId;
    }

    private function normalRoleQuery(): Builder
    {
        return GameRole::query()
            ->where('status', 1)
            ->where('id', '<>', 9999)
            ->whereNotNull('name')
            ->where('name', '<>', '');
    }

    private function assertAvatarAllowed(int $avatarId): void
    {
        $exists = $this->normalRoleQuery()
            ->where(function (Builder $query) use ($avatarId): void {
                $query
                    ->where('role_id_1', $avatarId)
                    ->orWhere('role_id_3', $avatarId)
                    ->orWhere('role_id_6', $avatarId);
            })
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'avatar' => '请选择合法的游戏角色头像',
            ]);
        }
    }

    private function updateAvatar(User $user, int $avatarId): void
    {
        if ($user->avatar !== null && (int) $user->avatar === $avatarId) {
            return;
        }

        $now = now();

        User::query()
            ->whereKey($user->getKey())
            ->update([
                'avatar' => $avatarId,
                'updated_at' => $now,
            ]);

        $user->refresh();
    }

    public function avatarUrl(?int $avatarId): ?string
    {
        if (
            $avatarId === null
            || ! is_file($this->avatarPath($avatarId))
        ) {
            $avatarId = $this->defaultAvatarId();
        }

        return url("images/roles/{$avatarId}.webp");
    }

    private function avatarVariant(?int $avatarId): ?array
    {
        if ($avatarId === null) {
            return null;
        }

        return [
            'id' => (int) $avatarId,
            'url' => url("images/roles/{$avatarId}.webp"),
        ];
    }

    private function avatarPath(int $avatarId): string
    {
        return public_path("images/roles/{$avatarId}.webp");
    }

    private function throwNicknameCooldown(
        ?CarbonInterface $lastUpdatedAt,
        int $cooldownDays
    ): never {
        $nextChangeAt = $lastUpdatedAt !== null
            ? $lastUpdatedAt->copy()->addDays($cooldownDays)
            : now();

        throw ValidationException::withMessages([
            'nickname' => "昵称每{$cooldownDays}天只能修改一次，"
                .'下次可修改时间：'
                .$nextChangeAt->format('Y-m-d H:i:s'),
        ]);
    }
}
