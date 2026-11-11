<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GameRole;
use App\Models\GameRoleStatistics;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamLink;
use Database\Seeders\TeamPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();

        $adminRole = Role::create([
            'name' => 'Super Admin',
            'code' => 'super_admin',
            'description' => '',
            'sort' => 1,
            'status' => 1,
        ]);

        $admin = Admin::create([
            'username' => 'team-test-admin',
            'password' => 'secret',
            'nickname' => 'Team Test',
            'status' => 1,
        ]);
        $admin->roles()->attach($adminRole->id);
        Sanctum::actingAs($admin);

        $this->createGameRole(101, '物理角色', 1, 100);
        $this->createGameRole(102, '魔法角色', -1, 500);
        $this->createGameRole(103, '辅助角色', 0, 300);
        $this->createGameRole(104, '前排角色', 0, 200);
        $this->createGameRole(105, '后排角色', 1, 50);
    }

    public function test_five_bosses_create_five_teams_and_twenty_five_role_links_with_fixed_damage(): void
    {
        $payload = $this->batchPayload(stage: 2, bosses: [1, 2, 3, 4, 5], damage: 99999, roles: [101, 102, 103, 104, 105]);

        $this->postJson('/api/admin/game/teams', $payload)
            ->assertOk()
            ->assertJsonPath('data.count', 5);

        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 3,
            bosses: [1, 5],
            damage: null,
            roles: [101, 102, 103, 104, 105],
            author: 'C阶段作者',
            link: 'https://example.com/c-stage',
        ))->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertDatabaseCount('teams', 7);
        $this->assertDatabaseCount('team_roles', 35);
        $this->assertDatabaseCount('team_links', 7);
        $this->assertDatabaseHas('teams', ['boss' => 1, 'damage' => 4200, 'team_key' => '102,103,104,101,105']);
        $this->assertDatabaseHas('teams', ['boss' => 5, 'damage' => 6000]);
        $this->assertDatabaseHas('teams', ['stage' => 3, 'boss' => 1, 'damage' => 7000]);
        $this->assertDatabaseHas('teams', ['stage' => 3, 'boss' => 5, 'damage' => 12000]);
        $this->assertDatabaseHas('team_links', ['difficulty' => 1, 'damage' => 4200, 'status' => 1]);
        $this->assertRoleUseTimes([
            101 => 7,
            102 => 7,
            103 => 7,
            104 => 7,
            105 => 7,
        ]);

        $list = $this->getJson('/api/admin/game/teams?status=1&page=1&pageSize=2');
        $list->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.roles.0.nickname', '魔法角色');

        GameRoleStatistics::updateOrCreate(['game_role_id' => 101], ['use_times' => 5]);
        GameRoleStatistics::updateOrCreate(['game_role_id' => 102], ['use_times' => 50]);
        $options = $this->getJson('/api/admin/game/roles/options');
        $options->assertOk()
            ->assertJsonPath('data.0.id', 102)
            ->assertJsonPath('data.0.search_area_width', 500)
            ->assertJsonPath('data.0.use_times', 50);
    }

    public function test_d_stage_lineup_options_match_leftmost_prefix_and_dedupe_team_key(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00'));
        $this->createGameRole(201, 'ABC前排', 1, 500);
        $this->createGameRole(202, '中间角色', 1, 300);
        $this->createGameRole(203, 'Zed角色', 1, 100);

        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 4, bosses: [1], damage: 5000, roles: [201, 202, 203],
        ))->assertOk();
        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 4, bosses: [2], damage: 5000, roles: [201, 202, 203],
        ))->assertOk();
        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 2, bosses: [1], damage: null, roles: [201, 202, 203],
        ))->assertOk();
        $latestDStageTeam = Team::query()->where('stage', 4)->orderByDesc('id')->firstOrFail();

        $this->getJson('/api/admin/game/teams/d-stage-lineups/options?prefix=AB')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.team_id', $latestDStageTeam->id)
            ->assertJsonPath('data.0.roles.0.nickname', 'ABC前排')
            ->assertJsonPath('data.0.roles.1.nickname', '中间角色')
            ->assertJsonPath('data.0.roles.2.nickname', 'Zed角色');

        $this->getJson('/api/admin/game/teams/d-stage-lineups/options?prefix=Zed')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Team::query()->where('stage', 4)->update(['created_at' => Carbon::parse('2026-09-30 23:59:59')]);
        $this->getJson('/api/admin/game/teams/d-stage-lineups/options?prefix=AB')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Team::query()->where('stage', 4)->update(['status' => 0]);
        $this->getJson('/api/admin/game/teams/d-stage-lineups/options?prefix=AB')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        Carbon::setTestNow();
    }

    public function test_list_includes_zero_atk_in_physical_and_magic_and_sorts_default_without_pagination(): void
    {
        $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 2, bosses: [2], damage: 5000, roles: [103, 104]))->assertOk();
        $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 4, bosses: [1], damage: 5000, roles: [101, 102]))->assertOk();
        $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 4, bosses: [2], damage: 5000, roles: [101, 102]))->assertOk();

        $this->getJson('/api/admin/game/teams?status=1&atk_type=1&pageSize=1')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.stage', 4)
            ->assertJsonPath('data.0.boss', 1)
            ->assertJsonPath('data.1.boss', 2);
        $this->getJson('/api/admin/game/teams?status=1&atk_type=-1')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_public_team_list_sorts_by_damage_desc_then_id_asc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));

        try {
            $firstTeam = Team::query()->create([
                'stage' => 4,
                'uid' => 0,
                'boss' => 1,
                'damage' => 9000,
                'team_key' => '101,102',
                'status' => 1,
                'mode' => 1,
                'atk_value' => 0,
            ]);
            $firstTeam->teamRoles()->createMany([
                ['role_id' => 101, 'status' => 1],
                ['role_id' => 102, 'status' => 1],
            ]);

            $highestDamageTeam = Team::query()->create([
                'stage' => 4,
                'uid' => 0,
                'boss' => 1,
                'damage' => 12000,
                'team_key' => '103,104',
                'status' => 1,
                'mode' => 1,
                'atk_value' => 0,
            ]);
            $highestDamageTeam->teamRoles()->createMany([
                ['role_id' => 103, 'status' => 1],
                ['role_id' => 104, 'status' => 1],
            ]);

            $sameDamageTeam = Team::query()->create([
                'stage' => 4,
                'uid' => 0,
                'boss' => 2,
                'damage' => 9000,
                'team_key' => '104,105',
                'status' => 1,
                'mode' => 1,
                'atk_value' => 0,
            ]);
            $sameDamageTeam->teamRoles()->createMany([
                ['role_id' => 104, 'status' => 1],
                ['role_id' => 105, 'status' => 1],
            ]);

            $this->getJson('/api/game/teams')
                ->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonPath('data.0.id', $highestDamageTeam->id)
                ->assertJsonPath('data.0.damage', 12000)
                ->assertJsonPath('data.1.id', $firstTeam->id)
                ->assertJsonPath('data.1.damage', 9000)
                ->assertJsonPath('data.2.id', $sameDamageTeam->id)
                ->assertJsonPath('data.2.damage', 9000);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_duplicate_admin_signature_reuses_team_and_keeps_each_source_link(): void
    {
        $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 4, bosses: [1], damage: 5000))
            ->assertOk();
        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 4,
            bosses: [1],
            damage: 6000,
            roles: [102, 101],
            author: '第二作者',
            link: 'https://example.com/second',
        ))->assertOk();

        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('team_roles', 2);
        $this->assertDatabaseCount('team_links', 2);
        $this->assertDatabaseHas('teams', ['stage' => 4, 'boss' => 1, 'uid' => 0, 'damage' => 6000, 'team_key' => '102,101']);

        $this->getJson('/api/admin/game/teams?status=1&author=第二作者&difficulty=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author', '第二作者')
            ->assertJsonPath('data.0.damage', 6000)
            ->assertJsonPath('data.0.team_damage', 6000)
            ->assertJsonPath('data.0.boss', 1);
    }

    public function test_matching_signature_in_same_batch_reuses_team_and_uses_highest_damage(): void
    {
        $payload = $this->batchPayload(stage: 4, bosses: [1], damage: 5000);
        $payload['records'][] = [
            'stage' => 4,
            'boss' => [1],
            'difficulty' => 1,
            'damage' => 6000,
            'roles' => [101, 102],
        ];

        $this->postJson('/api/admin/game/teams', $payload)
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('team_links', 2);
        $this->assertDatabaseCount('team_signature_locks', 2);
        $this->assertDatabaseHas('teams', ['uid' => 0, 'stage' => 4, 'boss' => 1, 'damage' => 6000]);
        $this->assertSame(
            (int) Team::query()->value('id'),
            (int) TeamLink::query()->value('team_id'),
        );
    }

    public function test_difficulties_one_through_four_use_mode_one_and_five_uses_mode_two(): void
    {
        foreach ([1, 2, 3, 4] as $difficulty) {
            $payload = $this->batchPayload(
                stage: 4,
                bosses: [1],
                damage: 5000 + $difficulty * 100,
                author: "难度{$difficulty}作者",
                link: "https://example.com/difficulty-{$difficulty}",
            );
            $payload['records'][0]['difficulty'] = $difficulty;
            $this->postJson('/api/admin/game/teams', $payload)->assertOk();
        }

        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('team_links', 4);
        $this->assertDatabaseHas('teams', ['mode' => 1, 'damage' => 5400]);

        $manual = $this->batchPayload(
            stage: 4,
            bosses: [1],
            damage: 8000,
            author: '难度5作者',
            link: 'https://example.com/difficulty-5',
        );
        $manual['records'][0]['difficulty'] = 5;
        $this->postJson('/api/admin/game/teams', $manual)->assertOk();

        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseHas('teams', ['mode' => 2, 'damage' => 8000]);
        $this->assertDatabaseHas('team_signature_locks', ['mode' => 1]);
        $this->assertDatabaseHas('team_signature_locks', ['mode' => 2]);

        $this->getJson('/api/admin/game/teams?mode=1')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.mode', 1);
        $this->getJson('/api/admin/game/teams?mode=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mode', 2);
    }

    public function test_same_signature_from_previous_month_creates_a_new_team_and_lock(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-10-31 23:59:59'));
            $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 4, bosses: [1], damage: 5000))
                ->assertOk();

            Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00'));
            $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 4, bosses: [1], damage: 6000))
                ->assertOk();

            $this->assertDatabaseCount('teams', 2);
            $this->assertDatabaseCount('team_signature_locks', 3);
            $this->assertDatabaseHas('team_signature_locks', ['year_month' => '2026-10']);
            $this->assertDatabaseHas('team_signature_locks', ['year_month' => '2026-11']);
            $this->assertDatabaseHas('team_signature_locks', ['year_month' => '0000-00', 'mode' => 0]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_hidden_matching_signature_is_reused_and_restored(): void
    {
        $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 4, bosses: [1], damage: 5000))
            ->assertOk();
        $this->assertRoleUseTimes([
            101 => 1,
            102 => 1,
        ]);
        $hiddenTeam = Team::query()->firstOrFail();
        $hiddenLink = TeamLink::query()->firstOrFail();
        $this->deleteJson("/api/admin/game/teams/{$hiddenTeam->id}")
            ->assertOk();

        $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 4, bosses: [1], damage: 6000))
            ->assertOk();

        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('team_roles', 2);
        $this->assertDatabaseCount('team_links', 2);
        $this->assertRoleUseTimes([
            101 => 1,
            102 => 1,
        ]);
        $this->assertDatabaseHas('teams', ['id' => $hiddenTeam->id, 'status' => 1, 'damage' => 6000]);
        $this->assertDatabaseHas('team_links', ['id' => $hiddenLink->id, 'status' => 0]);
        $this->assertDatabaseHas('team_links', ['team_id' => $hiddenTeam->id, 'status' => 1, 'damage' => 6000]);
        $this->getJson('/api/admin/game/teams?status=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_link_edit_hide_restore_and_status_filter_are_scoped_to_one_link(): void
    {
        $this->postJson('/api/admin/game/teams', $this->batchPayload(stage: 4, bosses: [1], damage: 5000))
            ->assertOk();
        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 4,
            bosses: [1],
            damage: 6000,
            author: '另一作者',
            link: 'https://example.com/other',
        ))->assertOk();

        $team = Team::query()->firstOrFail();
        $link = TeamLink::query()->where('author', '测试作者')->firstOrFail();
        $otherLink = TeamLink::query()->where('author', '另一作者')->firstOrFail();

        $this->putJson("/api/admin/game/teams/{$team->id}/links/{$link->id}", [
            'author' => '编辑作者',
            'link' => 'https://example.com/edited',
            'difficulty' => 2,
            'damage' => 5500,
        ])->assertOk()
            ->assertJsonPath('data.link_id', $link->id)
            ->assertJsonPath('data.author', '编辑作者')
            ->assertJsonPath('data.difficulty', 2)
            ->assertJsonPath('data.damage', 5500);

        $this->assertDatabaseHas('team_links', ['id' => $otherLink->id, 'author' => '另一作者', 'damage' => 6000]);
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'stage' => 4, 'boss' => 1, 'damage' => 6000]);

        $this->deleteJson("/api/admin/game/teams/{$team->id}/links/{$link->id}")
            ->assertOk();
        $this->getJson('/api/admin/game/teams?status=0')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/game/teams?status=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson("/api/admin/game/teams/{$team->id}/links/{$link->id}/status", ['status' => 1])
            ->assertOk();
        $this->getJson('/api/admin/game/teams?status=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_invalid_game_role_is_rejected_without_creating_partial_rows(): void
    {
        $payload = $this->batchPayload();
        $payload['records'][0]['roles'] = [101, 999];

        $this->postJson('/api/admin/game/teams', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['records.0.roles.1']);

        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('team_roles', 0);
        $this->assertDatabaseCount('team_links', 0);
    }

    public function test_boss_can_repeat_across_records_but_not_within_one_record(): void
    {
        $payload = $this->batchPayload(stage: 4, bosses: [1], damage: 5000);
        $payload['records'][] = [
            'stage' => 4,
            'boss' => [1],
            'difficulty' => 1,
            'damage' => 6000,
            'roles' => [103, 104],
        ];

        $this->postJson('/api/admin/game/teams', $payload)
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseCount('team_links', 2);

        $duplicateBossPayload = $this->batchPayload(stage: 4, bosses: [1, 1], damage: 7000);
        $this->postJson('/api/admin/game/teams', $duplicateBossPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['records.0.boss']);

        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseCount('team_links', 2);
    }

    public function test_role_usage_counts_lineup_once_regardless_of_role_order_and_mode(): void
    {
        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 2,
            bosses: [1],
            damage: null,
            roles: [101, 102, 103, 104, 105],
        ))->assertOk();

        $manualPayload = $this->batchPayload(
            stage: 2,
            bosses: [1],
            damage: null,
            roles: [105, 104, 103, 102, 101],
            author: '手动作者',
            link: 'https://example.com/manual',
        );
        $manualPayload['records'][0]['difficulty'] = 5;
        $this->postJson('/api/admin/game/teams', $manualPayload)->assertOk();

        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseCount('team_links', 2);
        $this->assertDatabaseCount('team_signature_locks', 3);
        $this->assertDatabaseHas('team_signature_locks', [
            'year_month' => '0000-00',
            'mode' => 0,
            'stage' => 2,
            'boss' => 1,
            'team_key' => '102,103,104,101,105',
        ]);
        $this->assertRoleUseTimes([
            101 => 1,
            102 => 1,
            103 => 1,
            104 => 1,
            105 => 1,
        ]);
    }

    public function test_same_batch_duplicate_role_usage_key_counts_once_without_skipping_links(): void
    {
        $payload = $this->batchPayload(
            stage: 4,
            bosses: [1],
            damage: 5000,
            roles: [101, 102, 103, 104, 105],
        );
        $manualPayload = $this->batchPayload(
            stage: 4,
            bosses: [1],
            damage: 6000,
            roles: [105, 104, 103, 102, 101],
            author: '批次手动作者',
            link: 'https://example.com/batch-manual',
        );
        $manualPayload['records'][0]['difficulty'] = 5;
        $payload['records'][] = $manualPayload['records'][0];

        $this->postJson('/api/admin/game/teams', $payload)
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseCount('team_links', 2);
        $this->assertRoleUseTimes([
            101 => 1,
            102 => 1,
            103 => 1,
            104 => 1,
            105 => 1,
        ]);
    }

    public function test_role_usage_counts_each_stage_and_boss_independently(): void
    {
        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 2,
            bosses: [1, 2],
            damage: null,
            roles: [101, 102],
        ))->assertOk();
        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 3,
            bosses: [1],
            damage: null,
            roles: [101, 103],
            author: 'C阶段作者',
            link: 'https://example.com/c-stage-heat',
        ))->assertOk();

        $this->assertRoleUseTimes([
            101 => 3,
            102 => 2,
            103 => 1,
        ]);
    }

    public function test_role_usage_supports_lineups_with_one_to_five_roles(): void
    {
        foreach (range(1, 5) as $size) {
            $this->postJson('/api/admin/game/teams', $this->batchPayload(
                stage: 4,
                bosses: [$size],
                damage: 5000 + $size,
                roles: array_slice([101, 102, 103, 104, 105], 0, $size),
                author: "角色数{$size}作者",
                link: "https://example.com/lineup-size-{$size}",
            ))->assertOk();
        }

        $this->assertRoleUseTimes([
            101 => 5,
            102 => 4,
            103 => 3,
            104 => 2,
            105 => 1,
        ]);
    }

    public function test_existing_role_usage_lock_prevents_increment(): void
    {
        DB::table('team_signature_locks')->insert([
            'year_month' => '0000-00',
            'team_key' => '102,101',
            'mode' => 0,
            'stage' => 2,
            'boss' => 1,
        ]);

        $this->postJson('/api/admin/game/teams', $this->batchPayload(
            stage: 2,
            bosses: [1],
            damage: null,
            roles: [101, 102],
        ))->assertOk();

        $this->assertDatabaseCount('game_role_statistics', 0);
    }

    public function test_role_usage_rolls_back_when_statistics_update_fails(): void
    {
        DB::statement(
            "CREATE TRIGGER fail_game_role_statistics_update
            BEFORE UPDATE ON game_role_statistics
            BEGIN
                SELECT RAISE(ABORT, 'forced role statistics failure');
            END"
        );

        try {
            $this->withoutExceptionHandling()->postJson(
                '/api/admin/game/teams',
                $this->batchPayload(
                    stage: 2,
                    bosses: [1],
                    damage: null,
                    roles: [101, 102],
                ),
            );
            $this->fail('统计更新失败时应抛出数据库异常。');
        } catch (QueryException) {
        }

        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('team_roles', 0);
        $this->assertDatabaseCount('team_links', 0);
        $this->assertDatabaseCount('team_signature_locks', 0);
        $this->assertDatabaseCount('game_role_statistics', 0);
    }

    public function test_team_permission_seeder_lists_actions_and_gives_super_admin_all_permissions(): void
    {
        $existingPermission = Permission::create([
            'name' => 'system:custom:existing',
            'display_name' => '既有权限',
            'http_method' => 'GET',
            'http_path' => 'api/admin/custom',
        ]);
        $otherRole = Role::create([
            'name' => 'Data Admin',
            'code' => 'data_admin',
            'description' => '',
            'sort' => 2,
            'status' => 1,
        ]);
        $otherRole->permissions()->attach($existingPermission->id);

        (new TeamPermissionSeeder())->run();

        $superAdmin = Role::query()->where('code', 'super_admin')->firstOrFail();
        $allPermissionIds = Permission::query()->orderBy('id')->pluck('id')->all();
        $superAdminPermissionIds = $superAdmin->permissions()
            ->orderBy('permissions.id')
            ->pluck('permissions.id')
            ->all();
        $this->assertSame($allPermissionIds, $superAdminPermissionIds);
        $this->assertDatabaseHas('role_permission', [
            'role_id' => $otherRole->id,
            'permission_id' => $existingPermission->id,
        ]);

        $createPermission = Permission::query()->where('name', 'system:team:create')->firstOrFail();
        $otherRole->permissions()->attach($createPermission->id);
        (new TeamPermissionSeeder())->run();
        $lineupPermission = Permission::query()->where('name', 'system:team:d-stage-lineup-options')->firstOrFail();
        $this->assertDatabaseHas('role_permission', [
            'role_id' => $otherRole->id,
            'permission_id' => $lineupPermission->id,
        ]);

        $this->getJson('/api/admin/permissions')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'system:team-link:update',
                'display_name' => '修改来源作业',
            ])
            ->assertJsonFragment([
                'name' => 'system:team-link:delete',
                'display_name' => '隐藏来源作业',
            ])
            ->assertJsonFragment([
                'name' => 'system:team-link:status',
                'display_name' => '恢复来源作业',
            ])
            ->assertJsonFragment([
                'name' => 'system:team:d-stage-lineup-options',
                'display_name' => 'D阶段阵容选项',
            ]);

        $codes = $this->getJson('/api/admin/codes')->assertOk()->json('data');
        $this->assertContains('system:team-link:update', $codes);
        $this->assertContains('system:team-link:delete', $codes);
        $this->assertContains('system:team-link:status', $codes);
        $this->assertContains('system:team:d-stage-lineup-options', $codes);
        $this->assertContains('system:custom:existing', $codes);
    }

    private function batchPayload(
        int $stage = 2,
        array $bosses = [1, 2],
        ?int $damage = 12000,
        array $roles = [101, 102],
        string $author = '测试作者',
        string $link = 'https://example.com/team',
    ): array {
        return [
            'author' => $author,
            'link' => $link,
            'records' => [[
                'stage' => $stage,
                'boss' => $bosses,
                'difficulty' => 1,
                'damage' => $damage,
                'roles' => $roles,
            ]],
        ];
    }

    private function assertRoleUseTimes(array $expected): void
    {
        foreach ($expected as $roleId => $useTimes) {
            $this->assertDatabaseHas('game_role_statistics', [
                'game_role_id' => $roleId,
                'use_times' => $useTimes,
            ]);
        }
    }

    private function createTestTables(): void
    {
        foreach (['game_role_statistics', 'team_signature_locks', 'team_roles', 'team_links', 'teams', 'game_roles', 'role_permission', 'permissions', 'admin_role', 'admins', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('description')->default('');
            $table->integer('sort')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->unsignedBigInteger('menu_id')->nullable();
            $table->string('http_method')->default('GET');
            $table->string('http_path');
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('nickname')->default('');
            $table->string('avatar')->default('');
            $table->string('email')->default('');
            $table->string('mobile')->default('');
            $table->tinyInteger('status')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->default('');
            $table->timestamps();
        });

        Schema::create('admin_role', function (Blueprint $table): void {
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['admin_id', 'role_id']);
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
            $table->string('name');
            $table->string('nickname');
            $table->unsignedInteger('search_area_width');
            $table->unsignedTinyInteger('position');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('game_role_statistics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('game_role_id')->unique();
            $table->unsignedBigInteger('use_times')->default(0);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->increments('id');
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
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('team_roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('role_id');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unique(['team_id', 'role_id']);
        });

        Schema::create('team_links', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('team_id');
            $table->string('url', 1000);
            $table->string('author', 32);
            $table->unsignedTinyInteger('difficulty');
            $table->unsignedInteger('damage');
            $table->unsignedTinyInteger('status')->default(1);
            $table->string('note', 255)->nullable();
        });

        Schema::create('team_signature_locks', function (Blueprint $table): void {
            $table->id();
            $table->char('year_month', 7);
            $table->string('team_key', 100);
            $table->unsignedTinyInteger('mode');
            $table->unsignedTinyInteger('stage');
            $table->unsignedTinyInteger('boss');
            $table->unique(['year_month', 'team_key', 'mode', 'stage', 'boss']);
        });
    }

    private function createGameRole(int $id, string $nickname, int $atkType, int $searchAreaWidth): void
    {
        GameRole::create([
            'id' => $id,
            'role_id' => $id * 100 + 1,
            'role_id_1' => $id * 100 + 11,
            'role_id_3' => $id * 100 + 31,
            'role_id_6' => null,
            'element' => 1,
            'atk_type' => $atkType,
            'is_clan_battle' => true,
            'name' => $nickname,
            'nickname' => $nickname,
            'search_area_width' => $searchAreaWidth,
            'position' => 1,
            'obtain' => 1,
            'probability' => 1,
            'up_time' => null,
            'status' => true,
        ]);
    }
}
