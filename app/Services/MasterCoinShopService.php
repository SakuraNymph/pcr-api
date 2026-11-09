<?php

namespace App\Services;

use App\Exceptions\MasterCoinShopException;
use App\Models\GameRole;
use App\Models\MasterCoinShopRole;
use App\Models\UserRolePool;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MasterCoinShopService
{
    public const TYPE_FES = 1;

    public const TYPE_NON_FES = 2;

    private const MAX_SORT_ATTEMPTS = 3;

    /**
     * 获取两个商店分组，排序值大的角色排在前面。
     */
    public function overview(): array
    {
        $shopRoles = MasterCoinShopRole::query()
            ->with('gameRole')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MasterCoinShopRole $shopRole): ?array => $this->presentShopRole($shopRole))
            ->filter()
            ->values();

        return [
            'fes' => $shopRoles
                ->where('type', self::TYPE_FES)
                ->values()
                ->all(),
            'non_fes' => $shopRoles
                ->where('type', self::TYPE_NON_FES)
                ->values()
                ->all(),
        ];
    }

    /**
     * 搜索全部游戏角色，并返回其商店所属分组。
     */
    public function searchGameRoles(string $keyword): Collection
    {
        return GameRole::query()
            ->with('masterCoinShopRole')
            ->where(function ($query) use ($keyword): void {
                $query->where('name', 'like', "%{$keyword}%")
                    ->orWhere('nickname', 'like', "%{$keyword}%");
            })
            ->orderByDesc('id')
            ->get()
            ->map(function (GameRole $gameRole): array {
                return [
                    ...$this->presentGameRole($gameRole),
                    'shop_type' => $gameRole->masterCoinShopRole?->type,
                ];
            });
    }

    /**
     * 将角色加入目标分组首位。
     */
    public function add(int $gameRoleId, int $type): array
    {
        $this->assertType($type);

        $gameRole = GameRole::query()->find($gameRoleId);

        if (! $gameRole) {
            throw new MasterCoinShopException('所选游戏角色不存在', 404);
        }

        return $this->retryOnSortCollision(function () use ($gameRole, $type): array {
            return DB::transaction(function () use ($gameRole, $type): array {
                $existing = MasterCoinShopRole::query()
                    ->where('game_role_id', $gameRole->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new MasterCoinShopException('该角色已加入大师币商店', 409);
                }

                $shopRole = MasterCoinShopRole::create([
                    'game_role_id' => $gameRole->id,
                    'type' => $type,
                    'sort_order' => $this->nextSortOrder($type),
                ]);

                return $this->presentShopRole($shopRole->load('gameRole'));
            });
        }, $gameRole->id, true);
    }

    /**
     * 将已加入商店的角色切换到另一个分组首位。
     */
    public function move(int $gameRoleId, int $type): array
    {
        $this->assertType($type);

        $this->assertGameRoleExists($gameRoleId);

        return $this->retryOnSortCollision(function () use ($gameRoleId, $type): array {
            return DB::transaction(function () use ($gameRoleId, $type): array {
                $shopRole = MasterCoinShopRole::query()
                    ->where('game_role_id', $gameRoleId)
                    ->lockForUpdate()
                    ->first();

                if (! $shopRole) {
                    throw new MasterCoinShopException('该角色尚未加入大师币商店', 404);
                }

                if ($shopRole->type === $type) {
                    throw new MasterCoinShopException('该角色已在该商店分组中', 409);
                }

                $shopRole->update([
                    'type' => $type,
                    'sort_order' => $this->nextSortOrder($type),
                ]);

                return $this->presentShopRole($shopRole->fresh('gameRole'));
            });
        }, $gameRoleId);
    }

    /**
     * 按前端提交的完整角色顺序重排指定分组。
     *
     * 先写入一组高于当前最大值的临时排序值，避免交换顺序时触发
     * unique(type, sort_order) 的中间态冲突。
     */
    public function sortGroup(int $type, array $gameRoleIds): array
    {
        $this->assertType($type);

        $normalizedRoleIds = array_values(array_map(
            static fn (int|string $roleId): int => (int) $roleId,
            $gameRoleIds
        ));

        if (
            count($normalizedRoleIds)
            !== count(array_unique($normalizedRoleIds))
        ) {
            throw new MasterCoinShopException('排序角色不能重复', 422);
        }

        DB::transaction(function () use ($type, $normalizedRoleIds): void {
            $shopRoles = MasterCoinShopRole::query()
                ->where('type', $type)
                ->orderByDesc('sort_order')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $currentRoleIds = $shopRoles
                ->pluck('game_role_id')
                ->map(static fn (int|string $roleId): int => (int) $roleId)
                ->sort()
                ->values()
                ->all();
            $requestedRoleIds = $normalizedRoleIds;
            sort($requestedRoleIds);

            if ($currentRoleIds !== $requestedRoleIds) {
                throw new MasterCoinShopException(
                    '排序角色列表与当前分组不一致，请刷新后重试',
                    409
                );
            }

            if (count($normalizedRoleIds) < 2) {
                return;
            }

            $shopRolesByGameRoleId = $shopRoles->keyBy('game_role_id');
            $temporaryStart = (int) $shopRoles
                ->max('sort_order') + count($normalizedRoleIds) + 1;

            foreach ($normalizedRoleIds as $index => $gameRoleId) {
                $shopRolesByGameRoleId
                    ->get($gameRoleId)
                    ?->update([
                        'sort_order' => $temporaryStart + $index,
                    ]);
            }

            $finalSortOrder = count($normalizedRoleIds);

            foreach ($normalizedRoleIds as $gameRoleId) {
                $shopRolesByGameRoleId
                    ->get($gameRoleId)
                    ?->update([
                        'sort_order' => $finalSortOrder--,
                    ]);
            }
        });

        return $this->overview();
    }

    /**
     * 从商店中移除角色，不调整剩余记录的排序值。
     */
    public function remove(int $gameRoleId): void
    {
        $this->assertGameRoleExists($gameRoleId);

        DB::transaction(function () use ($gameRoleId): void {
            $shopRole = MasterCoinShopRole::query()
                ->where('game_role_id', $gameRoleId)
                ->lockForUpdate()
                ->first();

            if (! $shopRole) {
                throw new MasterCoinShopException('该角色尚未加入大师币商店', 404);
            }

            $shopRole->delete();
        });
    }

    /**
     * 获取用户端可选的全部系统大师币角色。
     *
     * FES 始终排在非 FES 前面；组内使用商店排序值和记录 ID 保证顺序稳定。
     */
    public function userRoles(): Collection
    {
        return MasterCoinShopRole::query()
            ->with('gameRole')
            ->whereHas('gameRole')
            ->orderBy('type')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(function (MasterCoinShopRole $shopRole): array {
                $gameRole = $shopRole->gameRole;

                return [
                    'id' => (int) $gameRole->id,
                    'type' => (int) $shopRole->type,
                    'name' => $gameRole->name,
                    'image_url' => $this->imageUrl($gameRole),
                ];
            })
            ->values();
    }

    /**
     * 获取指定登录用户名下游戏账号的大师币配置。
     */
    public function userRolePoolConfig(int $poolId, int $userId): array
    {
        $pool = UserRolePool::query()
            ->where('id', $poolId)
            ->where('uid', $userId)
            ->where('status', 1)
            ->first();

        if (! $pool) {
            throw new MasterCoinShopException('游戏账号不存在', 404);
        }

        return $this->presentUserRolePoolConfig($pool);
    }

    /**
     * 保存指定登录用户名下游戏账号的大师币配置。
     */
    public function updateUserRolePoolConfig(
        int $poolId,
        int $userId,
        array $roleIds
    ): array {
        $normalizedRoleIds = array_values(array_map(
            static fn (int|string $roleId): int => (int) $roleId,
            $roleIds
        ));

        if (
            count($normalizedRoleIds)
            !== count(array_unique($normalizedRoleIds))
        ) {
            throw new MasterCoinShopException('大师币角色不能重复选择', 422);
        }

        return DB::transaction(function () use (
            $poolId,
            $userId,
            $normalizedRoleIds
        ): array {
            $pool = UserRolePool::query()
                ->where('id', $poolId)
                ->where('uid', $userId)
                ->where('status', 1)
                ->lockForUpdate()
                ->first();

            if (! $pool) {
                throw new MasterCoinShopException('游戏账号不存在', 404);
            }

            $this->assertRolesConfigured($normalizedRoleIds);

            $pool->coin = $normalizedRoleIds;
            $pool->save();

            return $this->presentUserRolePoolConfig($pool->fresh());
        });
    }

    private function nextSortOrder(int $type): int
    {
        $lastShopRole = MasterCoinShopRole::query()
            ->where('type', $type)
            ->orderByDesc('sort_order')
            ->lockForUpdate()
            ->first(['id', 'sort_order']);

        return $lastShopRole
            ? (int) $lastShopRole->sort_order + 1
            : 1;
    }

    /**
     * 唯一排序索引发生并发冲突时重新计算目标分组的排序值。
     */
    private function retryOnSortCollision(
        callable $operation,
        int $gameRoleId,
        bool $detectDuplicate = false
    ): array {
        for ($attempt = 1; $attempt <= self::MAX_SORT_ATTEMPTS; $attempt++) {
            try {
                return $operation();
            } catch (UniqueConstraintViolationException $exception) {
                if ($detectDuplicate) {
                    $existing = MasterCoinShopRole::query()
                        ->where('game_role_id', $gameRoleId)
                        ->first();

                    if ($existing) {
                        throw new MasterCoinShopException('该角色已加入大师币商店', 409);
                    }
                }

                if ($attempt === self::MAX_SORT_ATTEMPTS) {
                    throw new MasterCoinShopException('商店排序更新冲突，请稍后重试', 409);
                }
            } catch (QueryException $exception) {
                if (! $this->isRetryableLockException($exception)) {
                    throw $exception;
                }

                if ($attempt === self::MAX_SORT_ATTEMPTS) {
                    throw new MasterCoinShopException('商店配置并发冲突，请稍后重试', 409);
                }
            }
        }

        throw new MasterCoinShopException('商店排序更新冲突，请稍后重试', 409);
    }

    private function isRetryableLockException(QueryException $exception): bool
    {
        $message = $exception->getMessage();
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return $sqlState === '40001'
            || str_contains($message, 'Deadlock found')
            || str_contains($message, 'Lock wait timeout exceeded')
            || str_contains($message, 'try restarting transaction');
    }

    private function presentShopRole(MasterCoinShopRole $shopRole): ?array
    {
        $gameRole = $shopRole->gameRole;

        if (! $gameRole) {
            return null;
        }

        return [
            'id' => (int) $shopRole->id,
            'game_role_id' => (int) $shopRole->game_role_id,
            'type' => (int) $shopRole->type,
            'sort_order' => (int) $shopRole->sort_order,
            ...$this->presentGameRole($gameRole),
        ];
    }

    private function presentGameRole(GameRole $gameRole): array
    {
        return [
            'game_role_id' => (int) $gameRole->id,
            'role_id' => (int) $gameRole->role_id,
            'name' => $gameRole->name,
            'nickname' => $gameRole->nickname,
            'element' => (int) $gameRole->element,
            'atk_type' => (int) $gameRole->atk_type,
            'position' => (int) $gameRole->position,
            'search_area_width' => (int) $gameRole->search_area_width,
            'status' => (bool) $gameRole->status,
            'image_url' => $this->imageUrl($gameRole),
        ];
    }

    private function imageUrl(GameRole $gameRole): ?string
    {
        $imageId = $gameRole->role_id_6 ?? $gameRole->role_id_3;

        return $imageId
            ? url("images/roles/{$imageId}.webp")
            : null;
    }

    private function presentUserRolePoolConfig(UserRolePool $pool): array
    {
        $coin = is_array($pool->coin)
            ? array_values(array_map(
                static fn (int|string $roleId): int => (int) $roleId,
                $pool->coin
            ))
            : [];

        return [
            'id' => (int) $pool->id,
            'nickname' => $pool->nickname,
            'coin' => $coin,
        ];
    }

    /**
     * 校验角色 ID 仍存在于系统大师币商店配置中。
     */
    private function assertRolesConfigured(array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }

        $configuredRoleIds = MasterCoinShopRole::query()
            ->whereIn('game_role_id', $roleIds)
            ->whereHas('gameRole')
            ->pluck('game_role_id')
            ->map(static fn (int|string $roleId): int => (int) $roleId)
            ->all();

        $invalidRoleIds = array_values(array_diff(
            $roleIds,
            $configuredRoleIds
        ));

        if ($invalidRoleIds !== []) {
            throw new MasterCoinShopException(
                '包含未配置到大师币商店的角色',
                422
            );
        }
    }

    private function assertType(int $type): void
    {
        if (! in_array($type, [self::TYPE_FES, self::TYPE_NON_FES], true)) {
            throw new MasterCoinShopException('商店分组无效', 422);
        }
    }

    private function assertGameRoleExists(int $gameRoleId): void
    {
        if (! GameRole::query()->whereKey($gameRoleId)->exists()) {
            throw new MasterCoinShopException('所选游戏角色不存在', 404);
        }
    }
}
