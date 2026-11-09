<?php

namespace Tests\Feature;

use App\Models\Guild;
use App\Models\GuildApplication;
use App\Models\GuildInvitation;
use App\Models\GuildLog;
use App\Models\GuildMember;
use App\Models\GuildMonth;
use App\Models\GuildMonthMember;
use App\Models\GameRole;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuildApiTest extends TestCase
{
    private User $owner;
    private User $member;
    private User $vice;
    private User $outsider;
    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-05 12:00:00'));
        $this->createTestTables();

        $this->owner = $this->createUser('owner@example.com', '会长');
        $this->member = $this->createUser('member@example.com', '成员');
        $this->vice = $this->createUser('vice@example.com', '副会长');
        $this->outsider = $this->createUser('outsider@example.com', '外部用户');
        $this->guest = $this->createUser('guest@example.com', '游客', 0);

        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_is_rejected_from_all_guild_endpoints(): void
    {
        Sanctum::actingAs($this->guest);

        $this->getJson('/api/user/guilds')->assertUnauthorized();
        $this->postJson('/api/user/guilds', ['name' => '游客公会'])->assertUnauthorized();
        $this->getJson('/api/user/guilds/overview')->assertUnauthorized();
        $this->getJson('/api/user/guilds/1')->assertUnauthorized();
        $this->putJson('/api/user/guilds/1', ['name' => '游客公会'])->assertUnauthorized();
        $this->deleteJson('/api/user/guilds/1')->assertUnauthorized();
        $this->deleteJson('/api/user/guilds/1/members/me')->assertUnauthorized();
        $this->deleteJson('/api/user/guilds/1/members/2')->assertUnauthorized();
        $this->putJson('/api/user/guilds/1/members/2/vice')->assertUnauthorized();
        $this->deleteJson('/api/user/guilds/1/members/2/vice')->assertUnauthorized();
        $this->putJson('/api/user/guilds/1/owner', ['target_uid' => 2])->assertUnauthorized();
        $this->getJson('/api/user/guilds/1/invitation-candidates?uid=2')->assertUnauthorized();
        $this->postJson('/api/user/guilds/1/invitations', ['invitee_uid' => 2])->assertUnauthorized();
        $this->getJson('/api/user/guilds/1/applications')->assertUnauthorized();
        $this->postJson('/api/user/guilds/1/applications')->assertUnauthorized();
        $this->postJson('/api/user/guilds/1/applications/1/approve')->assertUnauthorized();
        $this->postJson('/api/user/guilds/1/applications/1/reject')->assertUnauthorized();
        $this->deleteJson('/api/user/guild-applications/1')->assertUnauthorized();
        $this->getJson('/api/user/guild-invitations')->assertUnauthorized();
        $this->deleteJson('/api/user/guild-invitations/1')->assertUnauthorized();
        $this->postJson('/api/user/guild-invitations/1/accept')->assertUnauthorized();
        $this->postJson('/api/user/guild-invitations/1/reject')->assertUnauthorized();
        $this->getJson('/api/user/guilds/1/schedule')->assertUnauthorized();
        $this->getJson('/api/user/guilds/1/schedule/team-options')->assertUnauthorized();
        $this->putJson('/api/user/guilds/1/schedule/members/2', ['version' => 0])->assertUnauthorized();
        $this->postJson('/api/user/guilds/1/schedule/copy', [
            'source_uid' => 2,
            'target_uid' => 3,
        ])->assertUnauthorized();
        $this->deleteJson('/api/user/guilds/1/schedule')->assertUnauthorized();
    }

    public function test_create_search_and_detail_permissions(): void
    {
        $created = $this->postJson('/api/user/guilds', [
            'name' => '星夜公会',
            'description' => '一起出刀',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', '星夜公会')
            ->assertJsonPath('data.owner_uid', $this->owner->id)
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.remaining_slots', 29)
            ->assertJsonPath('data.viewer_membership.role', 1)
            ->assertJsonCount(1, 'data.members');

        $guildId = (int) $created->json('data.id');

        $this->postJson('/api/user/guilds', ['name' => '第二个公会'])
            ->assertStatus(409);

        Sanctum::actingAs($this->outsider);

        $second = $this->postJson('/api/user/guilds', [
            'name' => '星夜公会',
            'description' => '同名公会',
        ])->assertOk();
        $secondId = (int) $second->json('data.id');

        $this->assertNotSame($guildId, $secondId);

        $this->getJson('/api/user/guilds?keyword=星夜')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.name', '星夜公会');

        $this->getJson("/api/user/guilds/{$guildId}")
            ->assertOk()
            ->assertJsonPath('data.permissions.is_member', false)
            ->assertJsonMissingPath('data.members');

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/user/guilds/{$guildId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.uid', $this->owner->id);
    }

    public function test_invitation_acceptance_reserves_capacity_and_invalidates_other_invitations(): void
    {
        $guildId = $this->createGuild();

        $this->getJson("/api/user/guilds/{$guildId}/invitation-candidates?uid={$this->outsider->id}")
            ->assertOk()
            ->assertJsonPath('data.eligible', true);

        $invitation = $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->outsider->id,
        ])->assertOk();
        $invitationId = (int) $invitation->json('data.id');

        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->outsider->id,
        ])->assertStatus(409);

        $this->getJson('/api/user/guilds/overview')
            ->assertOk()
            ->assertJsonPath('data.guild.pending_invitation_count', 1)
            ->assertJsonPath('data.guild.remaining_slots', 28);

        Sanctum::actingAs($this->outsider);

        $this->getJson('/api/user/guild-invitations?type=received')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.guild_name', '测试公会');

        $this->postJson("/api/user/guild-invitations/{$invitationId}/accept")
            ->assertOk()
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.viewer_membership.role', 3);

        $this->getJson("/api/user/guilds/{$guildId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.members');

        $this->assertDatabaseHas('guild_members', [
            'guild_id' => $guildId,
            'uid' => $this->outsider->id,
            'status' => 1,
            'role' => 3,
        ]);
    }

    public function test_pending_invitations_reserve_the_thirtieth_slot(): void
    {
        $guildId = $this->createGuild();

        for ($index = 0; $index < 28; $index++) {
            $user = $this->createUser("bulk{$index}@example.com", "成员{$index}");
            GuildMember::query()->create([
                'guild_id' => $guildId,
                'uid' => $user->id,
                'role' => GuildMember::ROLE_MEMBER,
                'member_slot' => $index + 2,
                'vice_slot' => null,
                'status' => GuildMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
        }

        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->outsider->id,
        ])->assertOk();

        $this->getJson('/api/user/guilds/overview')
            ->assertOk()
            ->assertJsonPath('data.guild.member_count', 29)
            ->assertJsonPath('data.guild.pending_invitation_count', 1)
            ->assertJsonPath('data.guild.remaining_slots', 0)
            ->assertJsonPath('data.guild.capacity_status', 'reserved');

        $another = $this->createUser('another@example.com', '另一人');

        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $another->id,
        ])->assertStatus(409);
    }

    public function test_leave_requires_a_new_invitation_created_after_leaving(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);

        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/user/guilds/{$guildId}/members/me")
            ->assertOk();

        $oldInvitation = GuildInvitation::query()->create([
            'guild_id' => $guildId,
            'inviter_uid' => $this->owner->id,
            'invitee_uid' => $this->member->id,
            'status' => GuildInvitation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->postJson("/api/user/guild-invitations/{$oldInvitation->id}/accept")
            ->assertStatus(409);

        $oldInvitation->status = GuildInvitation::STATUS_EXPIRED;
        $oldInvitation->handled_at = now();
        $oldInvitation->save();

        Sanctum::actingAs($this->owner);
        Carbon::setTestNow(now()->addSecond());
        $newInvitation = $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->member->id,
        ])->assertOk();

        Sanctum::actingAs($this->member);
        $this->postJson(
            '/api/user/guild-invitations/'.$newInvitation->json('data.id').'/accept'
        )->assertOk();

        $this->assertSame(
            2,
            GuildMember::query()
                ->where('guild_id', $guildId)
                ->where('uid', $this->member->id)
                ->count()
        );
    }

    public function test_vice_limit_transfer_and_dissolve(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        $this->addMemberByInvitation($guildId, $this->vice);
        $third = $this->createUser('third@example.com', '第三人');
        $this->addMemberByInvitation($guildId, $third);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->member->id}/vice")
            ->assertOk();
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->vice->id}/vice")
            ->assertOk();
        $this->putJson("/api/user/guilds/{$guildId}/members/{$third->id}/vice")
            ->assertStatus(409);
        $this->deleteJson("/api/user/guilds/{$guildId}/members/{$this->member->id}/vice")
            ->assertOk();
        $this->putJson("/api/user/guilds/{$guildId}/members/{$third->id}/vice")
            ->assertOk();
        $this->deleteJson("/api/user/guilds/{$guildId}/members/me")
            ->assertStatus(409);
        $this->deleteJson("/api/user/guilds/{$guildId}/members/{$this->owner->id}")
            ->assertStatus(409);
        $this->putJson("/api/user/guilds/{$guildId}/owner", [
            'target_uid' => $this->owner->id,
        ])->assertStatus(409);

        $this->putJson("/api/user/guilds/{$guildId}/owner", [
            'target_uid' => $this->member->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.owner_uid', $this->member->id);

        $this->assertDatabaseHas('guild_members', [
            'guild_id' => $guildId,
            'uid' => $this->owner->id,
            'role' => GuildMember::ROLE_MEMBER,
        ]);
        $this->assertDatabaseHas('guild_members', [
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'role' => GuildMember::ROLE_OWNER,
        ]);

        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/user/guilds/{$guildId}")->assertOk();

        $this->assertDatabaseHas('guilds', [
            'id' => $guildId,
            'status' => 0,
        ]);
        $this->assertSame(
            0,
            GuildMember::query()
                ->where('guild_id', $guildId)
                ->where('status', GuildMember::STATUS_ACTIVE)
                ->count()
        );
        $this->getJson("/api/user/guilds/{$guildId}")->assertNotFound();

        Sanctum::actingAs($this->owner);
        $newGuild = $this->postJson('/api/user/guilds', [
            'name' => '新公会',
        ])->assertOk();

        $this->assertNotSame($guildId, (int) $newGuild->json('data.id'));
    }

    public function test_vice_and_member_management_permission_matrix(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        $this->addMemberByInvitation($guildId, $this->vice);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->vice->id}/vice")
            ->assertOk();

        Sanctum::actingAs($this->vice);
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->member->id}/vice")
            ->assertForbidden();
        $this->deleteJson("/api/user/guilds/{$guildId}/members/{$this->member->id}/vice")
            ->assertForbidden();
        $this->deleteJson("/api/user/guilds/{$guildId}/members/{$this->member->id}")
            ->assertForbidden();
        $this->deleteJson("/api/user/guilds/{$guildId}")->assertForbidden();
        $this->putJson("/api/user/guilds/{$guildId}", ['name' => '副会长改名'])
            ->assertForbidden();
        $this->putJson("/api/user/guilds/{$guildId}/owner", [
            'target_uid' => $this->member->id,
        ])->assertForbidden();
        $this->deleteJson("/api/user/guilds/{$guildId}/schedule")
            ->assertForbidden();
        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->outsider->id,
        ])->assertOk();

        Sanctum::actingAs($this->member);
        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->createUser('member-invite@example.com', '被邀请人')->id,
        ])->assertForbidden();
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->vice->id}/vice")
            ->assertForbidden();
        $this->deleteJson("/api/user/guilds/{$guildId}/members/{$this->vice->id}")
            ->assertForbidden();
        $this->putJson("/api/user/guilds/{$guildId}", ['name' => '成员改名'])
            ->assertForbidden();
        $this->deleteJson("/api/user/guilds/{$guildId}")->assertForbidden();
        $this->putJson("/api/user/guilds/{$guildId}/owner", [
            'target_uid' => $this->owner->id,
        ])->assertForbidden();
    }

    public function test_current_month_initializes_once_and_mid_month_member_gets_six_empty_slots(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);

        Sanctum::actingAs($this->owner);
        $first = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk()
            ->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.is_current_month', true)
            ->assertJsonPath('data.initialized', true)
            ->assertJsonCount(2, 'data.members')
            ->assertJsonCount(6, 'data.members.0.slots');

        $this->assertSame(1, GuildMonth::query()->where('guild_id', $guildId)->count());
        $this->assertSame(2, GuildMonthMember::query()->where('guild_id', $guildId)->count());

        $this->getJson("/api/user/guilds/{$guildId}/schedule")->assertOk();
        $this->assertSame(2, GuildMonthMember::query()->where('guild_id', $guildId)->count());

        $this->addMemberByInvitation($guildId, $this->vice);

        $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk()
            ->assertJsonCount(3, 'data.members');

        $this->assertSame(3, GuildMonthMember::query()->where('guild_id', $guildId)->count());
    }

    public function test_schedule_permissions_version_conflicts_and_return_team_rules(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        $this->addMemberByInvitation($guildId, $this->vice);
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->vice->id}/vice")
            ->assertOk();

        $bTeam = $this->createTeam(2, 1);
        $cTeam = $this->createTeam(3, 1);
        $dTeam = $this->createTeam(4, 1);

        $schedule = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk();
        $ownerRow = collect($schedule->json('data.members'))
            ->firstWhere('uid', $this->owner->id);

        $saved = $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->owner->id}",
            [
                'version' => $ownerRow['version'],
                'team_1' => $bTeam->id,
                'team_1_return' => $cTeam->id,
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.members.0.team_1_return', $cTeam->id);

        $ownerRow = collect($saved->json('data.members'))
            ->firstWhere('uid', $this->owner->id);

        $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->owner->id}",
            [
                'version' => $ownerRow['version'] - 1,
                'team_1' => $cTeam->id,
            ]
        )->assertStatus(409);

        $saved = $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->owner->id}",
            [
                'version' => $ownerRow['version'],
                'team_1' => $dTeam->id,
                'team_1_return' => $cTeam->id,
            ]
        )->assertOk();

        $ownerRow = collect($saved->json('data.members'))
            ->firstWhere('uid', $this->owner->id);
        $this->assertNull($ownerRow['team_1_return']);

        Sanctum::actingAs($this->member);
        $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->vice->id}",
            ['version' => 0, 'team_1' => $bTeam->id]
        )->assertForbidden();

        Sanctum::actingAs($this->vice);
        $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->member->id}",
            ['version' => 0, 'team_1' => $bTeam->id]
        )->assertOk();

        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/user/guilds/{$guildId}/schedule")
            ->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk()
            ->assertJsonPath('data.members.0.team_1', null);
    }

    public function test_schedule_copy_requires_manager_and_copies_all_six_slots(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        $this->addMemberByInvitation($guildId, $this->vice);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->vice->id}/vice")
            ->assertOk();

        $bTeam = $this->createTeam(2, 1);
        $cTeam = $this->createTeam(3, 1);
        $dTeam = $this->createTeam(4, 1);

        $schedule = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk();
        $memberRow = collect($schedule->json('data.members'))
            ->firstWhere('uid', $this->member->id);

        $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->member->id}",
            [
                'version' => $memberRow['version'],
                'team_1' => $bTeam->id,
                'team_1_return' => $cTeam->id,
                'team_2' => $dTeam->id,
            ]
        )->assertOk();

        // 会长将成员A(填充刀)复制到副会长B。
        $copy = $this->postJson(
            "/api/user/guilds/{$guildId}/schedule/copy",
            [
                'source_uid' => $this->member->id,
                'target_uid' => $this->vice->id,
            ]
        )->assertOk();

        $sourceAfter = collect($copy->json('data.members'))
            ->firstWhere('uid', $this->member->id);
        $targetAfter = collect($copy->json('data.members'))
            ->firstWhere('uid', $this->vice->id);

        foreach ([
            'team_1',
            'team_1_return',
            'team_2',
            'team_2_return',
            'team_3',
            'team_3_return',
        ] as $field) {
            $this->assertSame($sourceAfter[$field], $targetAfter[$field]);
        }
        $this->assertSame($bTeam->id, $targetAfter['team_1']);
        $this->assertSame($cTeam->id, $targetAfter['team_1_return']);
        $this->assertSame($dTeam->id, $targetAfter['team_2']);
        $this->assertGreaterThan($memberRow['version'], $targetAfter['version']);

        $this->assertDatabaseHas('guild_logs', [
            'guild_id' => $guildId,
            'actor_uid' => $this->owner->id,
            'target_uid' => $this->vice->id,
            'action' => GuildLog::ACTION_SCHEDULE_COPIED,
        ]);

        // 副会长也可复制。
        Sanctum::actingAs($this->vice);
        $this->postJson(
            "/api/user/guilds/{$guildId}/schedule/copy",
            [
                'source_uid' => $this->member->id,
                'target_uid' => $this->owner->id,
            ]
        )->assertOk();

        // 普通成员复制 -> 403。
        Sanctum::actingAs($this->member);
        $this->postJson(
            "/api/user/guilds/{$guildId}/schedule/copy",
            [
                'source_uid' => $this->vice->id,
                'target_uid' => $this->owner->id,
            ]
        )->assertForbidden();

        // 来源与目标相同 -> 422。
        Sanctum::actingAs($this->owner);
        $this->postJson(
            "/api/user/guilds/{$guildId}/schedule/copy",
            [
                'source_uid' => $this->owner->id,
                'target_uid' => $this->owner->id,
            ]
        )->assertUnprocessable();

        // 来源6刀全空 -> 422 且目标数据不变。
        $cleanMember = $this->createUser('clean@example.com', '空白成员');
        $this->addMemberByInvitation($guildId, $cleanMember);
        $ownerRow = collect(
            $this->getJson("/api/user/guilds/{$guildId}/schedule")
                ->assertOk()
                ->json('data.members')
        )->firstWhere('uid', $this->owner->id);

        $this->postJson(
            "/api/user/guilds/{$guildId}/schedule/copy",
            [
                'source_uid' => $cleanMember->id,
                'target_uid' => $this->owner->id,
            ]
        )->assertUnprocessable();

        $afterEmpty = collect(
            $this->getJson("/api/user/guilds/{$guildId}/schedule")
                ->assertOk()
                ->json('data.members')
        )->firstWhere('uid', $this->owner->id);
        $this->assertSame($ownerRow['team_1'], $afterEmpty['team_1']);
        $this->assertSame(
            $ownerRow['team_1_return'],
            $afterEmpty['team_1_return']
        );
        $this->assertSame($ownerRow['version'], $afterEmpty['version']);
    }

    public function test_deleted_selected_team_is_returned_as_deleted_and_cannot_be_reselected(): void
    {
        $guildId = $this->createGuild();
        $team = $this->createTeam(2, 1);
        $returnTeam = $this->createTeam(3, 1);

        $schedule = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk();
        $row = $schedule->json('data.members.0');

        $saved = $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->owner->id}",
            [
                'version' => $row['version'],
                'team_1' => $team->id,
                'team_1_return' => $returnTeam->id,
            ]
        )->assertOk();

        $team->status = 0;
        $team->save();

        $afterDelete = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk();
        $row = $afterDelete->json('data.members.0');
        $mainSlot = collect($row['slots'])->firstWhere('key', 'team_1');

        $this->assertTrue($mainSlot['deleted']);
        $this->assertSame($returnTeam->id, $row['team_1_return']);

        $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->owner->id}",
            [
                'version' => $row['version'],
                'team_1' => $team->id,
                'team_1_return' => $this->createTeam(3, 2)->id,
            ]
        )->assertUnprocessable();
    }

    public function test_schedule_displays_latest_team_data_after_admin_edits(): void
    {
        $guildId = $this->createGuild();
        $team = $this->createTeam(2, 1);
        $returnTeam = $this->createTeam(2, 2);

        $firstRole = $this->createGameRole(9001, 110001, 10);
        $secondRole = $this->createGameRole(9002, 110002, 20);
        TeamRole::query()->create([
            'team_id' => $team->id,
            'role_id' => $firstRole->id,
            'status' => 1,
        ]);

        $schedule = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk();
        $row = $schedule->json('data.members.0');

        $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->owner->id}",
            [
                'version' => $row['version'],
                'team_1' => $team->id,
                'team_1_return' => $returnTeam->id,
            ]
        )->assertOk();

        // 管理员修改阶段、BOSS、伤害和阵容后，公会排刀展示最新作业数据。
        $team->stage = 3;
        $team->boss = 2;
        $team->damage = 4321;
        $team->save();
        TeamRole::query()
            ->where('team_id', $team->id)
            ->update(['role_id' => $secondRole->id]);

        $latest = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk()
            ->assertJsonPath('data.members.0.team_1', $team->id)
            ->assertJsonPath('data.members.0.slots.0.team.stage', 3)
            ->assertJsonPath('data.members.0.slots.0.team.boss', 2)
            ->assertJsonPath('data.members.0.slots.0.team.damage', 4321)
            ->assertJsonPath(
                'data.members.0.slots.0.team.team.0.role_id',
                $secondRole->id
            );

        // 主刀从 B 阶段改为 C 阶段时，已选主刀和返还刀都保留。
        $this->assertSame(
            $returnTeam->id,
            $latest->json('data.members.0.team_1_return')
        );
    }

    public function test_leave_preserves_history_but_removes_access_and_current_month_rows(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/user/guilds/{$guildId}/schedule")->assertOk();

        $october = GuildMonth::query()->create([
            'guild_id' => $guildId,
            'year_month' => '2026-10',
        ]);
        GuildMonthMember::query()->create([
            'month_id' => $october->id,
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'nickname' => '历史昵称',
            'role' => GuildMember::ROLE_MEMBER,
            'member_slot' => 2,
        ]);

        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/user/guilds/{$guildId}/members/me")
            ->assertOk();

        $this->getJson("/api/user/guilds/{$guildId}/schedule?month=2026-10")
            ->assertForbidden();
        $this->assertDatabaseHas('guild_month_members', [
            'month_id' => $october->id,
            'uid' => $this->member->id,
            'nickname' => '历史昵称',
        ]);
        $this->assertDatabaseMissing('guild_month_members', [
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'month_id' => GuildMonth::query()
                ->where('guild_id', $guildId)
                ->where('year_month', '2026-11')
                ->value('id'),
        ]);
    }

    public function test_database_uniqueness_rejects_duplicate_active_membership_owner_vice_and_invitation(): void
    {
        $guildId = $this->createGuild();

        try {
            GuildMember::query()->create([
                'guild_id' => $guildId,
                'uid' => $this->owner->id,
                'role' => GuildMember::ROLE_MEMBER,
                'member_slot' => 2,
                'status' => GuildMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
            $this->fail('同一用户不应能同时加入两个正常运营公会');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        try {
            GuildMember::query()->create([
                'guild_id' => $guildId,
                'uid' => $this->member->id,
                'role' => GuildMember::ROLE_OWNER,
                'member_slot' => 2,
                'status' => GuildMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
            $this->fail('同一公会不应出现第二个会长');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        GuildMember::query()->create([
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'role' => GuildMember::ROLE_VICE,
            'member_slot' => 2,
            'vice_slot' => 1,
            'status' => GuildMember::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        try {
            GuildMember::query()->create([
                'guild_id' => $guildId,
                'uid' => $this->vice->id,
                'role' => GuildMember::ROLE_VICE,
                'member_slot' => 3,
                'vice_slot' => 1,
                'status' => GuildMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
            $this->fail('同一副会长槽位不能被重复占用');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        GuildInvitation::query()->create([
            'guild_id' => $guildId,
            'inviter_uid' => $this->owner->id,
            'invitee_uid' => $this->outsider->id,
            'status' => GuildInvitation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        try {
            GuildInvitation::query()->create([
                'guild_id' => $guildId,
                'inviter_uid' => $this->owner->id,
                'invitee_uid' => $this->outsider->id,
                'status' => GuildInvitation::STATUS_PENDING,
                'expires_at' => now()->addDay(),
            ]);
            $this->fail('同一公会不能重复发送有效邀请');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }
    }

    public function test_exact_thirtieth_member_is_allowed_and_more_invitations_are_rejected(): void
    {
        $guildId = $this->createGuild();

        for ($index = 0; $index < 28; $index++) {
            $user = $this->createUser("capacity{$index}@example.com", "容量{$index}");
            GuildMember::query()->create([
                'guild_id' => $guildId,
                'uid' => $user->id,
                'role' => GuildMember::ROLE_MEMBER,
                'member_slot' => $index + 2,
                'vice_slot' => null,
                'status' => GuildMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
        }

        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->outsider->id,
        ])->assertOk();

        Sanctum::actingAs($this->outsider);
        $invitationId = GuildInvitation::query()
            ->where('guild_id', $guildId)
            ->where('invitee_uid', $this->outsider->id)
            ->value('id');
        $this->postJson("/api/user/guild-invitations/{$invitationId}/accept")
            ->assertOk()
            ->assertJsonPath('data.member_count', 30);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/user/guilds/overview')
            ->assertOk()
            ->assertJsonPath('data.guild.member_count', 30)
            ->assertJsonPath('data.guild.remaining_slots', 0)
            ->assertJsonPath('data.guild.capacity_status', 'full');

        $another = $this->createUser('capacity-extra@example.com', '超额用户');
        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $another->id,
        ])->assertStatus(409);
    }

    public function test_reject_revoke_and_expired_invitations_release_reserved_slots(): void
    {
        $guildId = $this->createGuild();

        $rejectedId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/invitations",
            ['invitee_uid' => $this->member->id]
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->member);
        $this->postJson("/api/user/guild-invitations/{$rejectedId}/reject")
            ->assertOk();
        $this->assertDatabaseHas('guild_invitations', [
            'id' => $rejectedId,
            'status' => GuildInvitation::STATUS_REJECTED,
        ]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/user/guilds/overview')
            ->assertJsonPath('data.guild.pending_invitation_count', 0)
            ->assertJsonPath('data.guild.remaining_slots', 29);

        $revokedId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/invitations",
            ['invitee_uid' => $this->vice->id]
        )->assertOk()->json('data.id');
        $this->deleteJson("/api/user/guild-invitations/{$revokedId}")
            ->assertOk();
        $this->assertDatabaseHas('guild_invitations', [
            'id' => $revokedId,
            'status' => GuildInvitation::STATUS_REVOKED,
        ]);

        $expiredId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/invitations",
            ['invitee_uid' => $this->outsider->id]
        )->assertOk()->json('data.id');
        GuildInvitation::query()->whereKey($expiredId)->update([
            'expires_at' => now()->subSecond(),
        ]);

        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/user/guild-invitations?type=received')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
        $this->assertDatabaseHas('guild_invitations', [
            'id' => $expiredId,
            'status' => GuildInvitation::STATUS_EXPIRED,
        ]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/user/guilds/overview')
            ->assertJsonPath('data.guild.pending_invitation_count', 0)
            ->assertJsonPath('data.guild.remaining_slots', 29);
    }

    public function test_departed_inviter_invalidates_pending_invitation(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->vice);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->vice->id}/vice")
            ->assertOk();

        Sanctum::actingAs($this->vice);
        $invitationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/invitations",
            ['invitee_uid' => $this->outsider->id]
        )->assertOk()->json('data.id');

        $this->deleteJson("/api/user/guilds/{$guildId}/members/me")
            ->assertOk();
        $this->assertDatabaseHas('guild_invitations', [
            'id' => $invitationId,
            'status' => GuildInvitation::STATUS_INVALID,
        ]);

        Sanctum::actingAs($this->outsider);
        $this->postJson("/api/user/guild-invitations/{$invitationId}/accept")
            ->assertStatus(409);
    }

    public function test_kick_releases_vice_slot_and_preserves_only_historical_rows(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        $this->addMemberByInvitation($guildId, $this->vice);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->member->id}/vice")
            ->assertOk();
        $this->getJson("/api/user/guilds/{$guildId}/schedule")->assertOk();

        $october = GuildMonth::query()->create([
            'guild_id' => $guildId,
            'year_month' => '2026-10',
        ]);
        GuildMonthMember::query()->create([
            'month_id' => $october->id,
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'nickname' => '被踢前昵称',
            'role' => GuildMember::ROLE_VICE,
            'member_slot' => 2,
        ]);

        $this->deleteJson("/api/user/guilds/{$guildId}/members/{$this->member->id}")
            ->assertOk();

        $this->assertDatabaseHas('guild_members', [
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'status' => GuildMember::STATUS_LEFT,
            'vice_slot' => null,
        ]);
        $this->assertDatabaseMissing('guild_month_members', [
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'month_id' => GuildMonth::query()
                ->where('guild_id', $guildId)
                ->where('year_month', '2026-11')
                ->value('id'),
        ]);
        $this->assertDatabaseHas('guild_month_members', [
            'month_id' => $october->id,
            'uid' => $this->member->id,
            'nickname' => '被踢前昵称',
        ]);

        $this->putJson("/api/user/guilds/{$guildId}/members/{$this->vice->id}/vice")
            ->assertOk();

        Sanctum::actingAs($this->member);
        $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertForbidden();
    }

    public function test_dissolve_preserves_schedule_history_and_logs_and_invalidates_invitations(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        $this->getJson("/api/user/guilds/{$guildId}/schedule")->assertOk();

        $pendingId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/invitations",
            ['invitee_uid' => $this->outsider->id]
        )->assertOk()->json('data.id');

        $this->deleteJson("/api/user/guilds/{$guildId}")->assertOk();

        $this->assertDatabaseHas('guilds', [
            'id' => $guildId,
            'status' => Guild::STATUS_DISSOLVED,
        ]);
        $this->assertDatabaseHas('guild_invitations', [
            'id' => $pendingId,
            'status' => GuildInvitation::STATUS_INVALID,
        ]);
        $this->assertSame(0, GuildMember::query()
            ->where('guild_id', $guildId)
            ->where('status', GuildMember::STATUS_ACTIVE)
            ->count());
        $this->assertDatabaseCount('guild_months', 1);
        $this->assertSame(2, GuildMonthMember::query()
            ->where('guild_id', $guildId)
            ->count());
        $this->assertDatabaseHas('guild_logs', [
            'guild_id' => $guildId,
            'action' => 'guild.dissolved',
        ]);

        Sanctum::actingAs($this->member);
        $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertNotFound();
    }

    public function test_current_month_uses_latest_nickname_and_history_uses_snapshot(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        $this->getJson("/api/user/guilds/{$guildId}/schedule")->assertOk();

        $october = GuildMonth::query()->create([
            'guild_id' => $guildId,
            'year_month' => '2026-10',
        ]);
        GuildMonthMember::query()->create([
            'month_id' => $october->id,
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'nickname' => '十月昵称',
            'role' => GuildMember::ROLE_MEMBER,
            'member_slot' => 2,
        ]);

        $this->member->nickname = '最新昵称';
        $this->member->save();

        $current = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk();
        $currentMember = collect($current->json('data.members'))
            ->firstWhere('uid', $this->member->id);
        $this->assertSame('最新昵称', $currentMember['nickname']);

        $history = $this->getJson(
            "/api/user/guilds/{$guildId}/schedule?month=2026-10"
        )->assertOk();
        $historyMember = collect($history->json('data.members'))
            ->firstWhere('uid', $this->member->id);
        $this->assertSame('十月昵称', $historyMember['nickname']);
        $this->assertDatabaseHas('guild_month_members', [
            'month_id' => $october->id,
            'uid' => $this->member->id,
            'nickname' => '十月昵称',
        ]);
    }

    public function test_clear_current_month_does_not_touch_history(): void
    {
        $guildId = $this->createGuild();
        $team = $this->createTeam(2, 1);
        $schedule = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk();
        $row = $schedule->json('data.members.0');

        $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->owner->id}",
            [
                'version' => $row['version'],
                'team_1' => $team->id,
            ]
        )->assertOk();

        $october = GuildMonth::query()->create([
            'guild_id' => $guildId,
            'year_month' => '2026-10',
        ]);
        GuildMonthMember::query()->create([
            'month_id' => $october->id,
            'guild_id' => $guildId,
            'uid' => $this->owner->id,
            'nickname' => '会长',
            'role' => GuildMember::ROLE_OWNER,
            'member_slot' => 1,
            'team_1' => $team->id,
        ]);

        $this->deleteJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk()
            ->assertJsonPath('data.members.0.team_1', null);

        $this->assertDatabaseHas('guild_month_members', [
            'month_id' => $october->id,
            'uid' => $this->owner->id,
            'team_1' => $team->id,
        ]);
        $this->assertDatabaseHas('guild_logs', [
            'guild_id' => $guildId,
            'action' => 'schedule.cleared',
        ]);
    }

    public function test_database_rejects_duplicate_month_and_member_slot(): void
    {
        $guildId = $this->createGuild();

        GuildMonth::query()->create([
            'guild_id' => $guildId,
            'year_month' => '2026-11',
        ]);

        try {
            GuildMonth::query()->create([
                'guild_id' => $guildId,
                'year_month' => '2026-11',
            ]);
            $this->fail('同一公会同一月份只能有一条记录');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        GuildMember::query()->create([
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'role' => GuildMember::ROLE_MEMBER,
            'member_slot' => 2,
            'vice_slot' => null,
            'status' => GuildMember::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        try {
            GuildMember::query()->create([
                'guild_id' => $guildId,
                'uid' => $this->vice->id,
                'role' => GuildMember::ROLE_MEMBER,
                'member_slot' => 2,
                'vice_slot' => null,
                'status' => GuildMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
            $this->fail('同一公会的活动成员槽位不能重复');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }
    }

    public function test_schedule_save_rejects_page_that_was_left_open_across_month_boundary(): void
    {
        $guildId = $this->createGuild();
        $schedule = $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk();
        $row = $schedule->json('data.members.0');

        Carbon::setTestNow(Carbon::parse('2026-12-01 00:01:00'));

        $this->putJson(
            "/api/user/guilds/{$guildId}/schedule/members/{$this->owner->id}",
            [
                'version' => $row['version'],
                'month' => '2026-11',
            ]
        )->assertStatus(409);

        $this->deleteJson(
            "/api/user/guilds/{$guildId}/schedule?month=2026-11"
        )->assertStatus(409);
    }

    public function test_application_submission_validates_membership_invitation_and_capacity(): void
    {
        $guildId = $this->createGuild();

        Sanctum::actingAs($this->outsider);
        $applicationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )
            ->assertOk()
            ->assertJsonPath('data.status', GuildApplication::STATUS_PENDING)
            ->json('data.id');

        $this->getJson("/api/user/guilds/{$guildId}")
            ->assertOk()
            ->assertJsonPath('data.viewer_has_guild', false)
            ->assertJsonPath('data.can_apply', false)
            ->assertJsonPath('data.apply_blocked_reason', 'pending_application')
            ->assertJsonPath('data.viewer_application.id', $applicationId);

        $this->postJson("/api/user/guilds/{$guildId}/applications")
            ->assertStatus(409)
            ->assertJsonPath('msg', '已提交过申请，请等待审核');

        $this->addMemberByInvitation($guildId, $this->member);

        Sanctum::actingAs($this->member);
        $this->postJson("/api/user/guilds/{$guildId}/applications")
            ->assertStatus(409)
            ->assertJsonPath('msg', '你已经是该公会成员');

        Sanctum::actingAs($this->vice);
        $this->postJson('/api/user/guilds', [
            'name' => '另一个公会',
        ])->assertOk();
        $this->postJson("/api/user/guilds/{$guildId}/applications")
            ->assertStatus(409)
            ->assertJsonPath('msg', '你已有公会，请先退出当前公会后再申请');

        $invitee = $this->createUser('invitee@example.com', '待邀请人');
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $invitee->id,
        ])->assertOk();

        Sanctum::actingAs($invitee);
        $this->postJson("/api/user/guilds/{$guildId}/applications")
            ->assertStatus(409)
            ->assertJsonPath(
                'msg',
                '该公会已向你发送邀请，请前往公会邀请页面处理'
            );

        // 当前 2 名成员 + 1 个申请 + 1 个邀请 = 已预留 4 个名额。
        for ($index = 0; $index < 26; $index++) {
            $this->addMemberDirectly(
                $guildId,
                $this->createUser("bulk-app-{$index}@example.com", "批量成员{$index}"),
                $index + 3
            );
        }

        $lastUser = $this->createUser('last-applicant@example.com', '最后申请人');
        Sanctum::actingAs($lastUser);
        $this->postJson("/api/user/guilds/{$guildId}/applications")
            ->assertStatus(409)
            ->assertJsonPath('msg', '当前公会暂无可用名额');

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/user/guilds/overview')
            ->assertOk()
            ->assertJsonPath('data.guild.member_count', 28)
            ->assertJsonPath('data.guild.pending_invitation_count', 1)
            ->assertJsonPath('data.guild.pending_application_count', 1)
            ->assertJsonPath('data.guild.remaining_slots', 0)
            ->assertJsonPath('data.guild.capacity_status', 'reserved');
    }

    public function test_application_withdraw_reject_and_expire_release_reserved_slots(): void
    {
        $guildId = $this->createGuild();

        Sanctum::actingAs($this->outsider);
        $firstId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');

        $this->deleteJson("/api/user/guild-applications/{$firstId}")
            ->assertOk();
        $this->assertDatabaseHas('guild_applications', [
            'id' => $firstId,
            'status' => GuildApplication::STATUS_WITHDRAWN,
        ]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/user/guilds/overview')
            ->assertJsonPath('data.guild.pending_application_count', 0)
            ->assertJsonPath('data.guild.remaining_slots', 29);

        Sanctum::actingAs($this->outsider);
        $secondId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$secondId}/reject"
        )->assertOk();
        $this->assertDatabaseHas('guild_applications', [
            'id' => $secondId,
            'status' => GuildApplication::STATUS_REJECTED,
            'handler_uid' => $this->owner->id,
        ]);

        Sanctum::actingAs($this->outsider);
        $thirdId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');
        GuildApplication::query()->whereKey($thirdId)->update([
            'expires_at' => now()->subSecond(),
        ]);

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/user/guilds/{$guildId}/applications")
            ->assertOk()
            ->assertJsonPath('data.count', 0);
        $this->assertDatabaseHas('guild_applications', [
            'id' => $thirdId,
            'status' => GuildApplication::STATUS_EXPIRED,
        ]);

        Sanctum::actingAs($this->outsider);
        $this->postJson("/api/user/guilds/{$guildId}/applications")
            ->assertOk();
    }

    public function test_application_approval_uses_shared_join_logic_and_empty_schedule_slots(): void
    {
        $guildId = $this->createGuild();

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/user/guilds/{$guildId}/schedule")->assertOk();

        Sanctum::actingAs($this->vice);
        $otherGuildId = (int) $this->postJson('/api/user/guilds', [
            'name' => '竞争公会',
        ])->assertOk()->json('data.id');

        Sanctum::actingAs($this->outsider);
        $applicationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');
        $otherApplicationId = (int) $this->postJson(
            "/api/user/guilds/{$otherGuildId}/applications"
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/user/guilds/{$guildId}/applications")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath(
                'data.items.0.applicant_uid',
                (int) $this->outsider->id
            )
            ->assertJsonPath(
                'data.items.0.applicant_nickname',
                '外部用户'
            );

        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$applicationId}/approve"
        )->assertOk();

        $this->assertDatabaseHas('guild_members', [
            'guild_id' => $guildId,
            'uid' => $this->outsider->id,
            'role' => GuildMember::ROLE_MEMBER,
            'status' => GuildMember::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('guild_month_members', [
            'guild_id' => $guildId,
            'uid' => $this->outsider->id,
            'nickname' => '外部用户',
            'team_1' => null,
            'team_1_return' => null,
            'team_2' => null,
            'team_2_return' => null,
            'team_3' => null,
            'team_3_return' => null,
        ]);
        $this->assertDatabaseHas('guild_applications', [
            'id' => $applicationId,
            'status' => GuildApplication::STATUS_APPROVED,
            'handler_uid' => $this->owner->id,
        ]);
        $this->assertDatabaseHas('guild_applications', [
            'id' => $otherApplicationId,
            'status' => GuildApplication::STATUS_INVALID,
        ]);

        $joinedLog = GuildLog::query()
            ->where('guild_id', $guildId)
            ->where('action', GuildLog::ACTION_MEMBER_JOINED)
            ->where('target_uid', $this->outsider->id)
            ->first();
        $this->assertNotNull($joinedLog);
        $this->assertSame('application', $joinedLog->context['source'] ?? null);
        $this->assertSame(
            $applicationId,
            (int) ($joinedLog->context['application_id'] ?? 0)
        );
        $this->assertDatabaseHas('guild_logs', [
            'guild_id' => $guildId,
            'action' => GuildLog::ACTION_APPLICATION_APPROVED,
            'target_uid' => $this->outsider->id,
        ]);

        Sanctum::actingAs($this->outsider);
        $this->getJson('/api/user/guilds/overview')
            ->assertOk()
            ->assertJsonPath('data.guild.id', $guildId);
        $this->getJson("/api/user/guilds/{$guildId}/schedule")
            ->assertOk()
            ->assertJsonPath('data.members.1.uid', (int) $this->outsider->id);
    }

    public function test_application_approval_rechecks_membership_capacity_and_expiry(): void
    {
        $guildId = $this->createGuild();

        Sanctum::actingAs($this->outsider);
        $applicationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');

        $otherGuild = Guild::query()->create([
            'name' => '外部公会',
            'description' => null,
            'owner_uid' => $this->vice->id,
            'status' => Guild::STATUS_ACTIVE,
        ]);
        GuildMember::query()->create([
            'guild_id' => $otherGuild->id,
            'uid' => $this->vice->id,
            'role' => GuildMember::ROLE_OWNER,
            'member_slot' => 1,
            'vice_slot' => null,
            'status' => GuildMember::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        GuildMember::query()->create([
            'guild_id' => $otherGuild->id,
            'uid' => $this->outsider->id,
            'role' => GuildMember::ROLE_MEMBER,
            'member_slot' => 2,
            'vice_slot' => null,
            'status' => GuildMember::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($this->owner);
        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$applicationId}/approve"
        )
            ->assertStatus(409)
            ->assertJsonPath('msg', '申请人已加入其他公会');

        GuildMember::query()
            ->where('guild_id', $otherGuild->id)
            ->where('uid', $this->outsider->id)
            ->delete();

        for ($index = 0; $index < 29; $index++) {
            $this->addMemberDirectly(
                $guildId,
                $this->createUser("full-app-{$index}@example.com", "满员成员{$index}"),
                $index + 2
            );
        }

        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$applicationId}/approve"
        )
            ->assertStatus(409)
            ->assertJsonPath('msg', '当前公会暂无可用名额');

        GuildApplication::query()->whereKey($applicationId)->update([
            'expires_at' => now()->subSecond(),
        ]);
        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$applicationId}/approve"
        )
            ->assertStatus(409)
            ->assertJsonPath('msg', '该申请已过期');
        $this->assertDatabaseHas('guild_applications', [
            'id' => $applicationId,
            'status' => GuildApplication::STATUS_EXPIRED,
        ]);
    }

    public function test_members_cannot_review_applications_but_vice_can_approve(): void
    {
        $guildId = $this->createGuild();
        $this->addMemberByInvitation($guildId, $this->member);
        $this->addMemberByInvitation($guildId, $this->vice);

        Sanctum::actingAs($this->owner);
        $this->putJson(
            "/api/user/guilds/{$guildId}/members/{$this->vice->id}/vice"
        )->assertOk();

        Sanctum::actingAs($this->outsider);
        $applicationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->member);
        $this->getJson("/api/user/guilds/{$guildId}/applications")
            ->assertForbidden();
        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$applicationId}/approve"
        )->assertForbidden();

        Sanctum::actingAs($this->vice);
        $this->getJson("/api/user/guilds/{$guildId}")
            ->assertOk()
            ->assertJsonPath(
                'data.permissions.can_review_applications',
                true
            );
        $this->getJson("/api/user/guilds/{$guildId}/applications")
            ->assertOk()
            ->assertJsonPath('data.count', 1);
        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$applicationId}/approve"
        )->assertOk();
        $this->assertDatabaseHas('guild_members', [
            'guild_id' => $guildId,
            'uid' => $this->outsider->id,
            'status' => GuildMember::STATUS_ACTIVE,
        ]);
    }

    public function test_application_and_invitation_are_mutually_exclusive_and_release_each_other(): void
    {
        $guildId = $this->createGuild();

        Sanctum::actingAs($this->outsider);
        $applicationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->outsider->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath(
                'msg',
                '该用户已提交加入申请，请直接在加入申请中审核'
            );
        $this->getJson(
            "/api/user/guilds/{$guildId}/invitation-candidates?uid={$this->outsider->id}"
        )
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath(
                'data.reason',
                '该用户已提交加入申请，请直接在加入申请中审核'
            );

        // 另一个公会的邀请被接受后，该用户的待处理申请立即失效。
        Sanctum::actingAs($this->vice);
        $otherGuildId = (int) $this->postJson('/api/user/guilds', [
            'name' => '邀请公会',
        ])->assertOk()->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/user/guild-applications/{$applicationId}")
            ->assertStatus(403);

        Sanctum::actingAs($this->outsider);
        $this->deleteJson("/api/user/guild-applications/{$applicationId}")
            ->assertOk();

        Sanctum::actingAs($this->outsider);
        $otherApplicationId = (int) $this->postJson(
            "/api/user/guilds/{$otherGuildId}/applications"
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->owner);
        $invitationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/invitations",
            ['invitee_uid' => $this->outsider->id]
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->outsider);
        $this->postJson("/api/user/guild-invitations/{$invitationId}/accept")
            ->assertOk();
        $this->assertDatabaseHas('guild_applications', [
            'id' => $otherApplicationId,
            'status' => GuildApplication::STATUS_INVALID,
        ]);
    }

    public function test_pending_applications_reserve_slots_for_invitations(): void
    {
        $guildId = $this->createGuild();

        for ($index = 0; $index < 27; $index++) {
            $this->addMemberDirectly(
                $guildId,
                $this->createUser("reserve-{$index}@example.com", "预留成员{$index}"),
                $index + 2
            );
        }

        Sanctum::actingAs($this->outsider);
        $this->postJson("/api/user/guilds/{$guildId}/applications")
            ->assertOk();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/user/guilds/overview')
            ->assertJsonPath('data.guild.member_count', 28)
            ->assertJsonPath('data.guild.pending_application_count', 1)
            ->assertJsonPath('data.guild.remaining_slots', 1);

        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->member->id,
        ])->assertOk();

        $this->getJson('/api/user/guilds/overview')
            ->assertJsonPath('data.guild.remaining_slots', 0)
            ->assertJsonPath('data.guild.capacity_status', 'reserved');

        $this->postJson("/api/user/guilds/{$guildId}/invitations", [
            'invitee_uid' => $this->vice->id,
        ])->assertStatus(409);
    }

    public function test_application_created_before_leaving_cannot_be_approved_and_rejoin_creates_new_rows(): void
    {
        $guildId = $this->createGuild();

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/user/guilds/{$guildId}/schedule")->assertOk();

        $this->addMemberByInvitation($guildId, $this->member);

        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/user/guilds/{$guildId}/members/me")
            ->assertOk();

        $oldApplication = GuildApplication::query()->create([
            'guild_id' => $guildId,
            'applicant_uid' => $this->member->id,
            'status' => GuildApplication::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);
        $oldApplication->created_at = now()->subMinute();
        $oldApplication->save();

        Sanctum::actingAs($this->owner);
        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$oldApplication->id}/approve"
        )
            ->assertStatus(409)
            ->assertJsonPath('msg', '该申请创建于成员离开之前，无法加入');

        $oldApplication->status = GuildApplication::STATUS_INVALID;
        $oldApplication->handled_at = now();
        $oldApplication->save();

        Carbon::setTestNow(now()->addSecond());
        Sanctum::actingAs($this->member);
        $newApplicationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->postJson(
            "/api/user/guilds/{$guildId}/applications/{$newApplicationId}/approve"
        )->assertOk();

        $this->assertSame(
            2,
            GuildMember::query()
                ->where('guild_id', $guildId)
                ->where('uid', $this->member->id)
                ->count()
        );
        $this->assertSame(
            1,
            GuildMonthMember::query()
                ->where('guild_id', $guildId)
                ->where('uid', $this->member->id)
                ->count()
        );
        $this->assertDatabaseHas('guild_month_members', [
            'guild_id' => $guildId,
            'uid' => $this->member->id,
            'team_1' => null,
            'team_2' => null,
            'team_3' => null,
        ]);
    }

    public function test_dissolve_invalidates_pending_applications(): void
    {
        $guildId = $this->createGuild();

        Sanctum::actingAs($this->outsider);
        $applicationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/applications"
        )->assertOk()->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/user/guilds/{$guildId}")->assertOk();

        $this->assertDatabaseHas('guild_applications', [
            'id' => $applicationId,
            'status' => GuildApplication::STATUS_INVALID,
        ]);
        $this->getJson("/api/user/guilds/{$guildId}")->assertNotFound();
    }

    public function test_database_rejects_duplicate_pending_application(): void
    {
        $guildId = $this->createGuild();

        GuildApplication::query()->create([
            'guild_id' => $guildId,
            'applicant_uid' => $this->outsider->id,
            'status' => GuildApplication::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        GuildApplication::query()->create([
            'guild_id' => $guildId,
            'applicant_uid' => $this->outsider->id,
            'status' => GuildApplication::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function createGuild(): int
    {
        Sanctum::actingAs($this->owner);

        return (int) $this->postJson('/api/user/guilds', [
            'name' => '测试公会',
            'description' => '测试简介',
        ])
            ->assertOk()
            ->json('data.id');
    }

    private function addMemberByInvitation(int $guildId, User $user): void
    {
        Sanctum::actingAs($this->owner);
        Carbon::setTestNow(now()->addSecond());
        $invitationId = (int) $this->postJson(
            "/api/user/guilds/{$guildId}/invitations",
            ['invitee_uid' => $user->id]
        )
            ->assertOk()
            ->json('data.id');

        Sanctum::actingAs($user);
        $this->postJson("/api/user/guild-invitations/{$invitationId}/accept")
            ->assertOk();

        Sanctum::actingAs($this->owner);
    }

    private function addMemberDirectly(
        int $guildId,
        User $user,
        int $slot
    ): void {
        GuildMember::query()->create([
            'guild_id' => $guildId,
            'uid' => $user->id,
            'role' => GuildMember::ROLE_MEMBER,
            'member_slot' => $slot,
            'vice_slot' => null,
            'status' => GuildMember::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
    }

    private function createUser(
        string $email,
        string $nickname,
        int $status = 1
    ): User {
        return User::query()->create([
            'ip' => '10.0.0.'.(User::query()->count() + 1),
            'nickname' => $nickname,
            'email' => $email,
            'password' => 'secret',
            'avatar' => 105811,
            'status' => $status,
            'is_subscribe' => 1,
        ]);
    }

    private function createTeam(int $stage, int $boss): Team
    {
        return Team::query()->create([
            'stage' => $stage,
            'source_team_id' => null,
            'uid' => 0,
            'boss' => $boss,
            'damage' => 1000,
            'team_key' => "{$stage}-{$boss}-".Team::query()->count(),
            'status' => 1,
            'mode' => 1,
            'atk_value' => 0,
            'remark' => '',
        ]);
    }

    private function createGameRole(
        int $id,
        int $roleId,
        int $searchAreaWidth
    ): GameRole {
        return GameRole::query()->create([
            'id' => $id,
            'role_id' => $roleId,
            'role_id_1' => $roleId,
            'role_id_3' => $roleId,
            'role_id_6' => $roleId,
            'element' => 1,
            'atk_type' => 1,
            'is_clan_battle' => true,
            'name' => '角色'.$id,
            'nickname' => '角色'.$id,
            'search_area_width' => $searchAreaWidth,
            'position' => 1,
            'status' => true,
        ]);
    }

    private function createTestTables(): void
    {
        foreach ([
            'guild_logs',
            'guild_month_members',
            'guild_months',
            'guild_applications',
            'guild_invitations',
            'guild_members',
            'guilds',
            'game_roles',
            'team_links',
            'team_roles',
            'teams',
            'personal_access_tokens',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('country')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('avatar')->nullable();
            $table->timestamp('nickname_updated_at')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('is_subscribe')->default(1);
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('stage');
            $table->unsignedInteger('source_team_id')->nullable();
            $table->unsignedInteger('uid')->default(0);
            $table->unsignedTinyInteger('boss');
            $table->unsignedInteger('damage');
            $table->string('team_key', 100);
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedTinyInteger('mode');
            $table->tinyInteger('atk_value');
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('import_timestamp')->nullable();
            $table->timestamps();
        });

        Schema::create('game_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('role_id')->unique();
            $table->unsignedBigInteger('role_id_1');
            $table->unsignedBigInteger('role_id_3');
            $table->unsignedBigInteger('role_id_6')->nullable();
            $table->unsignedTinyInteger('element');
            $table->unsignedTinyInteger('obtain')->nullable();
            $table->unsignedTinyInteger('probability')->nullable();
            $table->date('up_time')->nullable();
            $table->tinyInteger('atk_type');
            $table->boolean('is_clan_battle')->default(false);
            $table->string('name')->default('');
            $table->string('nickname')->default('');
            $table->unsignedInteger('search_area_width');
            $table->unsignedTinyInteger('position');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('team_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedTinyInteger('status')->default(1);
        });

        Schema::create('team_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('url')->nullable();
            $table->string('author', 32)->nullable();
            $table->unsignedTinyInteger('difficulty')->default(1);
            $table->unsignedInteger('damage')->default(0);
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
        });

        Schema::create('guilds', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 32);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('owner_uid');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedBigInteger('active_owner_uid')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 THEN owner_uid ELSE NULL END');
            $table->timestamps();
            $table->unique('active_owner_uid');
        });

        Schema::create('guild_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('uid');
            $table->unsignedTinyInteger('role')->default(3);
            $table->unsignedTinyInteger('member_slot');
            $table->unsignedTinyInteger('vice_slot')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('joined_at', 6);
            $table->timestamp('left_at', 6)->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('active_uid')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 THEN uid ELSE NULL END');
            $table->unsignedTinyInteger('active_member_slot')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 THEN member_slot ELSE NULL END');
            $table->unsignedTinyInteger('active_owner_slot')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 AND role = 1 THEN 1 ELSE NULL END');
            $table->unsignedTinyInteger('active_vice_slot')
                ->nullable()
                ->storedAs('CASE WHEN status = 1 AND role = 2 THEN vice_slot ELSE NULL END');
            $table->unique('active_uid');
            $table->unique(['guild_id', 'active_member_slot']);
            $table->unique(['guild_id', 'active_owner_slot']);
            $table->unique(['guild_id', 'active_vice_slot']);
        });

        Schema::create('guild_invitations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('inviter_uid');
            $table->unsignedBigInteger('invitee_uid');
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('expires_at', 6);
            $table->timestamp('handled_at', 6)->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('pending_uid')
                ->nullable()
                ->storedAs('CASE WHEN status = 0 THEN invitee_uid ELSE NULL END');
            $table->unique(['guild_id', 'pending_uid']);
        });

        Schema::create('guild_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('applicant_uid');
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('expires_at', 6);
            $table->timestamp('handled_at', 6)->nullable();
            $table->unsignedBigInteger('handler_uid')->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('pending_applicant')
                ->nullable()
                ->storedAs('CASE WHEN status = 0 THEN applicant_uid ELSE NULL END');
            $table->unique(['guild_id', 'pending_applicant']);
        });

        Schema::create('guild_months', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->char('year_month', 7);
            $table->timestamps();
            $table->unique(['guild_id', 'year_month']);
        });

        Schema::create('guild_month_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('month_id');
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('uid');
            $table->string('nickname', 32)->nullable();
            $table->unsignedTinyInteger('role')->default(3);
            $table->unsignedTinyInteger('member_slot');
            $table->unsignedBigInteger('team_1')->nullable();
            $table->unsignedBigInteger('team_1_return')->nullable();
            $table->unsignedBigInteger('team_2')->nullable();
            $table->unsignedBigInteger('team_2_return')->nullable();
            $table->unsignedBigInteger('team_3')->nullable();
            $table->unsignedBigInteger('team_3_return')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['month_id', 'uid']);
        });

        Schema::create('guild_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('guild_id');
            $table->unsignedBigInteger('actor_uid');
            $table->unsignedBigInteger('target_uid')->nullable();
            $table->string('action', 40);
            $table->json('context')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
        });
    }
}
