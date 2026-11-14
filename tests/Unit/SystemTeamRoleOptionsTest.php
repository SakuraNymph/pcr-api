<?php

namespace Tests\Unit;

use App\Services\SystemRoleMask;
use App\Services\SystemTeamRoleOptions;
use PHPUnit\Framework\TestCase;

class SystemTeamRoleOptionsTest extends TestCase
{
    public function test_status_zero_role_is_mandatory(): void
    {
        $profile = $this->profile(
            [1 => 1, 2 => 0, 3 => 1],
            [1 => true, 3 => true]
        );

        $this->assertSame([2], $profile['mandatory_roles']);
        $this->assertSame([1, 3], $profile['optional_roles']);
        $this->assertSame(2, $profile['borrow_options'][0]['role']);
        $this->assertSame(1, $profile['borrow_options'][0]['count']);
    }

    public function test_account_missing_role_is_mandatory(): void
    {
        $profile = $this->profile(
            [1 => 1, 2 => 1, 3 => 1],
            [1 => true, 3 => true]
        );

        $this->assertSame([2], $profile['mandatory_roles']);
        $this->assertSame([1, 3], $profile['optional_roles']);
        $this->assertSame(2, $profile['borrow_options'][0]['role']);
    }

    public function test_more_than_one_mandatory_role_removes_borrow_options(): void
    {
        $profile = $this->profile(
            [1 => 0, 2 => 0, 3 => 1],
            [3 => true]
        );

        $this->assertSame([1, 2], $profile['mandatory_roles']);
        $this->assertSame([3], $profile['optional_roles']);
        $this->assertSame([], $profile['borrow_options']);
    }

    public function test_public_and_account_modes_share_effective_mask_when_all_roles_are_owned(): void
    {
        $options = new SystemTeamRoleOptions();
        $mask = new SystemRoleMask([1, 2, 3]);
        $snapshot = [10 => ['roles' => [1 => 1, 2 => 1, 3 => 1]]];

        $publicProfile = $options->buildProfiles(
            $snapshot,
            null,
            $mask
        )[10];
        $accountProfile = $options->buildProfiles(
            $snapshot,
            [1 => true, 2 => true, 3 => true],
            $mask
        )[10];

        $this->assertSame(
            $publicProfile['effective_mask'],
            $accountProfile['effective_mask']
        );
        $this->assertSame(
            $publicProfile['borrow_options'],
            $accountProfile['borrow_options']
        );
    }

    /**
     * @param  array<int, int>  $roles
     * @param  array<int, bool>  $accountRoles
     * @return array<string, mixed>
     */
    private function profile(array $roles, array $accountRoles): array
    {
        $options = new SystemTeamRoleOptions();
        $mask = new SystemRoleMask(array_keys($roles));

        return $options->buildProfiles(
            [10 => ['roles' => $roles]],
            $accountRoles,
            $mask
        )[10];
    }
}
