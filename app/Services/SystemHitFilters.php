<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class SystemHitFilters
{
    /**
     * @param  iterable<int, int>  $teamIds
     * @return array<int, int>
     */
    public function normalizeTeamIds(iterable $teamIds): array
    {
        $normalized = [];

        foreach ($teamIds as $teamId) {
            $teamId = (int) $teamId;

            if ($teamId > 0) {
                $normalized[$teamId] = $teamId;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * @param  array<int, int>  $bosses
     * @return array<int, int>
     */
    public function normalizeBosses(array $bosses): array
    {
        $bosses = array_values(array_filter(
            array_map('intval', $bosses),
            static fn (int $boss): bool => $boss > 0
        ));
        sort($bosses, SORT_NUMERIC);

        return $bosses;
    }

    /**
     * @param  array<int, int>  $lockedIds
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    public function splitLockedIdsByStage(
        array $lockedIds,
        array $snapshots
    ): array {
        $lowLockedIds = [];
        $highLockedIds = [];

        foreach ($lockedIds as $teamId) {
            $stage = (int) ($snapshots[$teamId]['stage'] ?? 0);

            if ($stage === 2 || $stage === 3) {
                $lowLockedIds[] = $teamId;
            } elseif ($stage === 4) {
                $highLockedIds[] = $teamId;
            } else {
                throw ValidationException::withMessages([
                    'lockedIds' => '锁定作业不存在或不属于当前月份及当前难度',
                ]);
            }
        }

        if (count($lowLockedIds) > 2) {
            throw ValidationException::withMessages([
                'lockedIds' => '低阶段最多锁定 2 个作业',
            ]);
        }

        if (count($highLockedIds) > 2) {
            throw ValidationException::withMessages([
                'lockedIds' => '高阶段最多锁定 2 个作业',
            ]);
        }

        return [$lowLockedIds, $highLockedIds];
    }

    /**
     * @param  array<int, int>  $comboBosses
     * @param  array<int, int>  $selectedBosses
     */
    public function passesBossFilter(
        array $comboBosses,
        array $selectedBosses
    ): bool {
        $selectedCount = count($selectedBosses);

        if ($selectedCount === 0) {
            return true;
        }

        if ($selectedCount === 1) {
            return count(array_intersect(
                $selectedBosses,
                $comboBosses
            )) > 0;
        }

        if ($selectedCount === 2) {
            if (count(array_unique($selectedBosses)) === 1) {
                return count(array_intersect(
                    $comboBosses,
                    $selectedBosses
                )) >= 2;
            }

            return count(array_intersect(
                $selectedBosses,
                $comboBosses
            )) === 2;
        }

        return $comboBosses === $selectedBosses;
    }

    public function passesAttackFilter(
        int $atkType,
        int $physicalCount,
        int $magicCount
    ): bool {
        return match ($atkType) {
            0 => true,
            1 => $physicalCount === 3,
            2 => $physicalCount === 2 && $magicCount === 1,
            3 => $physicalCount === 1 && $magicCount === 2,
            4 => $magicCount === 3,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function hash(array $filters): string
    {
        return hash('sha256', json_encode(
            $filters,
            JSON_THROW_ON_ERROR
        ));
    }
}
