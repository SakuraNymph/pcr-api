<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GameRole;
use App\Models\Team;
use App\Models\TeamLink;
use App\Models\TeamRole;
use App\Models\User;
use App\Services\SystemTripleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTripleApiTest extends TestCase
{
    private const URL = '/api/user/game/teams/triple/user';

    private User $user;

    private User $guest;

    private User $otherUser;

    private Admin $admin;

    private int $teamSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $this->createTestTables();

        $this->user = User::create([
            'ip' => '127.0.0.1',
            'nickname' => '正式用户',
            'email' => 'registered@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $this->guest = User::create([
            'ip' => '127.0.0.2',
            'nickname' => '游客',
            'email' => 'guest@example.com',
            'password' => 'secret',
            'status' => 0,
        ]);
        $this->otherUser = User::create([
            'ip' => '127.0.0.3',
            'nickname' => '其他用户',
            'email' => 'other@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $this->admin = Admin::create([
            'username' => 'admin',
            'password' => 'secret',
            'nickname' => '管理员',
            'status' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Auth::forgetGuards();
        DB::disableQueryLog();

        parent::tearDown();
    }

    public function test_guest_and_registered_users_can_access_but_admin_and_unauthenticated_cannot(): void
    {
        $registeredTeams = [
            $this->createTeam(1, 100, [1 => 1, 2 => 1, 3 => 1], $this->user->id),
            $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id),
            $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id),
        ];
        $guestTeams = [
            $this->createTeam(1, 100, [10 => 1, 11 => 1, 12 => 1], $this->guest->id),
            $this->createTeam(2, 100, [13 => 1, 14 => 1, 15 => 1], $this->guest->id),
            $this->createTeam(3, 100, [16 => 1, 17 => 1, 18 => 1], $this->guest->id),
        ];

        Auth::forgetGuards();
        $this->postJson(self::URL)->assertUnauthorized();

        Sanctum::actingAs($this->guest);
        $guestResponse = $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $guestTeams),
            array_column($guestResponse, 'id')
        );

        Sanctum::actingAs($this->admin);
        $this->postJson(self::URL)
            ->assertForbidden()
            ->assertJsonPath('status', 1);

        Sanctum::actingAs($this->user);
        $registeredResponse = $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $registeredTeams),
            array_column($registeredResponse, 'id')
        );
    }

    public function test_only_current_users_current_month_d_stage_visible_teams_in_requested_mode_are_used(): void
    {
        $first = $this->createTeam(
            1,
            100,
            [1 => 1, 2 => 1, 3 => 1],
            $this->user->id,
            createdAt: Carbon::parse('2026-10-01 00:00:00')
        );
        $second = $this->createTeam(
            2,
            100,
            [4 => 1, 5 => 1, 6 => 1],
            $this->user->id,
            createdAt: Carbon::parse('2026-10-31 23:59:59')
        );
        $third = $this->createTeam(
            3,
            100,
            [7 => 1, 8 => 1, 9 => 1],
            $this->user->id
        );

        $this->createTeam(
            1,
            100,
            [10 => 1, 11 => 1, 12 => 1],
            $this->user->id,
            createdAt: Carbon::parse('2026-09-30 23:59:59')
        );
        $this->createTeam(
            1,
            100,
            [13 => 1, 14 => 1, 15 => 1],
            $this->user->id,
            createdAt: Carbon::parse('2026-11-01 00:00:00')
        );
        $this->createTeam(1, 100, [16 => 1, 17 => 1, 18 => 1], $this->otherUser->id);
        $this->createTeam(1, 100, [19 => 1, 20 => 1, 21 => 1], 0);
        $this->createTeam(1, 100, [22 => 1, 23 => 1, 24 => 1], $this->user->id, stage: 3);
        $this->createTeam(1, 100, [25 => 1, 26 => 1, 27 => 1], $this->user->id, status: 0);
        $this->createTeam(1, 100, [28 => 1, 29 => 1, 30 => 1], $this->user->id, mode: 2);

        Sanctum::actingAs($this->user);

        $data = $this->postJson(self::URL, ['type' => 1])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($data, 'id')
        );
    }

    public function test_auto_and_manual_modes_are_not_mixed(): void
    {
        $automatic = [
            $this->createTeam(1, 100, [1 => 1, 2 => 1, 3 => 1], $this->user->id),
            $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id),
            $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id),
        ];
        $manual = [
            $this->createTeam(1, 100, [10 => 1, 11 => 1, 12 => 1], $this->user->id, mode: 2),
            $this->createTeam(2, 100, [13 => 1, 14 => 1, 15 => 1], $this->user->id, mode: 2),
            $this->createTeam(3, 100, [16 => 1, 17 => 1, 18 => 1], $this->user->id, mode: 2),
        ];

        Sanctum::actingAs($this->user);

        $automaticResponse = $this->postJson(self::URL, ['type' => 1])
            ->assertOk()
            ->json('data.0');
        $manualResponse = $this->postJson(self::URL, ['type' => 2])
            ->assertOk()
            ->json('data.0');

        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $automatic),
            array_column($automaticResponse, 'id')
        );
        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $manual),
            array_column($manualResponse, 'id')
        );
    }

    public function test_status_zero_role_is_forced_to_be_borrowed(): void
    {
        $first = $this->createTeam(1, 100, [1 => 0, 2 => 1, 3 => 1], $this->user->id);
        $second = $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id);
        $third = $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id);

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(
            [
                $first->id => 1,
                $second->id => 0,
                $third->id => 0,
            ],
            $this->borrowsById($combination)
        );
    }

    public function test_duplicate_role_can_be_reassigned_to_keep_each_team_at_one_borrow(): void
    {
        $first = $this->createTeam(
            1,
            100,
            [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 0],
            $this->user->id
        );
        $second = $this->createTeam(
            2,
            100,
            [1 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1],
            $this->user->id
        );
        $third = $this->createTeam(
            3,
            100,
            [10 => 1, 11 => 1, 12 => 1, 13 => 1, 14 => 1],
            $this->user->id
        );

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(
            [
                $first->id => 5,
                $second->id => 1,
                $third->id => 0,
            ],
            $this->borrowsById($combination)
        );
    }

    public function test_combination_is_rejected_when_no_assignment_keeps_borrows_within_limit(): void
    {
        $this->createTeam(
            1,
            100,
            [1 => 1, 2 => 1, 3 => 1, 5 => 0],
            $this->user->id
        );
        $this->createTeam(
            2,
            100,
            [1 => 1, 2 => 1, 4 => 1, 6 => 1],
            $this->user->id
        );
        $this->createTeam(
            3,
            100,
            [7 => 1, 8 => 1, 9 => 1, 10 => 1],
            $this->user->id
        );

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_team_with_multiple_status_zero_roles_is_rejected(): void
    {
        $this->createTeam(
            1,
            100,
            [1 => 0, 2 => 0, 3 => 1, 4 => 1],
            $this->user->id
        );
        $this->createTeam(
            2,
            100,
            [5 => 1, 6 => 1, 7 => 1, 8 => 1],
            $this->user->id
        );
        $this->createTeam(
            3,
            100,
            [9 => 1, 10 => 1, 11 => 1, 12 => 1],
            $this->user->id
        );

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_role_pool_and_game_role_status_do_not_filter_user_teams(): void
    {
        $first = $this->createTeam(1, 100, [1 => 1, 2 => 1, 3 => 1], $this->user->id);
        $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id);
        $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id);

        GameRole::query()->whereKey(1)->update(['status' => false]);
        DB::table('user_role_pools')->insert([
            'uid' => $this->user->id,
            'nickname' => '不参与用户三刀',
            'roles' => json_encode([999999], JSON_THROW_ON_ERROR),
            'coin' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(
            $first->id,
            $combination[0]['id']
        );
    }

    public function test_custom_team_reads_only_its_visible_links(): void
    {
        $custom = $this->createTeam(1, 100, [1 => 1, 2 => 1, 3 => 1], $this->user->id);
        $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id);
        $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id);

        $visible = $this->createLink($custom, 'https://example.com/custom-visible', 1, 5, 200);
        $this->createLink($custom, 'https://example.com/custom-hidden', 0, 1, 100);

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)
            ->assertOk()
            ->json('data.0');
        $customResponse = collect($combination)->firstWhere('id', $custom->id);

        $this->assertSame([$visible->id], array_column($customResponse['link'], 'id'));
    }

    public function test_copied_team_reads_hidden_source_team_links_and_ignores_own_links(): void
    {
        $source = $this->createTeam(
            1,
            100,
            [1 => 1, 2 => 1, 3 => 1],
            0,
            status: 0,
            createdAt: Carbon::parse('2026-09-01 12:00:00')
        );
        $copied = $this->createTeam(
            1,
            100,
            [4 => 1, 5 => 1, 6 => 1],
            $this->user->id,
            sourceTeamId: $source->id
        );
        $this->createTeam(2, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id);
        $this->createTeam(3, 100, [10 => 1, 11 => 1, 12 => 1], $this->user->id);

        $hiddenSourceLink = $this->createLink(
            $source,
            'https://example.com/source-hidden',
            0,
            5,
            500
        );
        $visibleSourceLink = $this->createLink(
            $source,
            'https://example.com/source-visible',
            1,
            1,
            100
        );
        $ownLink = $this->createLink(
            $copied,
            'https://example.com/own-link',
            1,
            1,
            900
        );

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)
            ->assertOk()
            ->json('data.0');
        $copiedResponse = collect($combination)->firstWhere('id', $copied->id);

        $this->assertSame(
            [$hiddenSourceLink->id, $visibleSourceLink->id],
            array_column($copiedResponse['link'], 'id')
        );
        $this->assertNotContains($ownLink->id, array_column($copiedResponse['link'], 'id'));
    }

    public function test_missing_source_team_returns_empty_links_for_copied_team(): void
    {
        $copied = $this->createTeam(
            1,
            100,
            [1 => 1, 2 => 1, 3 => 1],
            $this->user->id,
            sourceTeamId: 999999
        );
        $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id);
        $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id);
        $this->createLink($copied, 'https://example.com/own-link', 1);

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)
            ->assertOk()
            ->json('data.0');
        $copiedResponse = collect($combination)->firstWhere('id', $copied->id);

        $this->assertSame([], $copiedResponse['link']);
    }

    public function test_stale_system_triple_cache_is_not_read_or_written(): void
    {
        $first = $this->createTeam(1, 100, [1 => 1, 2 => 1, 3 => 1], $this->user->id);
        $second = $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id);
        $third = $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id);

        DB::table('system_triple_caches')->insert([
            'year_month' => '202610',
            'mode' => 1,
            'team_ids' => '999999,999998,999997',
            'team_count' => 3,
            'signature' => str_repeat('a', 64),
            'combinations' => json_encode(
                [[999999, 999998, 999997]],
                JSON_THROW_ON_ERROR
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $data = $this->postJson(self::URL)
            ->assertOk()
            ->json('data.0');

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($data, 'id')
        );
        $this->assertDatabaseCount('system_triple_caches', 1);
        $this->assertDatabaseHas('system_triple_caches', [
            'year_month' => '202610',
            'mode' => 1,
            'team_count' => 3,
        ]);
    }

    public function test_query_count_does_not_grow_with_user_team_count(): void
    {
        $this->createTeam(1, 100, [1 => 1, 2 => 1, 3 => 1], $this->user->id);
        $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id);
        $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id);

        $service = app(SystemTripleService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->getUserGroups($this->user->id, 1);
        $threeTeamQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->createTeam(1, 100, [10 => 1, 11 => 1, 12 => 1], $this->user->id);
        $this->createTeam(2, 100, [13 => 1, 14 => 1, 15 => 1], $this->user->id);
        $this->createTeam(3, 100, [16 => 1, 17 => 1, 18 => 1], $this->user->id);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->getUserGroups($this->user->id, 1);
        $sixTeamQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($threeTeamQueryCount, $sixTeamQueryCount);
    }

    public function test_filters_and_response_shape_match_existing_triple_contract(): void
    {
        $first = $this->createTeam(1, 100, [1 => 1, 2 => 1, 3 => 1], $this->user->id, atkValue: 1);
        $second = $this->createTeam(2, 100, [4 => 1, 5 => 1, 6 => 1], $this->user->id, atkValue: -1);
        $third = $this->createTeam(3, 100, [7 => 1, 8 => 1, 9 => 1], $this->user->id, atkValue: 0);

        Sanctum::actingAs($this->user);

        $data = $this->postJson(self::URL, [
            'row1' => 1,
            'row2' => 2,
            'row3' => 3,
            'lockedIds' => [$first->id, $second->id],
        ])->assertOk()->json('data.0');

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($data, 'id')
        );
        $this->assertSame(
            ['id', 'boss', 'damage', 'atk_value', 'roles', 'borrow', 'remark', 'stage', 'link'],
            array_keys($data[0])
        );

        $this->postJson(self::URL, ['hiddenIds' => [$third->id]])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @param  array<int, int>  $roles
     */
    private function createTeam(
        int $boss,
        int $damage,
        array $roles,
        int $uid,
        int $mode = 1,
        int $status = 1,
        int $stage = 4,
        ?Carbon $createdAt = null,
        ?int $sourceTeamId = null,
        int $atkValue = 0
    ): Team {
        $this->teamSequence++;

        $team = Team::create([
            'stage' => $stage,
            'source_team_id' => $sourceTeamId,
            'uid' => $uid,
            'boss' => $boss,
            'damage' => $damage,
            'team_key' => 'user-triple-'.$this->teamSequence,
            'status' => $status,
            'mode' => $mode,
            'atk_value' => $atkValue,
            'remark' => 'remark-'.$this->teamSequence,
        ]);

        foreach ($roles as $roleId => $roleStatus) {
            $this->ensureGameRole($roleId);

            TeamRole::create([
                'team_id' => $team->id,
                'role_id' => $roleId,
                'status' => $roleStatus,
            ]);
        }

        if ($createdAt !== null) {
            DB::table('teams')
                ->where('id', $team->id)
                ->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            $team->refresh();
        }

        return $team;
    }

    private function createLink(
        Team $team,
        string $url,
        int $status,
        int $difficulty = 1,
        int $damage = 100
    ): TeamLink {
        return TeamLink::create([
            'team_id' => $team->id,
            'url' => $url,
            'author' => 'tester',
            'difficulty' => $difficulty,
            'damage' => $damage,
            'note' => null,
            'status' => $status,
        ]);
    }

    private function ensureGameRole(int $roleId): void
    {
        if (GameRole::query()->whereKey($roleId)->exists()) {
            return;
        }

        GameRole::create([
            'id' => $roleId,
            'role_id' => 1000 + $roleId,
            'role_id_1' => 100 + $roleId,
            'role_id_3' => 3000 + $roleId,
            'role_id_6' => null,
            'element' => 1,
            'atk_type' => 1,
            'is_clan_battle' => false,
            'name' => 'Role '.$roleId,
            'nickname' => 'R'.$roleId,
            'search_area_width' => 1000 - $roleId,
            'position' => 1,
            'obtain' => 1,
            'probability' => 1,
            'up_time' => null,
            'status' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $combination
     * @return array<int, int>
     */
    private function borrowsById(array $combination): array
    {
        $result = [];

        foreach ($combination as $team) {
            $result[$team['id']] = $team['borrow'];
        }

        return $result;
    }

    private function createTestTables(): void
    {
        foreach ([
            'system_triple_caches',
            'team_links',
            'team_roles',
            'teams',
            'game_roles',
            'user_role_pools',
            'admins',
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
            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('is_subscribe')->default(1);
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('nickname')->default('');
            $table->tinyInteger('status')->default(1);
            $table->rememberToken();
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
            $table->string('name');
            $table->string('nickname');
            $table->unsignedInteger('search_area_width');
            $table->unsignedTinyInteger('position');
            $table->boolean('status')->default(true);
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

        Schema::create('user_role_pools', function (Blueprint $table): void {
            $table->id();
            $table->integer('uid');
            $table->string('nickname', 64);
            $table->json('roles');
            $table->json('coin')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('system_triple_caches', function (Blueprint $table): void {
            $table->id();
            $table->char('year_month', 6);
            $table->unsignedTinyInteger('mode');
            $table->text('team_ids');
            $table->unsignedInteger('team_count')->default(0);
            $table->char('signature', 64);
            $table->json('combinations');
            $table->timestamps();
            $table->unique(
                ['year_month', 'mode'],
                'uk_system_triple_caches_period_mode'
            );
        });
    }
}
