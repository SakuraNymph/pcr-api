<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GameRole;
use App\Models\Team;
use App\Models\TeamLink;
use App\Models\TeamRole;
use App\Models\User;
use App\Services\SystemTriplePlanner;
use App\Services\SystemTripleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemTripleApiTest extends TestCase
{
    private const URL = '/api/user/game/teams/triple/system';

    private const PREVIOUS_MONTH_URL = '/api/user/game/teams/triple/system/previous-month';

    private User $user;

    private User $guest;

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

        parent::tearDown();
    }

    public function test_only_registered_users_can_access_the_endpoint(): void
    {
        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];

        foreach ($teams as $team) {
            $this->createLink($team, 1);
        }

        Auth::forgetGuards();
        $this->postJson(self::URL)->assertUnauthorized();

        Sanctum::actingAs($this->guest);
        $this->postJson(self::URL)
            ->assertUnauthorized()
            ->assertJsonPath('status', 1);

        Sanctum::actingAs($this->admin);
        $this->postJson(self::URL)
            ->assertForbidden()
            ->assertJsonPath('status', 1);

        Sanctum::actingAs($this->user);
        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('code', 200)
            ->assertJsonCount(1, 'data');
    }

    public function test_account_id_must_belong_to_current_user_and_be_active(): void
    {
        $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]);

        $otherUser = User::create([
            'ip' => '127.0.0.3',
            'nickname' => 'other',
            'email' => 'other@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $otherPoolId = $this->createRolePool($otherUser->id, [1, 2, 3]);
        $inactivePoolId = $this->createRolePool($this->user->id, [1, 2, 3], 0);

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL, ['accountId' => $otherPoolId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accountId');
        $this->postJson(self::URL, ['accountId' => $inactivePoolId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accountId');
    }

    public function test_difficulty_validation_rejects_out_of_range_values(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson(self::URL, ['difficulty' => 6])
            ->assertStatus(422)
            ->assertJsonValidationErrors('difficulty');
        $this->postJson(self::PREVIOUS_MONTH_URL, ['difficulty' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('difficulty');
    }

    public function test_account_role_pool_marks_missing_role_as_borrowed(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }
        $poolId = $this->createRolePool(
            $this->user->id,
            [2, 3, 4, 5, 6, 7, 8, 9]
        );

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL, [
            'accountId' => $poolId,
        ])->assertOk()->json('data.0');
        $firstHit = $this->findTeamById($combination, $first->id);
        $missingRole = $this->findRoleById($firstHit['roles'], 1);

        $this->assertSame(1, $firstHit['borrow']);
        $this->assertSame(0, $missingRole['status']);
        $this->assertSame(1, $this->findRoleById($firstHit['roles'], 2)['status']);
    }

    public function test_account_borrow_resolves_cross_team_conflicts(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [1 => 1, 4 => 1, 5 => 1]);
        $third = $this->createTeam(3, 100, 1, [1 => 1, 6 => 1, 7 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }
        $poolId = $this->createRolePool(
            $this->user->id,
            [1, 2, 3, 4, 5, 6, 7]
        );

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL, [
            'accountId' => $poolId,
        ])->assertOk()->json('data.0');
        $borrows = $this->borrowsById($combination);

        $this->assertCount(2, array_filter($borrows));
        $this->assertSame(1, $borrows[$second->id]);
        $this->assertSame(1, $borrows[$third->id]);
        $secondRole = $this->findRoleById(
            $this->findTeamById($combination, $second->id)['roles'],
            1
        );
        $this->assertSame(1, $secondRole['status']);
    }

    public function test_account_filter_scans_beyond_first_ten_public_combinations(): void
    {
        foreach ([101, 201, 301] as $offset) {
            $team = $this->createTeam(
                1,
                1000,
                1,
                [
                    $offset => 1,
                    $offset + 1 => 1,
                    $offset + 2 => 1,
                ]
            );
            $this->createLink($team, 1);
        }

        foreach ([11, 21, 31] as $offset) {
            $team = $this->createTeam(
                1,
                1,
                1,
                [
                    $offset => 1,
                    $offset + 1 => 1,
                    $offset + 2 => 1,
                ]
            );
            $this->createLink($team, 1);
        }

        $poolId = $this->createRolePool(
            $this->user->id,
            [11, 12, 13, 21, 22, 23, 31, 32, 33]
        );

        Sanctum::actingAs($this->user);

        $data = $this->postJson(self::URL, [
            'accountId' => $poolId,
        ])->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(
            [11, 12, 13, 21, 22, 23, 31, 32, 33],
            array_values(array_map(
                static fn (array $role): int => (int) $role['role_id'],
                array_merge(...array_map(
                    static fn (array $team): array => $team['roles'],
                    $data[0]
                ))
            ))
        );
    }

    public function test_account_results_are_isolated_and_rebuilt_for_role_pool_changes(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }
        $fullPoolId = $this->createRolePool(
            $this->user->id,
            [1, 2, 3, 4, 5, 6, 7, 8, 9]
        );
        $missingPoolId = $this->createRolePool(
            $this->user->id,
            [2, 3, 4, 5, 6, 7, 8, 9]
        );

        Sanctum::actingAs($this->user);

        $full = $this->postJson(self::URL, [
            'accountId' => $fullPoolId,
        ])->assertOk()->json('data.0');
        $makeAfterFullPool = $this->formalMakeTeamsCache();
        $missing = $this->postJson(self::URL, [
            'accountId' => $missingPoolId,
        ])->assertOk()->json('data.0');
        $makeAfterMissingPool = $this->formalMakeTeamsCache();

        $this->assertSame(0, $this->findTeamById($full, $first->id)['borrow']);
        $this->assertSame(1, $this->findTeamById($missing, $first->id)['borrow']);
        $this->assertDatabaseCount('system_triple_account_caches', 0);
        $this->assertSame(
            $makeAfterFullPool['generated_at'],
            $makeAfterMissingPool['generated_at']
        );

        DB::table('user_role_pools')
            ->where('id', $missingPoolId)
            ->update([
                'roles' => json_encode(
                    [1, 2, 3, 4, 5, 6, 7, 8, 9],
                    JSON_THROW_ON_ERROR
                ),
                'updated_at' => Carbon::now()->addSecond(),
            ]);

        $rebuilt = $this->postJson(self::URL, [
            'accountId' => $missingPoolId,
        ])->assertOk()->json('data.0');

        $this->assertSame(0, $this->findTeamById($rebuilt, $first->id)['borrow']);
        $this->assertDatabaseCount('system_triple_account_caches', 0);
    }

    public function test_account_filter_rejects_multiple_mandatory_roles_without_persisting_empty_result(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 0, 2 => 0, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }
        $poolId = $this->createRolePool(
            $this->user->id,
            [1, 2, 3, 4, 5, 6, 7, 8, 9]
        );

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL, ['accountId' => $poolId])
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('system_triple_account_caches', 0);

        $this->postJson(self::URL, ['accountId' => $poolId])
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('system_triple_account_caches', 0);
    }

    public function test_account_path_keeps_existing_filters(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(1, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(2, 100, -1, [7 => 1, 8 => 1, 9 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }
        $poolId = $this->createRolePool(
            $this->user->id,
            [1, 2, 3, 4, 5, 6, 7, 8, 9]
        );

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL, [
            'accountId' => $poolId,
            'row1' => 1,
            'row2' => 1,
        ])->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::URL, [
            'accountId' => $poolId,
            'atk' => 1,
        ])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::URL, [
            'accountId' => $poolId,
            'lockedIds' => [$first->id, $second->id],
        ])->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(self::URL, [
            'accountId' => $poolId,
            'hiddenIds' => [$third->id],
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_account_path_scopes_combinations_by_difficulty(): void
    {
        $autoTeams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];
        $simpleTeams = [
            $this->createTeam(1, 100, 1, [10 => 1, 11 => 1, 12 => 1]),
            $this->createTeam(2, 100, 1, [13 => 1, 14 => 1, 15 => 1]),
            $this->createTeam(3, 100, 1, [16 => 1, 17 => 1, 18 => 1]),
        ];

        foreach ($autoTeams as $team) {
            $this->createLink($team, 1);
        }

        foreach ($simpleTeams as $team) {
            $this->createLink($team, 2);
        }

        $poolId = $this->createRolePool(
            $this->user->id,
            range(1, 18)
        );

        Sanctum::actingAs($this->user);

        $auto = $this->postJson(self::URL, [
            'accountId' => $poolId,
            'difficulty' => 1,
        ])->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $simple = $this->postJson(self::URL, [
            'accountId' => $poolId,
            'difficulty' => 2,
        ])->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $autoTeams),
            array_column($auto, 'id')
        );
        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $simpleTeams),
            array_column($simple, 'id')
        );
        $this->assertDatabaseCount('system_triple_account_caches', 0);
    }

    public function test_previous_month_allows_guest_and_registered_users(): void
    {
        $first = $this->createTeam(
            1,
            100,
            1,
            [1 => 1, 2 => 1, 3 => 1],
            createdAt: Carbon::parse('2026-09-10 12:00:00')
        );
        $second = $this->createTeam(
            2,
            100,
            1,
            [4 => 1, 5 => 1, 6 => 1],
            createdAt: Carbon::parse('2026-09-11 12:00:00')
        );
        $third = $this->createTeam(
            3,
            100,
            1,
            [7 => 1, 8 => 1, 9 => 1],
            createdAt: Carbon::parse('2026-09-12 12:00:00')
        );
        $this->createLink($first, 1);
        $this->createLink($second, 1);
        $this->createLink($third, 1);

        Auth::forgetGuards();
        $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertUnauthorized();

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('code', 200)
            ->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->user);
        $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->admin);
        $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertForbidden()
            ->assertJsonPath('status', 1);
    }

    public function test_previous_month_uses_previous_natural_month_without_mixing_current_month(): void
    {
        $previousBoss3 = $this->createTeam(
            3,
            100,
            1,
            [1 => 1, 2 => 1, 3 => 1],
            createdAt: Carbon::parse('2026-09-03 12:00:00')
        );
        $previousBoss1 = $this->createTeam(
            1,
            100,
            1,
            [4 => 1, 5 => 1, 6 => 1],
            createdAt: Carbon::parse('2026-09-01 12:00:00')
        );
        $previousBoss2 = $this->createTeam(
            2,
            100,
            1,
            [7 => 1, 8 => 1, 9 => 1],
            createdAt: Carbon::parse('2026-09-02 12:00:00')
        );
        $this->createLink($previousBoss1, 1);
        $this->createLink($previousBoss2, 1);
        $this->createLink($previousBoss3, 1);
        $currentBoss1 = $this->createTeam(
            1,
            100,
            1,
            [10 => 1, 11 => 1, 12 => 1]
        );
        $currentBoss2 = $this->createTeam(
            2,
            100,
            1,
            [13 => 1, 14 => 1, 15 => 1]
        );
        $currentBoss3 = $this->createTeam(
            3,
            100,
            1,
            [16 => 1, 17 => 1, 18 => 1]
        );
        $this->createLink($currentBoss1, 1);
        $this->createLink($currentBoss2, 1);
        $this->createLink($currentBoss3, 1);

        $outsideMonth = $this->createTeam(
            1,
            100,
            1,
            [19 => 1, 20 => 1, 21 => 1],
            createdAt: Carbon::parse('2026-08-31 23:59:59')
        );
        $wrongStage = $this->createTeam(
            1,
            100,
            1,
            [22 => 1, 23 => 1, 24 => 1],
            stage: 3,
            createdAt: Carbon::parse('2026-09-04 12:00:00')
        );
        $userTeam = $this->createTeam(
            1,
            100,
            1,
            [25 => 1, 26 => 1, 27 => 1],
            uid: $this->user->id,
            createdAt: Carbon::parse('2026-09-05 12:00:00')
        );
        $hiddenTeam = $this->createTeam(
            1,
            100,
            1,
            [28 => 1, 29 => 1, 30 => 1],
            status: 0,
            createdAt: Carbon::parse('2026-09-06 12:00:00')
        );
        $this->createLink($outsideMonth, 1);
        $this->createLink($wrongStage, 1);
        $this->createLink($userTeam, 1);
        $this->createLink($hiddenTeam, 1);
        Sanctum::actingAs($this->user);
        $currentResponse = $this->postJson(self::URL)
            ->assertOk()
            ->json('data');
        $currentCache = $this->formalSourceCache();
        $currentCacheSignature = $currentCache['signature'];

        Sanctum::actingAs($this->guest);
        $previousResponse = $this->postJson(
            self::PREVIOUS_MONTH_URL
        )->assertOk()->json('data');

        $this->assertSame(
            [
                $currentBoss1->id,
                $currentBoss2->id,
                $currentBoss3->id,
            ],
            array_column($currentResponse[0], 'id')
        );
        $this->assertSame(
            [
                $previousBoss1->id,
                $previousBoss2->id,
                $previousBoss3->id,
            ],
            array_column($previousResponse[0], 'id')
        );

        $previousCache = $this->formalSourceCache('202609');
        $this->assertSame(3, $previousCache['team_count']);
        $previousIds = array_map(
            static fn (Team $team): int => $team->id,
            [$previousBoss1, $previousBoss2, $previousBoss3]
        );
        sort($previousIds, SORT_NUMERIC);
        $this->assertSame(
            $previousIds,
            array_column($previousCache['snapshots'], 'id')
        );
        $this->assertNotSame(
            $currentCacheSignature,
            $previousCache['signature']
        );
        $this->assertSame(
            $currentCacheSignature,
            $this->formalSourceCache()['signature']
        );
        $this->assertDatabaseCount('system_triple_caches', 0);
    }

    public function test_previous_month_handles_year_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        $decemberBoss1 = $this->createTeam(
            1,
            100,
            1,
            [1 => 1, 2 => 1, 3 => 1],
            createdAt: Carbon::parse('2025-12-01 12:00:00')
        );
        $decemberBoss2 = $this->createTeam(
            2,
            100,
            1,
            [4 => 1, 5 => 1, 6 => 1],
            createdAt: Carbon::parse('2025-12-02 12:00:00')
        );
        $decemberBoss3 = $this->createTeam(
            3,
            100,
            1,
            [7 => 1, 8 => 1, 9 => 1],
            createdAt: Carbon::parse('2025-12-03 12:00:00')
        );
        $januaryBoss1 = $this->createTeam(
            1,
            100,
            1,
            [10 => 1, 11 => 1, 12 => 1],
            createdAt: Carbon::parse('2026-01-01 12:00:00')
        );
        $januaryBoss2 = $this->createTeam(
            2,
            100,
            1,
            [13 => 1, 14 => 1, 15 => 1],
            createdAt: Carbon::parse('2026-01-02 12:00:00')
        );
        $januaryBoss3 = $this->createTeam(
            3,
            100,
            1,
            [16 => 1, 17 => 1, 18 => 1],
            createdAt: Carbon::parse('2026-01-03 12:00:00')
        );
        $this->createLink($decemberBoss1, 1);
        $this->createLink($decemberBoss2, 1);
        $this->createLink($decemberBoss3, 1);
        $this->createLink($januaryBoss1, 1);
        $this->createLink($januaryBoss2, 1);
        $this->createLink($januaryBoss3, 1);

        Sanctum::actingAs($this->guest);
        $response = $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [
                $decemberBoss1->id,
                $decemberBoss2->id,
                $decemberBoss3->id,
            ],
            array_column($response[0], 'id')
        );
        $source = $this->formalSourceCache('202512');
        $make = $this->formalMakeTeamsCache('202512');
        $this->assertSame(3, $source['team_count']);
        $this->assertCount(1, $make['groups'][1]);
        $this->assertDatabaseCount('system_triple_caches', 0);
    }

    public function test_previous_month_applies_existing_filters(): void
    {
        $first = $this->createTeam(
            1,
            100,
            1,
            [1 => 1, 2 => 1, 3 => 1],
            createdAt: Carbon::parse('2026-09-01 12:00:00')
        );
        $second = $this->createTeam(
            1,
            100,
            1,
            [4 => 1, 5 => 1, 6 => 1],
            createdAt: Carbon::parse('2026-09-02 12:00:00')
        );
        $third = $this->createTeam(
            2,
            100,
            -1,
            [7 => 1, 8 => 1, 9 => 1],
            createdAt: Carbon::parse('2026-09-03 12:00:00')
        );
        $this->createLink($first, 1);
        $this->createLink($second, 1);
        $this->createLink($third, 1);

        Sanctum::actingAs($this->guest);

        $this->postJson(
            self::PREVIOUS_MONTH_URL,
            ['row1' => 1, 'row2' => 2]
        )->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(
            self::PREVIOUS_MONTH_URL,
            ['atk' => 2]
        )->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(
            self::PREVIOUS_MONTH_URL,
            ['atk' => 1]
        )->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(
            self::PREVIOUS_MONTH_URL,
            ['lockedIds' => [$first->id, $second->id]]
        )->assertOk()->assertJsonCount(1, 'data');
        $this->postJson(
            self::PREVIOUS_MONTH_URL,
            ['hiddenIds' => [$third->id]]
        )->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_previous_month_ignores_legacy_cache_table(): void
    {
        $first = $this->createTeam(
            1,
            100,
            1,
            [1 => 1, 2 => 1, 3 => 1],
            createdAt: Carbon::parse('2026-09-01 12:00:00')
        );
        $second = $this->createTeam(
            2,
            100,
            1,
            [4 => 1, 5 => 1, 6 => 1],
            createdAt: Carbon::parse('2026-09-02 12:00:00')
        );
        $third = $this->createTeam(
            3,
            100,
            1,
            [7 => 1, 8 => 1, 9 => 1],
            createdAt: Carbon::parse('2026-09-03 12:00:00')
        );
        $this->createLink($first, 1);
        $this->createLink($second, 1);
        $this->createLink($third, 1);

        DB::table('system_triple_caches')->insert([
            'year_month' => '202609',
            'mode' => 1,
            'team_ids' => '999999,999998,999997',
            'team_count' => 3,
            'signature' => str_repeat('b', 64),
            'combinations' => json_encode(
                [[999999, 999998, 999997]],
                JSON_THROW_ON_ERROR
            ),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->guest);

        $data = $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($data, 'id')
        );
        $this->assertDatabaseCount('system_triple_caches', 1);
        $this->assertDatabaseHas('system_triple_caches', [
            'year_month' => '202609',
            'mode' => 1,
            'team_count' => 3,
        ]);
        $this->assertSame(
            3,
            $this->formalSourceCache('202609')['team_count']
        );
    }

    public function test_previous_month_reuses_role_overlap_and_borrow_rules(): void
    {
        $first = $this->createTeam(
            1,
            100,
            1,
            [1 => 1, 2 => 1, 3 => 1],
            createdAt: Carbon::parse('2026-09-01 12:00:00')
        );
        $second = $this->createTeam(
            2,
            100,
            1,
            [1 => 1, 4 => 1, 5 => 1],
            createdAt: Carbon::parse('2026-09-02 12:00:00')
        );
        $third = $this->createTeam(
            3,
            100,
            1,
            [1 => 1, 6 => 1, 7 => 1],
            createdAt: Carbon::parse('2026-09-03 12:00:00')
        );
        $this->createLink($first, 1);
        $this->createLink($second, 1);
        $this->createLink($third, 1);

        Sanctum::actingAs($this->guest);
        $combination = $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->json('data.0');

        $this->assertSame(
            [
                $first->id => 1,
                $second->id => 1,
                $third->id => 0,
            ],
            $this->borrowsById($combination)
        );
    }

    public function test_previous_month_cache_is_shared_and_ignores_user_role_pools(): void
    {
        $first = $this->createTeam(
            1,
            100,
            1,
            [1 => 1, 2 => 1, 3 => 1],
            createdAt: Carbon::parse('2026-09-01 12:00:00')
        );
        $second = $this->createTeam(
            2,
            100,
            1,
            [4 => 1, 5 => 1, 6 => 1],
            createdAt: Carbon::parse('2026-09-02 12:00:00')
        );
        $third = $this->createTeam(
            3,
            100,
            1,
            [7 => 1, 8 => 1, 9 => 1],
            createdAt: Carbon::parse('2026-09-03 12:00:00')
        );
        $this->createLink($first, 1);
        $this->createLink($second, 1);
        $this->createLink($third, 1);

        DB::table('user_role_pools')->insert([
            [
                'uid' => $this->guest->id,
                'nickname' => '游客角色池',
                'roles' => json_encode([1, 2, 3], JSON_THROW_ON_ERROR),
                'coin' => null,
                'status' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'uid' => $this->user->id,
                'nickname' => '正式用户角色池',
                'roles' => json_encode([7, 8, 9], JSON_THROW_ON_ERROR),
                'coin' => null,
                'status' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);

        Sanctum::actingAs($this->guest);
        $guestData = $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->json('data');

        Sanctum::actingAs($this->user);
        $userData = $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->json('data');

        $this->assertSame($guestData, $userData);
        $this->assertSame(
            [
                $first->id,
                $second->id,
                $third->id,
            ],
            array_column($userData[0], 'id')
        );
    }

    public function test_previous_month_difficulty_scopes_are_isolated_and_ignore_teams_mode(): void
    {
        $autoTeams = [
            $this->createTeam(
                1,
                100,
                1,
                [1 => 1, 2 => 1, 3 => 1],
                mode: 1,
                createdAt: Carbon::parse('2026-09-01 12:00:00')
            ),
            $this->createTeam(
                2,
                100,
                1,
                [4 => 1, 5 => 1, 6 => 1],
                mode: 2,
                createdAt: Carbon::parse('2026-09-02 12:00:00')
            ),
            $this->createTeam(
                3,
                100,
                1,
                [7 => 1, 8 => 1, 9 => 1],
                mode: 1,
                createdAt: Carbon::parse('2026-09-03 12:00:00')
            ),
        ];
        $simpleTeams = [
            $this->createTeam(
                1,
                100,
                1,
                [10 => 1, 11 => 1, 12 => 1],
                mode: 2,
                createdAt: Carbon::parse('2026-09-04 12:00:00')
            ),
            $this->createTeam(
                2,
                100,
                1,
                [13 => 1, 14 => 1, 15 => 1],
                mode: 1,
                createdAt: Carbon::parse('2026-09-05 12:00:00')
            ),
            $this->createTeam(
                3,
                100,
                1,
                [16 => 1, 17 => 1, 18 => 1],
                mode: 2,
                createdAt: Carbon::parse('2026-09-06 12:00:00')
            ),
        ];

        foreach ($autoTeams as $team) {
            $this->createLink($team, 1);
        }

        foreach ($simpleTeams as $team) {
            $this->createLink($team, 2);
        }

        Sanctum::actingAs($this->guest);
        $auto = $this->postJson(
            self::PREVIOUS_MONTH_URL,
            ['difficulty' => 1]
        )->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $simple = $this->postJson(
            self::PREVIOUS_MONTH_URL,
            ['difficulty' => 2]
        )->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $autoTeams),
            array_column($auto, 'id')
        );
        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $simpleTeams),
            array_column($simple, 'id')
        );

        $make = $this->formalMakeTeamsCache('202609');
        $this->assertCount(1, $make['groups'][1]);
        $this->assertCount(1, $make['groups'][2]);
        $this->assertDatabaseCount('system_triple_caches', 0);
        $this->assertFalse(
            Cache::has('systemTripleTeamsMakeTeams:202610')
        );
    }

    public function test_previous_month_defaults_to_auto_difficulty(): void
    {
        $autoTeams = [
            $this->createTeam(
                1,
                100,
                1,
                [1 => 1, 2 => 1, 3 => 1],
                createdAt: Carbon::parse('2026-09-01 12:00:00')
            ),
            $this->createTeam(
                2,
                100,
                1,
                [4 => 1, 5 => 1, 6 => 1],
                createdAt: Carbon::parse('2026-09-02 12:00:00')
            ),
            $this->createTeam(
                3,
                100,
                1,
                [7 => 1, 8 => 1, 9 => 1],
                createdAt: Carbon::parse('2026-09-03 12:00:00')
            ),
        ];
        $simpleTeams = [
            $this->createTeam(
                1,
                100,
                1,
                [10 => 1, 11 => 1, 12 => 1],
                createdAt: Carbon::parse('2026-09-04 12:00:00')
            ),
            $this->createTeam(
                2,
                100,
                1,
                [13 => 1, 14 => 1, 15 => 1],
                createdAt: Carbon::parse('2026-09-05 12:00:00')
            ),
            $this->createTeam(
                3,
                100,
                1,
                [16 => 1, 17 => 1, 18 => 1],
                createdAt: Carbon::parse('2026-09-06 12:00:00')
            ),
        ];

        foreach ($autoTeams as $team) {
            $this->createLink($team, 1);
        }

        foreach ($simpleTeams as $team) {
            $this->createLink($team, 2);
        }

        $autoIds = array_map(
            static fn (Team $team): int => $team->id,
            $autoTeams
        );

        Sanctum::actingAs($this->guest);

        $default = $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $zero = $this->postJson(
            self::PREVIOUS_MONTH_URL,
            ['difficulty' => 0]
        )->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame($autoIds, array_column($default, 'id'));
        $this->assertSame($autoIds, array_column($zero, 'id'));
        $make = $this->formalMakeTeamsCache('202609');
        $this->assertCount(1, $make['groups'][1]);
        $this->assertDatabaseCount('system_triple_caches', 0);
    }

    public function test_previous_month_cache_rebuilds_when_previous_teams_change(): void
    {
        $first = $this->createTeam(
            1,
            100,
            1,
            [1 => 1, 2 => 1, 3 => 1],
            createdAt: Carbon::parse('2026-09-01 12:00:00')
        );
        $second = $this->createTeam(
            2,
            100,
            1,
            [4 => 1, 5 => 1, 6 => 1],
            createdAt: Carbon::parse('2026-09-02 12:00:00')
        );
        $third = $this->createTeam(
            3,
            100,
            1,
            [7 => 1, 8 => 1, 9 => 1],
            createdAt: Carbon::parse('2026-09-03 12:00:00')
        );
        $this->createLink($first, 1);
        $this->createLink($second, 1);
        $this->createLink($third, 1);

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $sourceBefore = $this->formalSourceCache('202609');
        $makeBefore = $this->formalMakeTeamsCache('202609');
        $signatureBefore = $sourceBefore['signature'];
        $generatedAtBefore = $makeBefore['generated_at'];
        $this->assertSame(3, $sourceBefore['team_count']);
        $this->assertCount(1, $makeBefore['groups'][1]);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $sourceHit = $this->formalSourceCache('202609');
        $makeHit = $this->formalMakeTeamsCache('202609');

        $this->assertSame($signatureBefore, $sourceHit['signature']);
        $this->assertSame($generatedAtBefore, $makeHit['generated_at']);

        TeamRole::query()
            ->where('team_id', $first->id)
            ->where('role_id', 1)
            ->update(['status' => 0]);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->postJson(self::PREVIOUS_MONTH_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $sourceAfterRoleChange = $this->formalSourceCache('202609');
        $makeAfterRoleChange = $this->formalMakeTeamsCache('202609');

        $this->assertSame(3, $sourceAfterRoleChange['team_count']);
        $this->assertNotSame(
            $signatureBefore,
            $sourceAfterRoleChange['signature']
        );
        $this->assertNotSame(
            $generatedAtBefore,
            $makeAfterRoleChange['generated_at']
        );

        $newTeam = $this->createTeam(
            4,
            100,
            1,
            [10 => 1, 11 => 1, 12 => 1],
            createdAt: Carbon::parse('2026-09-04 12:00:00')
        );
        $this->createLink($newTeam, 1);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->postJson(self::PREVIOUS_MONTH_URL)->assertOk();
        $sourceAfterTeamChange = $this->formalSourceCache('202609');

        $this->assertSame(4, $sourceAfterTeamChange['team_count']);
        $this->assertContains(
            $newTeam->id,
            array_column($sourceAfterTeamChange['snapshots'], 'id')
        );
        $this->assertNotSame(
            $sourceAfterRoleChange['signature'],
            $sourceAfterTeamChange['signature']
        );
        $this->assertDatabaseCount('system_triple_caches', 0);
    }

    public function test_only_current_month_system_d_stage_teams_with_requested_difficulty_are_used(): void
    {
        $teamBoss3 = $this->createTeam(3, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $teamBoss1 = $this->createTeam(1, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $teamBoss2 = $this->createTeam(2, 100, 1, [7 => 1, 8 => 1, 9 => 1]);
        $otherDifficulty = $this->createTeam(
            4,
            100,
            1,
            [25 => 1, 26 => 1, 27 => 1]
        );

        foreach ([$teamBoss1, $teamBoss2, $teamBoss3] as $team) {
            $this->createLink($team, 1);
        }

        $this->createLink($otherDifficulty, 2);

        $this->createTeam(
            1,
            100,
            1,
            [10 => 1, 11 => 1, 12 => 1],
            createdAt: Carbon::now()->subMonthNoOverflow()
        );
        $this->createTeam(1, 100, 1, [13 => 1, 14 => 1, 15 => 1], stage: 3);
        $this->createTeam(1, 100, 1, [16 => 1, 17 => 1, 18 => 1], uid: $this->user->id);
        $this->createTeam(1, 100, 1, [19 => 1, 20 => 1, 21 => 1], status: 0);
        $this->createTeam(1, 100, 1, [22 => 1, 23 => 1, 24 => 1], mode: 2);

        Sanctum::actingAs($this->user);

        $response = $this->postJson(self::URL, ['difficulty' => 1])
            ->assertOk();
        $combinations = $response->json('data');

        $this->assertCount(1, $combinations);
        $this->assertSame(
            [$teamBoss1->id, $teamBoss2->id, $teamBoss3->id],
            array_column($combinations[0], 'id')
        );

        $this->postJson(self::URL, ['difficulty' => 2])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_role_shared_by_all_three_teams_is_valid_and_assigns_two_borrows(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [1 => 1, 4 => 1, 5 => 1]);
        $third = $this->createTeam(3, 100, 1, [1 => 1, 6 => 1, 7 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)->assertOk()->json('data.0');

        $this->assertCount(3, $combination);
        $this->assertSame(
            [
                $first->id => 1,
                $second->id => 1,
                $third->id => 0,
            ],
            $this->borrowsById($combination)
        );
    }

    public function test_two_roles_shared_by_all_three_teams_are_rejected(): void
    {
        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 10 => 1]),
            $this->createTeam(2, 100, 1, [1 => 1, 2 => 1, 11 => 1]),
            $this->createTeam(3, 100, 1, [1 => 1, 2 => 1, 12 => 1]),
        ];

        foreach ($teams as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_pair_sharing_three_active_roles_is_rejected(): void
    {
        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(3, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
        ];

        foreach ($teams as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pair_sharing_two_active_roles_can_form_a_valid_combination(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 7 => 1]);
        $second = $this->createTeam(2, 100, 1, [1 => 1, 2 => 1, 8 => 1]);
        $third = $this->createTeam(3, 100, 1, [3 => 1, 4 => 1, 5 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)->assertOk()->json('data.0');

        $this->assertSame(
            [
                $first->id => 1,
                $second->id => 2,
                $third->id => 0,
            ],
            $this->borrowsById($combination)
        );
    }

    public function test_status_zero_role_is_marked_as_borrowed_first(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 0, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL)->assertOk()->json('data.0');

        $this->assertSame(
            [
                $first->id => 1,
                $second->id => 0,
                $third->id => 0,
            ],
            $this->borrowsById($combination)
        );
    }

    public function test_boss_attack_lock_and_hidden_filters_match_legacy_rules(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(1, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(2, 100, -1, [7 => 1, 8 => 1, 9 => 1]);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL, ['row1' => 1])->assertJsonCount(1, 'data');
        $this->postJson(self::URL, ['row1' => 1, 'row2' => 1])
            ->assertJsonCount(1, 'data');
        $this->postJson(self::URL, ['row1' => 1, 'row2' => 2])
            ->assertJsonCount(1, 'data');
        $this->postJson(self::URL, ['row1' => 1, 'row2' => 1, 'row3' => 2])
            ->assertJsonCount(1, 'data');
        $this->postJson(self::URL, ['row1' => 1, 'row2' => 2, 'row3' => 2])
            ->assertJsonCount(0, 'data');

        $this->postJson(self::URL, ['atk' => 1])->assertJsonCount(0, 'data');
        $this->postJson(self::URL, ['atk' => 2])->assertJsonCount(1, 'data');
        $this->postJson(self::URL, ['atk' => 3])->assertJsonCount(0, 'data');
        $this->postJson(self::URL, ['atk' => 4])->assertJsonCount(0, 'data');

        $this->postJson(self::URL, [
            'lockedIds' => [$first->id, $second->id],
        ])->assertJsonCount(1, 'data');
        $this->postJson(self::URL, [
            'lockedIds' => [$first->id, 9999],
        ])->assertJsonCount(0, 'data');
        $this->postJson(self::URL, [
            'hiddenIds' => [$third->id],
        ])->assertJsonCount(0, 'data');
    }

    public function test_results_are_limited_to_ten_unique_id_combinations(): void
    {
        for ($index = 1; $index <= 6; $index++) {
            $baseRole = ($index - 1) * 3 + 1;
            $team = $this->createTeam(
                1,
                100,
                1,
                [
                    $baseRole => 1,
                    $baseRole + 1 => 1,
                    $baseRole + 2 => 1,
                ]
            );
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);

        $data = $this->postJson(self::URL)->assertOk()->json('data');

        $this->assertCount(10, $data);
        $this->assertCount(
            10,
            array_unique(array_map(
                static function (array $combination): string {
                    $ids = array_column($combination, 'id');
                    sort($ids);

                    return implode(',', $ids);
                },
                $data
            ))
        );
    }

    public function test_combination_order_uses_weighted_damage_and_boss_order_inside_each_group(): void
    {
        $boss5 = $this->createTeam(5, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $boss1 = $this->createTeam(1, 10, 1, [4 => 1, 5 => 1, 6 => 1]);
        $boss2 = $this->createTeam(2, 10, 1, [7 => 1, 8 => 1, 9 => 1]);
        $boss3 = $this->createTeam(3, 10, 1, [10 => 1, 11 => 1, 12 => 1]);

        foreach ([$boss5, $boss1, $boss2, $boss3] as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);

        $data = $this->postJson(self::URL)->assertOk()->json('data');

        $this->assertSame(
            [
                [$boss1->id, $boss3->id, $boss5->id],
                [$boss2->id, $boss3->id, $boss5->id],
                [$boss1->id, $boss2->id, $boss5->id],
                [$boss1->id, $boss2->id, $boss3->id],
            ],
            array_map(
                static fn (array $combination): array => array_column($combination, 'id'),
                $data
            )
        );
        $this->assertSame([1, 3, 5], array_column($data[0], 'boss'));
    }

    public function test_cache_reuses_base_combinations_and_rebuilds_when_signature_changes(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [1 => 1, 4 => 1, 5 => 1]);
        $third = $this->createTeam(3, 100, 1, [1 => 1, 6 => 1, 7 => 1]);
        $this->createLink($second, 1);
        $this->createLink($third, 1);

        $activeLink = TeamLink::create([
            'team_id' => $first->id,
            'url' => 'https://example.com/active',
            'author' => 'tester',
            'difficulty' => 1,
            'damage' => 100,
            'note' => 'active',
            'status' => 1,
        ]);
        TeamLink::create([
            'team_id' => $first->id,
            'url' => 'https://example.com/hidden',
            'author' => 'tester',
            'difficulty' => 1,
            'damage' => 90,
            'note' => 'hidden',
            'status' => 0,
        ]);

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.0.0.link')
            ->assertJsonPath('data.0.0.link.0.url', 'https://example.com/active')
            ->assertJsonPath('data.0.0.roles.0.image_id', 3001)
            ->assertJsonPath(
                'data.0.0.roles.0.image_url',
                url('images/roles/3001.webp')
            );

        $sourceBefore = $this->formalSourceCache();
        $makeBefore = $this->formalMakeTeamsCache();
        $signatureBefore = $sourceBefore['signature'];

        $this->assertSame(3, $sourceBefore['team_count']);
        $this->assertSame(
            [1, 2, 3, 4, 5],
            array_keys($makeBefore['groups'])
        );
        $this->assertCount(1, $makeBefore['groups'][1]);
        $this->assertSame(64, strlen($signatureBefore));
        $generatedAtBefore = $makeBefore['generated_at'];

        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $sourceHit = $this->formalSourceCache();
        $makeHit = $this->formalMakeTeamsCache();

        $this->assertSame($signatureBefore, $sourceHit['signature']);
        $this->assertSame($generatedAtBefore, $makeHit['generated_at']);

        $activeLink->update(['status' => 0]);
        Carbon::setTestNow(Carbon::now()->addSecond());

        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $sourceAfterLinkChange = $this->formalSourceCache();
        $makeAfterLinkChange = $this->formalMakeTeamsCache();
        $this->assertNotSame($signatureBefore, $sourceAfterLinkChange['signature']);
        $this->assertSame([], $makeAfterLinkChange['groups'][1]);

        TeamRole::query()
            ->where('team_id', $second->id)
            ->where('role_id', 1)
            ->update(['status' => 0]);
        TeamRole::query()
            ->where('team_id', $third->id)
            ->where('role_id', 1)
            ->update(['status' => 0]);
        Carbon::setTestNow(Carbon::now()->addSecond());

        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $sourceAfterRoleChange = $this->formalSourceCache();
        $this->assertNotSame(
            $sourceAfterLinkChange['signature'],
            $sourceAfterRoleChange['signature']
        );
        $this->assertDatabaseCount('system_triple_caches', 0);
        $this->assertDatabaseCount('system_triple_account_caches', 0);
    }

    public function test_cache_rebuilds_when_team_ids_change_with_same_count(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]);

        Sanctum::actingAs($this->user);

        foreach ([$first, $second, $third] as $team) {
            $this->createLink($team, 1);
        }

        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $sourceBefore = $this->formalSourceCache();

        $first->update(['status' => 0]);
        $replacement = $this->createTeam(
            4,
            100,
            1,
            [10 => 1, 11 => 1, 12 => 1]
        );
        $this->createLink($replacement, 1);
        Carbon::setTestNow(Carbon::now()->addSecond());

        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $sourceAfter = $this->formalSourceCache();

        $this->assertSame(3, $sourceAfter['team_count']);
        $this->assertSame(
            [$second->id, $third->id, $replacement->id],
            array_column($sourceAfter['snapshots'], 'id')
        );
        $this->assertNotSame($sourceBefore['signature'], $sourceAfter['signature']);
    }

    public function test_cache_is_scoped_by_month_and_difficulty(): void
    {
        foreach ([1, 2, 3] as $index) {
            $start = $index * 3;
            $team = $this->createTeam(
                1,
                100,
                1,
                [$start + 1 => 1, $start + 2 => 1, $start + 3 => 1]
            );
            $this->createLink($team, 1);
        }

        foreach ([1, 2, 3] as $index) {
            $start = 10 + $index * 3;
            $team = $this->createTeam(
                1,
                100,
                1,
                [$start + 1 => 1, $start + 2 => 1, $start + 3 => 1]
            );
            $this->createLink($team, 2);
        }

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL, ['difficulty' => 1])->assertOk();
        $this->postJson(self::URL, ['difficulty' => 2])->assertOk();

        $make = $this->formalMakeTeamsCache();

        $this->assertCount(1, $make['groups'][1]);
        $this->assertCount(1, $make['groups'][2]);
        $this->assertCount(0, $make['groups'][3]);
        $this->assertDatabaseCount('system_triple_caches', 0);
        $this->assertDatabaseCount('system_triple_account_caches', 0);
    }

    public function test_cache_unique_index_prevents_duplicate_period_and_mode(): void
    {
        foreach (['first', 'second'] as $suffix) {
            DB::table('system_triple_caches')->insertOrIgnore([
                'year_month' => '202610',
                'mode' => 1,
                'team_ids' => '',
                'team_count' => 0,
                'signature' => $suffix,
                'combinations' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->assertDatabaseCount('system_triple_caches', 1);
    }

    public function test_all_three_teams_must_contain_the_selected_difficulty(): void
    {
        $autoTeams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];
        $onlySimple = $this->createTeam(
            4,
            100,
            1,
            [10 => 1, 11 => 1, 12 => 1]
        );

        foreach ($autoTeams as $team) {
            $this->createLink($team, 1);
        }

        $this->createLink($onlySimple, 2);

        Sanctum::actingAs($this->user);

        $combination = $this->postJson(self::URL, ['difficulty' => 1])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(
            array_map(static fn (Team $team): int => $team->id, $autoTeams),
            array_column($combination, 'id')
        );

        $this->postJson(self::URL, ['difficulty' => 2])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_five_difficulties_are_generated_as_separate_groups_and_default_to_auto(): void
    {
        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];

        foreach ($teams as $team) {
            foreach ([1, 2, 3, 4, 5] as $difficulty) {
                $this->createLink($team, $difficulty);
            }
        }

        Sanctum::actingAs($this->user);

        for ($difficulty = 1; $difficulty <= 5; $difficulty++) {
            $data = $this->postJson(self::URL, [
                'difficulty' => $difficulty,
            ])->assertOk()->assertJsonCount(1, 'data')->json('data.0');

            $this->assertSame(
                array_map(static fn (Team $team): int => $team->id, $teams),
                array_column($data, 'id')
            );
        }

        $default = $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $auto = $this->postJson(self::URL, ['difficulty' => 1])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $make = $this->formalMakeTeamsCache();

        $this->assertSame($auto, $default);

        foreach ([1, 2, 3, 4, 5] as $difficulty) {
            $this->assertCount(1, $make['groups'][$difficulty]);
        }
    }

    public function test_new_public_groups_are_exactly_equivalent_to_legacy_combination_rules(): void
    {
        $fixtures = [
            [1, 100, 1, [1 => 1, 2 => 1, 3 => 1], [1, 2, 5]],
            [2, 90, -1, [1 => 1, 4 => 1, 5 => 1], [1, 2, 3, 4, 5]],
            [3, 80, 1, [1 => 1, 6 => 1, 7 => 1], [1, 5]],
            [4, 70, -1, [8 => 1, 9 => 1, 10 => 1], [2, 3]],
            [5, 60, 1, [11 => 0, 12 => 1, 13 => 1], [1, 3, 5]],
            [6, 50, -1, [14 => 1, 15 => 1, 16 => 1], [4]],
            [7, 40, 1, [17 => 1, 18 => 1, 19 => 1], [1, 2, 3, 4, 5]],
            [8, 30, 1, [20 => 1, 21 => 1, 22 => 1], [2, 4]],
        ];

        foreach ($fixtures as [$boss, $damage, $atk, $roles, $difficulties]) {
            $team = $this->createTeam($boss, $damage, $atk, $roles);

            foreach ($difficulties as $difficulty) {
                $this->createLink($team, $difficulty);
            }
        }

        $teams = Team::query()
            ->where('uid', 0)
            ->where('stage', 4)
            ->where('status', 1)
            ->with([
                'teamRoles' => fn ($query) => $query
                    ->orderBy('id')
                    ->with('role'),
                'links' => fn ($query) => $query
                    ->where('status', 1)
                    ->orderByDesc('damage')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();
        $service = app(SystemTripleService::class);
        $planner = app(SystemTriplePlanner::class);
        $snapshots = $service->buildSnapshotState($teams, [4])['snapshots'];
        $built = $planner->build(array_values($snapshots));

        foreach ([1, 2, 3, 4, 5] as $difficulty) {
            $filteredSnapshots = array_filter(
                $snapshots,
                function (array $snapshot) use ($difficulty): bool {
                    foreach ($snapshot['links'] as $link) {
                        if ((int) $link['difficulty'] === $difficulty) {
                            return true;
                        }
                    }

                    return false;
                }
            );
            $legacy = $service->buildCombinationsForState([
                'snapshots' => $filteredSnapshots,
                'team_ids' => implode(',', array_keys($filteredSnapshots)),
                'team_count' => count($filteredSnapshots),
                'signature' => '',
            ]);
            $optimized = array_values(array_map(
                static fn (int $index): array => $built['combinations'][$index]['team_ids'],
                $built['groups'][$difficulty]
            ));

            $this->assertSame($legacy, $optimized, "difficulty {$difficulty}");
        }
    }

    public function test_missing_difficulty_versions_only_remove_that_difficulty_group(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]);

        foreach ([$first, $second] as $team) {
            foreach ([1, 2, 3, 4, 5] as $difficulty) {
                $this->createLink($team, $difficulty);
            }
        }

        foreach ([1, 5] as $difficulty) {
            $this->createLink($third, $difficulty);
        }

        Sanctum::actingAs($this->user);

        foreach ([1, 5] as $difficulty) {
            $this->postJson(self::URL, ['difficulty' => $difficulty])
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }

        foreach ([2, 3, 4] as $difficulty) {
            $this->postJson(self::URL, ['difficulty' => $difficulty])
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }

        $make = $this->formalMakeTeamsCache();
        $this->assertCount(1, $make['groups'][1]);
        $this->assertCount(0, $make['groups'][2]);
        $this->assertCount(0, $make['groups'][3]);
        $this->assertCount(0, $make['groups'][4]);
        $this->assertCount(1, $make['groups'][5]);
    }

    public function test_request_filters_do_not_regenerate_public_combinations(): void
    {
        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];

        foreach ($teams as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);
        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $makeBefore = $this->formalMakeTeamsCache();
        $sourceBefore = $this->formalSourceCache();

        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->postJson(self::URL, [
            'row1' => 1,
            'row2' => 2,
            'atk' => 2,
            'lockedIds' => [$teams[0]->id],
            'hiddenIds' => [],
        ])->assertOk();

        $makeAfter = $this->formalMakeTeamsCache();
        $sourceAfter = $this->formalSourceCache();

        $this->assertSame($makeBefore['generated_at'], $makeAfter['generated_at']);
        $this->assertSame($sourceBefore['signature'], $sourceAfter['signature']);
    }

    public function test_public_cache_hit_does_not_extend_thirty_day_ttl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));

        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];

        foreach ($teams as $team) {
            $this->createLink($team, 1);
        }

        $sourceKey = 'systemTripleTeams:202610';
        $makeKey = 'systemTripleTeamsMakeTeams:202610';

        Sanctum::actingAs($this->user);
        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $sourceBefore = $this->formalSourceCache();
        $makeBefore = $this->formalMakeTeamsCache();

        Carbon::setTestNow(Carbon::now()->addDays(29));

        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');

        $this->assertTrue(Cache::has($sourceKey));
        $this->assertTrue(Cache::has($makeKey));
        $this->assertSame(
            $sourceBefore['generated_at'],
            $this->formalSourceCache()['generated_at']
        );
        $this->assertSame(
            $makeBefore['generated_at'],
            $this->formalMakeTeamsCache()['generated_at']
        );

        Carbon::setTestNow(Carbon::now()->addDay()->addSecond());

        $this->assertFalse(Cache::has($sourceKey));
        $this->assertFalse(Cache::has($makeKey));

        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');

        $this->assertTrue(Cache::has($sourceKey));
        $this->assertTrue(Cache::has($makeKey));
        $this->assertNotSame(
            $sourceBefore['generated_at'],
            $this->formalSourceCache()['generated_at']
        );
        $this->assertNotSame(
            $makeBefore['generated_at'],
            $this->formalMakeTeamsCache()['generated_at']
        );
    }

    public function test_formal_cache_hit_uses_three_source_queries_and_no_result_writes(): void
    {
        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];

        foreach ($teams as $team) {
            $this->createLink($team, 1);
        }

        $poolId = $this->createRolePool(
            $this->user->id,
            [1, 2, 3, 4, 5, 6, 7, 8, 9]
        );

        Sanctum::actingAs($this->user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $coldQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $hitQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->postJson(self::URL, ['accountId' => $poolId])
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $accountQueries = count(DB::getQueryLog());

        $this->assertSame(6, $coldQueries);
        $this->assertSame(3, $hitQueries);
        $this->assertSame(5, $accountQueries);
        $this->assertDatabaseCount('system_triple_account_caches', 0);
        DB::disableQueryLog();
    }

    public function test_source_change_rebuilds_all_five_groups_together(): void
    {
        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];
        $eyeLink = null;

        foreach ($teams as $teamIndex => $team) {
            foreach ([1, 2, 3, 4, 5] as $difficulty) {
                $link = $this->createLink($team, $difficulty);

                if ($teamIndex === 2 && $difficulty === 5) {
                    $eyeLink = $link;
                }
            }
        }

        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, ['difficulty' => 1])
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $before = $this->formalMakeTeamsCache();

        foreach ([1, 2, 3, 4, 5] as $difficulty) {
            $this->assertCount(1, $before['groups'][$difficulty]);
        }

        $eyeLink->update(['status' => 0]);
        Carbon::setTestNow(Carbon::now()->addSecond());

        $this->postJson(self::URL, ['difficulty' => 1])
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $after = $this->formalMakeTeamsCache();

        foreach ([1, 2, 3, 4] as $difficulty) {
            $this->assertCount(1, $after['groups'][$difficulty]);
        }

        $this->assertCount(0, $after['groups'][5]);
        $this->assertNotSame($before['generated_at'], $after['generated_at']);
    }

    public function test_incomplete_group_payload_is_rebuilt_as_one_complete_cache(): void
    {
        $teams = [
            $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]),
            $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]),
            $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]),
        ];

        foreach ($teams as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->user);
        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $payload = $this->formalMakeTeamsCache();
        unset($payload['groups'][5]);
        Cache::put(
            'systemTripleTeamsMakeTeams:202610',
            $payload,
            now()->addDays(30)
        );

        $this->postJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $rebuilt = $this->formalMakeTeamsCache();

        $this->assertSame([1, 2, 3, 4, 5], array_keys($rebuilt['groups']));
        $this->assertCount(1, $rebuilt['groups'][1]);
        $this->assertSame([], $rebuilt['groups'][5]);
    }

    public function test_hidden_links_do_not_count_toward_difficulty(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $third = $this->createTeam(3, 100, 1, [7 => 1, 8 => 1, 9 => 1]);

        $this->createLink($first, 1, 0);
        $this->createLink($second, 1);
        $this->createLink($third, 1);

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL, ['difficulty' => 1])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_fewer_than_three_eligible_teams_returns_empty_data(): void
    {
        $first = $this->createTeam(1, 100, 1, [1 => 1, 2 => 1, 3 => 1]);
        $second = $this->createTeam(2, 100, 1, [4 => 1, 5 => 1, 6 => 1]);
        $this->createLink($first, 1);
        $this->createLink($second, 1);

        Sanctum::actingAs($this->user);

        $this->postJson(self::URL)
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonCount(0, 'data');

        $source = $this->formalSourceCache();
        $make = $this->formalMakeTeamsCache();
        $this->assertSame(2, $source['team_count']);
        $this->assertSame([], $make['combinations']);
        $this->assertSame([], $make['groups'][1]);
    }

    /**
     * @param  array<int, Team>  $teams
     */
    private function sortedTeamIds(array $teams): string
    {
        $ids = array_map(
            static fn (Team $team): int => (int) $team->id,
            $teams
        );

        sort($ids, SORT_NUMERIC);

        return implode(',', $ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function formalSourceCache(
        string $yearMonth = '202610'
    ): array {
        $payload = Cache::get('systemTripleTeams:'.$yearMonth);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function formalMakeTeamsCache(
        string $yearMonth = '202610'
    ): array {
        $payload = Cache::get(
            'systemTripleTeamsMakeTeams:'.$yearMonth
        );
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param  array<int, int>  $roles
     */
    private function createTeam(
        int $boss,
        int $damage,
        int $atkValue,
        array $roles,
        int $uid = 0,
        int $status = 1,
        int $mode = 1,
        int $stage = 4,
        ?Carbon $createdAt = null
    ): Team {
        $this->teamSequence++;

        $team = Team::create([
            'stage' => $stage,
            'uid' => $uid,
            'boss' => $boss,
            'damage' => $damage,
            'team_key' => 'system-triple-'.$this->teamSequence,
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
        int $difficulty,
        int $status = 1
    ): TeamLink {
        return TeamLink::create([
            'team_id' => $team->id,
            'url' => 'https://example.com/team-'.$team->id.'-'.$difficulty,
            'author' => 'tester',
            'difficulty' => $difficulty,
            'damage' => 100,
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

    /**
     * @param  array<int, array<string, mixed>>  $combination
     * @return array<string, mixed>
     */
    private function findTeamById(array $combination, int $teamId): array
    {
        foreach ($combination as $team) {
            if ((int) $team['id'] === $teamId) {
                return $team;
            }
        }

        $this->fail('Team not found in combination.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $roles
     * @return array<string, mixed>
     */
    private function findRoleById(array $roles, int $roleId): array
    {
        foreach ($roles as $role) {
            if ((int) $role['role_id'] === $roleId) {
                return $role;
            }
        }

        $this->fail('Role not found in team.');
    }

    /**
     * @param  array<int, int>  $roles
     */
    private function createRolePool(
        int $uid,
        array $roles,
        int $status = 1
    ): int {
        return (int) DB::table('user_role_pools')->insertGetId([
            'uid' => $uid,
            'nickname' => 'pool-'.$uid,
            'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
            'coin' => null,
            'status' => $status,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function createTestTables(): void
    {
        foreach ([
            'system_triple_account_caches',
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

        Schema::create('system_triple_account_caches', function (Blueprint $table): void {
            $table->id();
            $table->char('year_month', 6);
            $table->unsignedTinyInteger('mode');
            $table->unsignedTinyInteger('algorithm_version')->default(1);
            $table->char('source_signature', 64);
            $table->unsignedBigInteger('uid');
            $table->unsignedBigInteger('account_id');
            $table->char('role_pool_hash', 64);
            $table->char('filter_hash', 64);
            $table->json('filters');
            $table->json('results');
            $table->unsignedTinyInteger('result_count')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique(
                [
                    'year_month',
                    'mode',
                    'algorithm_version',
                    'source_signature',
                    'uid',
                    'account_id',
                    'role_pool_hash',
                    'filter_hash',
                ],
                'uk_system_triple_account_cache_key'
            );
        });
    }
}
