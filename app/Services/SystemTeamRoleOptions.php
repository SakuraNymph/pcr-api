<?php

namespace App\Services;

final class SystemTeamRoleOptions
{
    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, int>
     */
    public function collectRoleIds(array $snapshots): array
    {
        $roleIds = [];

        foreach ($snapshots as $snapshot) {
            foreach (($snapshot['roles'] ?? []) as $roleId => $_) {
                $roleId = (int) $roleId;

                if ($roleId > 0) {
                    $roleIds[] = $roleId;
                }
            }
        }

        $roleIds = array_values(array_unique($roleIds));
        sort($roleIds, SORT_NUMERIC);

        return $roleIds;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, bool>|null  $accountRoles
     * @return array<int, array{
     *     raw_mask: array<int, int>,
     *     effective_mask: array<int, int>,
     *     mandatory_roles: array<int, int>,
     *     optional_roles: array<int, int>,
     *     borrow_options: array<int, array{
     *         role: int,
     *         mask: array<int, int>,
     *         count: int
     *     }>
     * }>
     */
    public function buildProfiles(
        array $snapshots,
        ?array $accountRoles,
        SystemRoleMask $roleMask
    ): array {
        $result = [];

        foreach ($snapshots as $teamId => $snapshot) {
            $roles = $snapshot['roles'] ?? [];
            $rawRoles = [];
            $activeRoles = [];
            $mandatoryRoles = [];
            $optionalRoles = [];

            foreach ($roles as $roleId => $status) {
                $roleId = (int) $roleId;
                $status = (int) $status;
                $rawRoles[] = $roleId;

                if ($status === 0) {
                    $mandatoryRoles[] = $roleId;

                    continue;
                }

                if ($accountRoles === null) {
                    $activeRoles[] = $roleId;
                    $optionalRoles[] = $roleId;

                    continue;
                }

                if (isset($accountRoles[$roleId])) {
                    $activeRoles[] = $roleId;
                    $optionalRoles[] = $roleId;
                } else {
                    $mandatoryRoles[] = $roleId;
                }
            }

            $mandatoryRoles = array_values(array_unique($mandatoryRoles));
            $optionalRoles = array_values(array_unique($optionalRoles));
            sort($mandatoryRoles, SORT_NUMERIC);
            sort($optionalRoles, SORT_NUMERIC);
            $effectiveMask = $roleMask->maskFromRoleIds($activeRoles);

            $result[(int) $teamId] = [
                'raw_mask' => $roleMask->maskFromRoleIds($rawRoles),
                'effective_mask' => $effectiveMask,
                'mandatory_roles' => $mandatoryRoles,
                'optional_roles' => $optionalRoles,
                'borrow_options' => $this->makeBorrowOptions(
                    $roleMask,
                    $effectiveMask,
                    $mandatoryRoles,
                    $optionalRoles
                ),
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $mandatoryRoles
     * @param  array<int, int>  $optionalRoles
     * @return array<int, array{role: int, mask: array<int, int>, count: int}>
     */
    private function makeBorrowOptions(
        SystemRoleMask $roleMask,
        array $effectiveMask,
        array $mandatoryRoles,
        array $optionalRoles
    ): array {
        if (count($mandatoryRoles) > 1) {
            return [];
        }

        if (count($mandatoryRoles) === 1) {
            $roleId = (int) $mandatoryRoles[0];

            return [[
                'role' => $roleId,
                'mask' => $roleMask->withoutRole($effectiveMask, $roleId),
                'count' => 1,
            ]];
        }

        $options = [[
            'role' => 0,
            'mask' => $effectiveMask,
            'count' => 0,
        ]];

        foreach ($optionalRoles as $roleId) {
            $options[] = [
                'role' => (int) $roleId,
                'mask' => $roleMask->withoutRole(
                    $effectiveMask,
                    (int) $roleId
                ),
                'count' => 1,
            ];
        }

        return $options;
    }
}
