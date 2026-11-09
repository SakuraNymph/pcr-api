<?php

namespace App\Services;

use App\Models\GameRole;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

class CardStatisticsRoleService
{
    public const CACHE_KEY = 'card_statistics:roles:v1';

    public const CACHE_TTL_DAYS = 30;

    /**
     * 获取全部可用于抽卡统计的角色。
     *
     * @return array<int, array{
     *     id: int,
     *     image_id: int|null,
     *     name: string,
     *     probability: int,
     *     up_time: string,
     *     year: int
     * }>
     */
    public function roles(): array
    {
        $roles = Cache::remember(
            self::CACHE_KEY,
            now()->addDays(self::CACHE_TTL_DAYS),
            function (): array {
                return GameRole::query()
                    ->select([
                        'id',
                        'role_id_3',
                        'role_id_6',
                        'name',
                        'probability',
                        'up_time',
                    ])
                    ->where('obtain', 1)
                    ->where('status', 1)
                    ->whereNotNull('up_time')
                    ->orderBy('up_time')
                    ->orderBy('id')
                    ->get()
                    ->map(function (GameRole $gameRole): array {
                        $upTime = $this->normalizeDate($gameRole->up_time);
                        $imageId = $gameRole->role_id_6
                            ?? $gameRole->role_id_3;

                        return [
                            'id' => (int) $gameRole->id,
                            'image_id' => $imageId ? (int) $imageId : null,
                            'name' => (string) $gameRole->name,
                            'probability' => (int) $gameRole->probability,
                            'up_time' => $upTime,
                            'year' => (int) substr($upTime, 0, 4),
                        ];
                    })
                    ->values()
                    ->all();
            }
        );

        return is_array($roles) ? $roles : [];
    }

    /**
     * @param array<int, array{year: int}> $roles
     * @return array<int, int>
     */
    public function availableYears(array $roles): array
    {
        $years = [];

        foreach ($roles as $role) {
            $year = (int) ($role['year'] ?? 0);

            if ($year > 0) {
                $years[$year] = $year;
            }
        }

        rsort($years);

        return array_values($years);
    }

    /**
     * @param array<int, array{year: int}> $roles
     * @return array<int, array<string, mixed>>
     */
    public function rolesForYear(array $roles, int $year): array
    {
        return array_values(array_filter(
            $roles,
            static fn (array $role): bool => (int) $role['year'] === $year
        ));
    }

    public function imageUrl(?int $imageId): ?string
    {
        return $imageId ? url("images/roles/{$imageId}.webp") : null;
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function normalizeDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
