<?php

namespace App\Services;

final class SystemRoleAssignment
{
    /**
     * @param  array<int, array<int, array{role: int, mask: array<int, int>, count: int}>>  $optionSets
     * @return array<int, array{role: int, mask: array<int, int>, count: int}>|null
     */
    public function findTripleAssignment(
        array $optionSets,
        SystemRoleMask $roleMask,
        int &$trials
    ): ?array {
        if (count($optionSets) !== 3) {
            return null;
        }

        $best = null;

        foreach ($optionSets[0] as $first) {
            foreach ($optionSets[1] as $second) {
                if ($roleMask->intersects($first['mask'], $second['mask'])) {
                    continue;
                }

                $firstTwoMask = $roleMask->union($first['mask'], $second['mask']);

                foreach ($optionSets[2] as $third) {
                    $trials++;
                    $count = $first['count'] + $second['count'] + $third['count'];

                    if ($best !== null && $count > $best['count']) {
                        continue;
                    }

                    if ($roleMask->intersects($firstTwoMask, $third['mask'])) {
                        continue;
                    }

                    if ($best !== null && $count === $best['count']) {
                        continue;
                    }

                    $best = [
                        'count' => $count,
                        'options' => [$first, $second, $third],
                    ];
                }
            }
        }

        return $best === null ? null : $best['options'];
    }
}
