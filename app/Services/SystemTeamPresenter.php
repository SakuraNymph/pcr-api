<?php

namespace App\Services;

final class SystemTeamPresenter
{
    /**
     * @param  array<int, int>  $teamIds
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, int>  $borrows
     * @param  array<int, bool>|null  $accountRoles
     * @return array<int, array<string, mixed>>
     */
    public function presentTeams(
        array $teamIds,
        array $snapshots,
        array $borrows,
        ?array $accountRoles = null
    ): array {
        return array_map(
            fn (int $teamId): array => $this->presentTeam(
                $teamId,
                $snapshots,
                $borrows,
                $accountRoles
            ),
            $teamIds
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, int>  $borrows
     * @param  array<int, bool>|null  $accountRoles
     * @return array<string, mixed>
     */
    public function presentTeam(
        int $teamId,
        array $snapshots,
        array $borrows,
        ?array $accountRoles = null
    ): array {
        $team = $snapshots[$teamId];
        $roles = [];

        foreach ($team['roles'] as $roleId => $roleStatus) {
            $roleId = (int) $roleId;
            $status = (int) $roleStatus;

            if ($accountRoles !== null) {
                $status = $status === 1 && isset($accountRoles[$roleId])
                    ? 1
                    : 0;
            }

            $details = $team['role_details'][$roleId] ?? [
                'image_id' => null,
                'image_url' => null,
            ];
            $imageId = $details['image_id'] ?? null;
            $imageUrl = $details['image_url'] ?? null;
            $roles[] = [
                'image_id' => $imageId,
                'image_url' => $imageUrl ?? (
                    $imageId ? url("images/roles/{$imageId}.webp") : null
                ),
                'role_id' => $roleId,
                'status' => $status,
            ];
        }

        return [
            'id' => $teamId,
            'boss' => (int) $team['boss'],
            'damage' => (int) $team['damage'],
            'atk_value' => (int) $team['atk_value'],
            'roles' => $roles,
            'borrow' => (int) (
                $borrows[$teamId] ?? $borrows[(string) $teamId] ?? 0
            ),
            'remark' => (string) ($team['remark'] ?? ''),
            'stage' => (int) $team['stage'],
            'link' => $team['links'],
        ];
    }
}
