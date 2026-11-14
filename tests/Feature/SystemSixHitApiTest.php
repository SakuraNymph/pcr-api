<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GameRole;
use App\Models\Team;
use App\Models\TeamLink;
use App\Models\TeamRole;
use App\Models\User;
use App\Models\UserRolePool;
use App\Services\SystemSixHitService;
use App\Services\SystemTeamSourceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemSixHitApiTest extends TestCase
{
    private const CURRENT_URL = '/api/user/game/teams/six/system';

    private const PREVIOUS_URL = '/api/user/game/teams/six/system/previous-month';

    private User $user;

    private User $otherUser;

    private User $guest;

    private Admin $admin;

    private int $teamSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));
        $this->createTestTables();

        $this->user = User::create([
            'ip' => '127.0.0.1',
            'nickname' => '正式用户',
            'email' => 'six@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $this->otherUser = User::create([
            'ip' => '127.0.0.2',
            'nickname' => '其他用户',
            'email' => 'other@example.com',
            'password' => 'secret',
            'status' => 1,
        ]);
        $this->guest = User::create([
            'ip' => '127.0.0.3',
            'nickname' => '游客',
            'email' => 'guest-six@example.com',
            'password' => 'secret',
            'status' => 0,
        ]);
        $this->admin = Admin::create([
            'username' => 'admin-six',
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

    public function test_current_and_previous_endpoints_return_three_pairs(): void
    {
        $current = $this->createSixTeams(1);
        $previous = $this->createSixTeams(
            100,
            Carbon::parse('2026-10-10 12:00:00')
        );
        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '当前账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->user);
        $currentResponse = $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertOk()->assertJsonPath('status', 0);
        $currentPackage = $currentResponse->json('data.0');

        $this->assertSame(3, count($currentPackage['pairs']));
        $this->assertSame(27, $currentPackage['score']);
        $this->assertSame(0, $currentPackage['borrow_count']);
        $this->assertSame(1, $currentPackage['pairs'][0]['low']['roles'][0]['status']);
        $this->assertSame(4, $currentPackage['pairs'][0]['high']['stage']);
        $this->assertSame(
            array_slice(array_column($current, 'id'), 0, 3),
            array_map(
                static fn (array $team): int => $team['id'],
                array_column($currentPackage['pairs'], 'low')
            )
        );

        Sanctum::actingAs($this->guest);
        $previousResponse = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertJsonPath('status', 0);
        $previousPackage = $previousResponse->json('data.0');

        $this->assertSame(3, count($previousPackage['pairs']));
        $this->assertSame(2700, $previousPackage['score']);
        $this->assertSame(
            array_slice(array_column($previous, 'id'), 0, 3),
            array_map(
                static fn (array $team): int => $team['id'],
                array_column($previousPackage['pairs'], 'low')
            )
        );
    }

    public function test_current_endpoint_enforces_registered_user_and_account_ownership(): void
    {
        $this->createSixTeams(1);
        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '当前账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);
        $otherPool = UserRolePool::create([
            'uid' => $this->otherUser->id,
            'nickname' => '其他账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);

        Auth::forgetGuards();
        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertUnauthorized();

        Sanctum::actingAs($this->guest);
        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertUnauthorized()->assertJsonPath('status', 1);

        Sanctum::actingAs($this->admin);
        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertForbidden()->assertJsonPath('status', 1);

        Sanctum::actingAs($this->user);
        $this->postJson(self::CURRENT_URL, [
            'accountId' => $otherPool->id,
        ])->assertStatus(422)->assertJsonValidationErrors('accountId');
    }

    public function test_previous_endpoint_rejects_account_id_and_allows_guest(): void
    {
        $this->createSixTeams(
            1,
            Carbon::parse('2026-10-10 12:00:00')
        );

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_URL, [
            'accountId' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('accountId');

        $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_difficulty_validation_rejects_out_of_range_values(): void
    {
        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '难度校验账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
            'difficulty' => 6,
        ])->assertStatus(422)->assertJsonValidationErrors('difficulty');

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_URL, [
            'difficulty' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors('difficulty');
    }

    public function test_previous_month_difficulty_filters_only_high_stage_and_ignores_mode(): void
    {
        $createdAt = Carbon::parse('2026-10-10 12:00:00');

        foreach ([
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ] as $index => $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                mode: 1,
                stage: $index === 2 ? 3 : 2,
                createdAt: $createdAt
            );
        }

        $highTeams = [];

        foreach ([
            [10 => 1, 11 => 1, 12 => 1],
            [13 => 1, 14 => 1, 15 => 1],
            [16 => 1, 17 => 1, 18 => 1],
        ] as $roles) {
            $highTeams[] = $this->createTeam(
                1,
                10,
                1,
                $roles,
                mode: 1,
                stage: 4,
                createdAt: $createdAt
            );
        }

        foreach ($highTeams as $team) {
            $this->createLink($team, 2);
        }

        Sanctum::actingAs($this->guest);
        $package = $this->postJson(self::PREVIOUS_URL, [
            'difficulty' => 2,
        ])->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame(
            [2, 2, 3],
            array_column(array_column($package['pairs'], 'low'), 'stage')
        );
        $this->assertSame(
            [4, 4, 4],
            array_column(array_column($package['pairs'], 'high'), 'stage')
        );

        $this->postJson(self::PREVIOUS_URL, [
            'difficulty' => 1,
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_difficulty_filters_high_stage_only_and_ignores_teams_mode(): void
    {
        $createdAt = Carbon::parse('2026-10-10 12:00:00');
        $lowTeams = [];

        foreach ([
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ] as $index => $roles) {
            $lowTeams[] = $this->createTeam(
                1,
                10,
                1,
                $roles,
                mode: $index % 2 + 1,
                stage: $index === 2 ? 3 : 2,
                createdAt: $createdAt
            );
        }

        foreach ($lowTeams as $team) {
            $this->createLink($team, 5);
        }

        $highTeams = [];

        foreach ([
            [10 => 1, 11 => 1, 12 => 1],
            [13 => 1, 14 => 1, 15 => 1],
            [16 => 1, 17 => 1, 18 => 1],
        ] as $index => $roles) {
            $highTeams[] = $this->createTeam(
                1,
                10,
                1,
                $roles,
                mode: $index % 2 + 1,
                stage: 4,
                createdAt: $createdAt
            );
        }

        foreach ($highTeams as $team) {
            $this->createLink($team, 1);
        }

        Sanctum::actingAs($this->guest);
        $package = $this->postJson(self::PREVIOUS_URL, [
            'difficulty' => 1,
        ])->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame(
            [2, 2, 3],
            array_column(array_column($package['pairs'], 'low'), 'stage')
        );
        $this->assertSame(
            [4, 4, 4],
            array_column(array_column($package['pairs'], 'high'), 'stage')
        );
    }

    public function test_difficulty_requires_all_three_high_stage_teams(): void
    {
        $createdAt = Carbon::parse('2026-10-10 12:00:00');

        foreach ([
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ] as $index => $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: $index === 2 ? 3 : 2,
                createdAt: $createdAt
            );
        }

        foreach ([
            [10 => 1, 11 => 1, 12 => 1],
            [13 => 1, 14 => 1, 15 => 1],
            [16 => 1, 17 => 1, 18 => 1],
        ] as $index => $roles) {
            $team = $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: 4,
                createdAt: $createdAt
            );
            $this->createLink($team, $index < 2 ? 1 : 2);
        }

        Sanctum::actingAs($this->guest);

        $this->postJson(self::PREVIOUS_URL, ['difficulty' => 1])
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->postJson(self::PREVIOUS_URL, ['difficulty' => 2])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_expands_candidate_limit_when_five_per_side_is_not_enough(): void
    {
        $createdAt = Carbon::parse('2026-10-10 12:00:00');
        $badLowTeams = [
            [100 => 0, 101 => 0, 102 => 1],
            [103 => 0, 104 => 0, 105 => 1],
            [106 => 0, 107 => 0, 108 => 1],
        ];
        $goodLowTeams = [
            [109 => 1, 110 => 1, 111 => 1],
            [112 => 1, 113 => 1, 114 => 1],
            [115 => 1, 116 => 1, 117 => 1],
        ];

        foreach ($badLowTeams as $roles) {
            $this->createTeam(
                1,
                100,
                1,
                $roles,
                stage: 2,
                createdAt: $createdAt
            );
        }

        foreach ($goodLowTeams as $roles) {
            $this->createTeam(
                1,
                1,
                1,
                $roles,
                stage: 2,
                createdAt: $createdAt
            );
        }

        for ($index = 0; $index < 6; $index++) {
            $start = 200 + $index * 3;
            $this->createTeam(
                1,
                1,
                1,
                [
                    $start => 1,
                    $start + 1 => 1,
                    $start + 2 => 1,
                ],
                stage: 4,
                createdAt: $createdAt
            );
        }

        Sanctum::actingAs($this->guest);
        $packages = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->json('data');

        $this->assertCount(10, $packages);
    }

    public function test_current_missing_role_is_borrowed_and_status_is_account_aware(): void
    {
        $this->createSixTeams(1);
        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '缺一个角色',
            'roles' => array_values(array_diff(range(1, 18), [10])),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->user);
        $package = $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertOk()->json('data.0');

        $this->assertSame(1, $package['borrow_count']);
        $this->assertSame(
            10,
            $package['pairs'][0]['high']['borrow']
        );
        $this->assertSame(
            0,
            $package['pairs'][0]['high']['roles'][0]['status']
        );
    }

    public function test_previous_month_allows_same_pair_overlap_and_borrows_cross_pair_conflict(): void
    {
        $low = [
            [1 => 1, 2 => 1, 3 => 1],
            [1 => 1, 4 => 1, 5 => 1],
            [6 => 1, 7 => 1, 8 => 1],
        ];
        $high = [
            [1 => 1, 9 => 1, 10 => 1],
            [11 => 1, 12 => 1, 13 => 1],
            [14 => 1, 15 => 1, 16 => 1],
        ];

        foreach ($low as $index => $roles) {
            $this->createTeam(
                1,
                100,
                1,
                $roles,
                stage: $index === 2 ? 3 : 2,
                createdAt: Carbon::parse('2026-10-10 12:00:00')
            );
        }

        foreach ($high as $roles) {
            $this->createTeam(
                1,
                100,
                1,
                $roles,
                stage: 4,
                createdAt: Carbon::parse('2026-10-11 12:00:00')
            );
        }

        Sanctum::actingAs($this->guest);
        $package = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->json('data.0');

        $this->assertSame(3, count($package['pairs']));
        $this->assertGreaterThan(0, $package['overlap']);
        $this->assertGreaterThanOrEqual(1, $package['borrow_count']);
    }

    public function test_filters_and_cache_reuse(): void
    {
        $this->createSixTeams(1);
        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '筛选账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->user);
        $first = $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
            'lowBosses' => [1],
            'highBosses' => [1],
        ])->assertOk()->assertJsonCount(1, 'data')->json('data');
        $second = $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
            'lowBosses' => [1],
            'highBosses' => [1],
        ])->assertOk()->assertJsonCount(1, 'data')->json('data');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->sixHitCachePayloads());

        $third = $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
            'lowBosses' => [1],
            'highBosses' => [1],
        ])->assertOk()->assertJsonCount(1, 'data')->json('data');

        $this->assertSame($first, $third);
        $this->assertCount(1, $this->sixHitCachePayloads());
    }

    public function test_result_cache_key_covers_all_computation_inputs(): void
    {
        $service = $this->app->make(SystemSixHitService::class);
        $method = new \ReflectionMethod($service, 'resultCacheKey');
        $algorithmVersion = (new \ReflectionClassConstant(
            SystemSixHitService::class,
            'ALGORITHM_VERSION'
        ))->getValue();
        $sourceSignature = str_repeat('a', 64);
        $rolePoolHash = hash('sha256', 'roles:1,2,3');
        $filterHash = hash('sha256', 'filters:default');
        $arguments = [
            '202611',
            3,
            $sourceSignature,
            1,
            7,
            9,
            $rolePoolHash,
            $filterHash,
        ];
        $key = $method->invokeArgs($service, $arguments);
        $expected = hash('sha256', json_encode([
            'year_month' => '202611',
            'difficulty' => 3,
            'source_signature' => $sourceSignature,
            'endpoint' => 1,
            'uid' => 7,
            'account_id' => 9,
            'role_pool_hash' => $rolePoolHash,
            'filter_hash' => $filterHash,
            'algorithm_version' => $algorithmVersion,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame($expected, $key);

        $variants = [
            ['202612', 3, $sourceSignature, 1, 7, 9, $rolePoolHash, $filterHash],
            ['202611', 4, $sourceSignature, 1, 7, 9, $rolePoolHash, $filterHash],
            ['202611', 3, str_repeat('b', 64), 1, 7, 9, $rolePoolHash, $filterHash],
            ['202611', 3, $sourceSignature, 2, null, null, null, $filterHash],
            ['202611', 3, $sourceSignature, 1, 8, 9, $rolePoolHash, $filterHash],
            ['202611', 3, $sourceSignature, 1, 7, 10, $rolePoolHash, $filterHash],
            [
                '202611',
                3,
                $sourceSignature,
                1,
                7,
                9,
                hash('sha256', 'roles:other'),
                $filterHash,
            ],
            [
                '202611',
                3,
                $sourceSignature,
                1,
                7,
                9,
                $rolePoolHash,
                hash('sha256', 'filters:other'),
            ],
        ];

        foreach ($variants as $variant) {
            $this->assertNotSame($key, $method->invokeArgs($service, $variant));
        }

        $differentVersionKey = hash('sha256', json_encode([
            'year_month' => '202611',
            'difficulty' => 3,
            'source_signature' => $sourceSignature,
            'endpoint' => 1,
            'uid' => 7,
            'account_id' => 9,
            'role_pool_hash' => $rolePoolHash,
            'filter_hash' => $filterHash,
            'algorithm_version' => $algorithmVersion + 1,
        ], JSON_THROW_ON_ERROR));

        $this->assertNotSame($key, $differentVersionKey);
    }

    public function test_six_hit_results_are_stored_only_in_laravel_cache(): void
    {
        $this->createSixTeams(1);
        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '纯缓存账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertOk()->assertJsonCount(1, 'data');

        $this->assertCount(1, $this->sixHitCachePayloads());
    }

    public function test_locked_ids_limit_two_per_stage_and_hidden_ids_are_unlimited(): void
    {
        $teams = $this->createSixTeams(
            1,
            Carbon::parse('2026-10-10 12:00:00')
        );
        $lowIds = array_map(
            static fn (Team $team): int => (int) $team->id,
            array_slice($teams, 0, 3)
        );
        $highIds = array_map(
            static fn (Team $team): int => (int) $team->id,
            array_slice($teams, 3, 3)
        );

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_URL, [
            'lockedIds' => [
                $lowIds[0],
                $lowIds[1],
                $highIds[0],
                $highIds[1],
            ],
        ])->assertOk()->assertJsonCount(1, 'data');

        $this->postJson(self::PREVIOUS_URL, [
            'lockedIds' => $lowIds,
        ])->assertStatus(422)->assertJsonValidationErrors('lockedIds');

        $this->postJson(self::PREVIOUS_URL, [
            'lockedIds' => $highIds,
        ])->assertStatus(422)->assertJsonValidationErrors('lockedIds');

        $manyHiddenIds = range(9000, 9100);
        $this->postJson(self::PREVIOUS_URL, [
            'hiddenIds' => $manyHiddenIds,
        ])->assertOk()->assertJsonCount(1, 'data');

        $this->postJson(self::PREVIOUS_URL, [
            'hiddenIds' => array_merge([$lowIds[0]], $manyHiddenIds),
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_locked_and_hidden_ids_filter_candidate_triples(): void
    {
        $lowRoles = [];
        $highRoles = [];

        for ($index = 0; $index < 4; $index++) {
            $start = $index * 3 + 1;
            $lowRoles[] = [
                $start => 1,
                $start + 1 => 1,
                $start + 2 => 1,
            ];
            $highStart = 13 + $index * 3;
            $highRoles[] = [
                $highStart => 1,
                $highStart + 1 => 1,
                $highStart + 2 => 1,
            ];
        }

        $teams = $this->createTripleScenario(
            $lowRoles,
            $highRoles,
            Carbon::parse('2026-10-10 12:00:00')
        );
        $lowIds = array_map(
            static fn (Team $team): int => (int) $team->id,
            array_slice($teams, 0, 4)
        );
        $highIds = array_map(
            static fn (Team $team): int => (int) $team->id,
            array_slice($teams, 4, 4)
        );
        $lockedIds = [
            $lowIds[0],
            $lowIds[1],
            $highIds[0],
            $highIds[1],
        ];

        Sanctum::actingAs($this->guest);
        $packages = $this->postJson(self::PREVIOUS_URL, [
            'lockedIds' => $lockedIds,
        ])->assertOk()->json('data');

        $this->assertNotEmpty($packages);

        foreach ($packages as $package) {
            $teamIds = $this->packageTeamIds($package);

            foreach ($lockedIds as $lockedId) {
                $this->assertContains($lockedId, $teamIds);
            }
        }

        $hiddenPackages = $this->postJson(self::PREVIOUS_URL, [
            'hiddenIds' => [$lowIds[0]],
        ])->assertOk()->json('data');

        $this->assertNotEmpty($hiddenPackages);

        foreach ($hiddenPackages as $package) {
            $this->assertNotContains(
                $lowIds[0],
                $this->packageTeamIds($package)
            );
        }
    }

    public function test_current_locked_and_hidden_filters(): void
    {
        $teams = $this->createSixTeams(1);
        $lowIds = array_map(
            static fn (Team $team): int => (int) $team->id,
            array_slice($teams, 0, 3)
        );
        $highIds = array_map(
            static fn (Team $team): int => (int) $team->id,
            array_slice($teams, 3, 3)
        );
        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '锁定账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
            'lockedIds' => [
                $lowIds[0],
                $lowIds[1],
                $highIds[0],
                $highIds[1],
            ],
        ])->assertOk()->assertJsonCount(1, 'data');

        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
            'hiddenIds' => [$lowIds[0]],
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_previous_month_evaluates_all_six_permutations(): void
    {
        $this->createTripleScenario(
            [
                [1 => 1, 2 => 1, 3 => 1],
                [4 => 1, 5 => 1, 6 => 1],
                [7 => 1, 8 => 1, 9 => 1],
            ],
            [
                [1 => 1, 10 => 1, 11 => 1],
                [4 => 1, 12 => 1, 13 => 1],
                [7 => 1, 14 => 1, 15 => 1],
            ],
            Carbon::parse('2026-10-10 12:00:00')
        );

        Sanctum::actingAs($this->guest);
        $package = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->json('data.0');

        $this->assertSame(3, $package['overlap']);
        $this->assertSame(0, $package['permutation_index']);
        $this->assertSame(0, $package['borrow_count']);

        $payloads = $this->sixHitCachePayloads();
        $this->assertCount(1, $payloads);
        $checks = $payloads[0][0]['permutation_checks'];

        $this->assertCount(6, $checks);
        $this->assertSame(
            [3, 1, 1, 0, 0, 1],
            array_column($checks, 'overlap')
        );
        $this->assertSame(
            [true, true, true, true, true, true],
            array_column($checks, 'public_valid')
        );
    }

    public function test_previous_month_keeps_searching_after_a_permutation_fails(): void
    {
        $this->createTripleScenario(
            [
                [1 => 1, 2 => 1, 3 => 1],
                [4 => 1, 5 => 1, 6 => 1],
                [7 => 1, 8 => 1, 9 => 1],
            ],
            [
                [1 => 1, 2 => 1, 10 => 0],
                [11 => 1, 12 => 1, 13 => 1],
                [14 => 1, 15 => 1, 16 => 1],
            ],
            Carbon::parse('2026-10-10 12:00:00')
        );

        Sanctum::actingAs($this->guest);
        $package = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->json('data.0');

        $this->assertSame(2, $package['overlap']);
        $this->assertSame(0, $package['permutation_index']);
        $this->assertSame(1, $package['borrow_count']);

        $payloads = $this->sixHitCachePayloads();
        $this->assertCount(1, $payloads);
        $checks = $payloads[0][0]['permutation_checks'];

        $this->assertSame(
            [true, true, false, false, false, false],
            array_column($checks, 'public_valid')
        );
    }

    public function test_previous_month_returns_and_caches_empty_when_all_permutations_fail(): void
    {
        $this->createTripleScenario(
            [
                [1 => 1, 2 => 1, 3 => 1],
                [4 => 1, 5 => 1, 6 => 1],
                [7 => 1, 8 => 1, 9 => 1],
            ],
            [
                [1 => 0, 2 => 0, 10 => 1],
                [11 => 1, 12 => 1, 13 => 1],
                [14 => 1, 15 => 1, 16 => 1],
            ],
            Carbon::parse('2026-10-10 12:00:00')
        );

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertExactJson([
                'status' => 0,
                'code' => 200,
                'msg' => '获取上月系统六刀组合成功',
                'data' => [],
            ]);
        $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $payloads = $this->sixHitCachePayloads();
        $this->assertCount(1, $payloads);
        $this->assertSame([], $payloads[0]);
    }

    public function test_same_role_in_three_pairs_uses_three_borrows(): void
    {
        $this->createTripleScenario(
            [
                [1 => 1, 2 => 1, 3 => 1],
                [1 => 1, 4 => 1, 5 => 1],
                [1 => 1, 6 => 1, 7 => 1],
            ],
            [
                [1 => 1, 8 => 1, 9 => 1],
                [1 => 1, 10 => 1, 11 => 1],
                [1 => 1, 12 => 1, 13 => 1],
            ],
            Carbon::parse('2026-10-10 12:00:00')
        );

        Sanctum::actingAs($this->guest);
        $package = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->json('data.0');

        $this->assertSame(3, $package['overlap']);
        $this->assertSame(4, $package['borrow_count']);

        $borrowedPairCounts = [];

        foreach ($package['pairs'] as $pair) {
            $borrowedRoles = array_filter([
                $pair['low']['borrow'],
                $pair['high']['borrow'],
            ]);

            $borrowedPairCounts[] = count($borrowedRoles);
        }

        sort($borrowedPairCounts);
        $this->assertSame([0, 2, 2], $borrowedPairCounts);
    }

    public function test_current_role_pool_hash_isolates_account_results(): void
    {
        $this->createSixTeams(1);
        $fullPool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '完整角色池',
            'roles' => range(1, 18),
            'status' => 1,
        ]);
        $missingPool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '缺失角色池',
            'roles' => array_values(array_diff(range(1, 18), [10])),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->user);
        $fullPackage = $this->postJson(self::CURRENT_URL, [
            'accountId' => $fullPool->id,
        ])->assertOk()->json('data.0');
        $missingPackage = $this->postJson(self::CURRENT_URL, [
            'accountId' => $missingPool->id,
        ])->assertOk()->json('data.0');

        $this->assertSame(0, $fullPackage['borrow_count']);
        $this->assertSame(1, $missingPackage['borrow_count']);
        $this->assertSame(
            10,
            $this->findTeamWithRole($missingPackage, 10)['borrow']
        );
        $this->assertCount(2, $this->sixHitCachePayloads());

        $missingPool->update(['roles' => range(1, 18)]);
        $updatedPackage = $this->postJson(self::CURRENT_URL, [
            'accountId' => $missingPool->id,
        ])->assertOk()->json('data.0');

        $this->assertSame(0, $updatedPackage['borrow_count']);
        $this->assertCount(3, $this->sixHitCachePayloads());
    }

    public function test_previous_month_does_not_query_user_role_pools(): void
    {
        $this->createTripleScenario(
            [
                [1 => 1, 2 => 1, 3 => 1],
                [4 => 1, 5 => 1, 6 => 1],
                [7 => 1, 8 => 1, 9 => 1],
            ],
            [
                [10 => 1, 11 => 1, 12 => 1],
                [13 => 1, 14 => 1, 15 => 1],
                [16 => 1, 17 => 1, 18 => 1],
            ],
            Carbon::parse('2026-10-10 12:00:00')
        );

        Sanctum::actingAs($this->guest);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson(self::PREVIOUS_URL)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'user_role_pools',
                $query['query']
            );
        }
    }

    public function test_difficulty_stage_attack_and_repeated_boss_filters(): void
    {
        $lowRoles = [
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ];
        $highRoles = [
            [10 => 1, 11 => 1, 12 => 1],
            [13 => 1, 14 => 1, 15 => 1],
            [16 => 1, 17 => 1, 18 => 1],
        ];
        $createdAt = Carbon::parse('2026-10-10 12:00:00');

        foreach ($lowRoles as $index => $roles) {
            $this->createTeam(
                $index < 2 ? 1 : 2,
                10,
                1,
                $roles,
                mode: 1,
                stage: $index < 2 ? 2 : 3,
                createdAt: $createdAt
            );
        }

        $highTeams = [];

        foreach ($highRoles as $index => $roles) {
            $highTeams[] = $this->createTeam(
                2,
                10,
                $index < 2 ? 1 : -1,
                $roles,
                mode: 2,
                stage: 4,
                createdAt: $createdAt
            );
        }

        foreach ($highTeams as $team) {
            $this->createLink($team, 2);
        }

        Sanctum::actingAs($this->guest);
        $baseFilters = [
            'lowBosses' => [1, 1],
            'highBosses' => [2, 2, 2],
            'difficulty' => 2,
            'atkType' => 2,
        ];

        $this->postJson(self::PREVIOUS_URL, $baseFilters)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->postJson(self::PREVIOUS_URL, [
            ...$baseFilters,
            'stage' => 2,
        ])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::PREVIOUS_URL, [
            ...$baseFilters,
            'stage' => 3,
        ])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::PREVIOUS_URL, [
            ...$baseFilters,
            'atkType' => 1,
        ])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::PREVIOUS_URL, [
            ...$baseFilters,
            'lowBosses' => [1, 1, 1],
        ])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::PREVIOUS_URL, [
            ...$baseFilters,
            'difficulty' => 1,
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_current_month_difficulty_filters_only_high_stage(): void
    {
        $createdAt = Carbon::parse('2026-11-10 12:00:00');

        foreach ([
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ] as $index => $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                mode: 1,
                stage: $index === 2 ? 3 : 2,
                createdAt: $createdAt
            );
        }

        $highTeams = [];

        foreach ([
            [10 => 1, 11 => 1, 12 => 1],
            [13 => 1, 14 => 1, 15 => 1],
            [16 => 1, 17 => 1, 18 => 1],
        ] as $roles) {
            $highTeams[] = $this->createTeam(
                1,
                10,
                1,
                $roles,
                mode: 1,
                stage: 4,
                createdAt: $createdAt
            );
        }

        foreach ($highTeams as $team) {
            $this->createLink($team, 2);
        }

        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '模式账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->user);
        $package = $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
            'difficulty' => 2,
        ])->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame(
            [2, 2, 3],
            array_column(array_column($package['pairs'], 'low'), 'stage')
        );
        $this->assertSame(
            [4, 4, 4],
            array_column(array_column($package['pairs'], 'high'), 'stage')
        );

        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
            'difficulty' => 1,
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cache_invalidates_on_team_changes_without_affecting_previous_month(): void
    {
        $current = $this->createSixTeams(1);
        $this->createSixTeams(
            100,
            Carbon::parse('2026-10-10 12:00:00')
        );
        $pool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '缓存账号',
            'roles' => range(1, 18),
            'status' => 1,
        ]);

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_URL)->assertOk();
        $previousPayloads = $this->sixHitCachePayloads();
        $this->assertCount(1, $previousPayloads);
        $previousResults = $previousPayloads[0];

        Sanctum::actingAs($this->user);
        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertOk();
        $this->assertCount(2, $this->sixHitCachePayloads());

        $current[0]->update(['damage' => 2]);
        app(SystemTeamSourceService::class)->forgetMonth(
            $current[0]->created_at->format('Ym')
        );
        $damagePackage = $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertOk()->json('data.0');
        $this->assertSame(31.5, $damagePackage['score']);
        $this->assertCount(3, $this->sixHitCachePayloads());

        TeamRole::query()
            ->where('team_id', $current[1]->id)
            ->where('role_id', 4)
            ->delete();
        $this->ensureGameRole(20);
        TeamRole::create([
            'team_id' => $current[1]->id,
            'role_id' => 20,
            'status' => 1,
        ]);
        app(SystemTeamSourceService::class)->forgetMonth(
            $current[1]->created_at->format('Ym')
        );

        $this->postJson(self::CURRENT_URL, [
            'accountId' => $pool->id,
        ])->assertOk()->assertJsonCount(1, 'data');
        $this->assertCount(4, $this->sixHitCachePayloads());

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_URL)->assertOk();
        $payloads = $this->sixHitCachePayloads();

        $this->assertSame(
            $previousResults,
            $payloads[0]
        );
        $this->assertCount(4, $payloads);
    }

    public function test_source_rows_are_cached_in_separate_low_and_high_pools(): void
    {
        $this->createSixTeams(
            1,
            Carbon::parse('2026-10-10 12:00:00')
        );

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_URL)->assertOk();

        $low = Cache::get('system-source:rows:v1:202610:low');
        $high = Cache::get('system-source:rows:v1:202610:high');

        $this->assertIsArray($low);
        $this->assertIsArray($high);
        $this->assertSame([2, 3], $low['stages']);
        $this->assertSame([4], $high['stages']);
        $this->assertSame(
            [2, 3],
            array_values(array_unique(
                array_column($low['rows']['teams'], 'stage')
            ))
        );
        $this->assertSame(
            [4],
            array_values(array_unique(
                array_column($high['rows']['teams'], 'stage')
            ))
        );

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson(self::PREVIOUS_URL)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(teams|team_roles|team_links)\b/',
                (string) ($query['query'] ?? '')
            );
        }
    }

    public function test_returns_empty_when_high_stage_has_no_triple(): void
    {
        $createdAt = Carbon::parse('2026-10-10 12:00:00');

        foreach ([
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ] as $index => $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: $index < 2 ? 2 : 3,
                createdAt: $createdAt
            );
        }

        Sanctum::actingAs($this->guest);
        $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_low_stage_supports_b_only_c_only_and_mixed_triples(): void
    {
        $createdAt = Carbon::parse('2026-10-10 12:00:00');
        $lowB = [
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ];
        $lowC = [
            [19 => 1, 20 => 1, 21 => 1],
            [22 => 1, 23 => 1, 24 => 1],
            [25 => 1, 26 => 1, 27 => 1],
        ];
        $high = [
            [10 => 1, 11 => 1, 12 => 1],
            [13 => 1, 14 => 1, 15 => 1],
            [16 => 1, 17 => 1, 18 => 1],
        ];

        foreach ($lowB as $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: 2,
                createdAt: $createdAt
            );
        }

        foreach ($lowC as $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: 3,
                createdAt: $createdAt
            );
        }

        foreach ($high as $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: 4,
                createdAt: $createdAt
            );
        }

        Sanctum::actingAs($this->guest);
        $bOnly = $this->postJson(self::PREVIOUS_URL, [
            'stage' => 2,
        ])->assertOk()->json('data.0');
        $this->assertSame(
            [2, 2, 2],
            array_column(array_column($bOnly['pairs'], 'low'), 'stage')
        );

        $cOnly = $this->postJson(self::PREVIOUS_URL, [
            'stage' => 3,
        ])->assertOk()->json('data.0');
        $this->assertSame(
            [3, 3, 3],
            array_column(array_column($cOnly['pairs'], 'low'), 'stage')
        );

        $mixedPackages = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->json('data');
        $stagePatterns = array_map(static function (array $package): string {
            $stages = array_column(
                array_column($package['pairs'], 'low'),
                'stage'
            );
            sort($stages);

            return implode(',', $stages);
        }, $mixedPackages);

        $this->assertContains('2,2,3', $stagePatterns);
        $this->assertContains('2,3,3', $stagePatterns);
    }

    public function test_low_stage_without_difficulty_links_remains_eligible(): void
    {
        $createdAt = Carbon::parse('2026-10-10 12:00:00');
        $lowB = [
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ];
        $lowC = [
            [19 => 1, 20 => 1, 21 => 1],
            [22 => 1, 23 => 1, 24 => 1],
            [25 => 1, 26 => 1, 27 => 1],
        ];
        $high = [
            [10 => 1, 11 => 1, 12 => 1],
            [13 => 1, 14 => 1, 15 => 1],
            [16 => 1, 17 => 1, 18 => 1],
        ];

        foreach ($lowB as $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: 2,
                createdAt: $createdAt
            );
        }

        foreach ($lowC as $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: 3,
                createdAt: $createdAt
            );
        }

        foreach ($high as $roles) {
            $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: 4,
                createdAt: $createdAt
            );
        }

        Sanctum::actingAs($this->guest);
        $packages = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($packages);
        $bOnly = $this->postJson(self::PREVIOUS_URL, ['stage' => 2])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');
        $cOnly = $this->postJson(self::PREVIOUS_URL, ['stage' => 3])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(
            [2, 2, 2],
            array_column(array_column($bOnly['pairs'], 'low'), 'stage')
        );
        $this->assertSame(
            [3, 3, 3],
            array_column(array_column($cOnly['pairs'], 'low'), 'stage')
        );
    }

    public function test_result_limit_is_ten_and_tie_order_is_stable(): void
    {
        $lowRoles = [];
        $highRoles = [];

        for ($index = 0; $index < 5; $index++) {
            $start = $index * 3 + 1;
            $lowRoles[] = [
                $start => 1,
                $start + 1 => 1,
                $start + 2 => 1,
            ];
        }

        for ($index = 0; $index < 4; $index++) {
            $start = 16 + $index * 3;
            $highRoles[] = [
                $start => 1,
                $start + 1 => 1,
                $start + 2 => 1,
            ];
        }

        $this->createTripleScenario(
            $lowRoles,
            $highRoles,
            Carbon::parse('2026-10-10 12:00:00')
        );

        Sanctum::actingAs($this->guest);
        $first = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->json('data');
        $second = $this->postJson(self::PREVIOUS_URL)
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->json('data');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->sixHitCachePayloads());
    }

    /**
     * @return array<int, Team>
     */
    private function createSixTeams(
        int $damage,
        ?Carbon $createdAt = null
    ): array {
        $lowRoles = [
            [1 => 1, 2 => 1, 3 => 1],
            [4 => 1, 5 => 1, 6 => 1],
            [7 => 1, 8 => 1, 9 => 1],
        ];
        $highRoles = [
            [10 => 1, 11 => 1, 12 => 1],
            [13 => 1, 14 => 1, 15 => 1],
            [16 => 1, 17 => 1, 18 => 1],
        ];
        $teams = [];

        foreach ($lowRoles as $index => $roles) {
            $teams[] = $this->createTeam(
                1,
                $damage,
                1,
                $roles,
                stage: $index === 2 ? 3 : 2,
                createdAt: $createdAt
            );
        }

        foreach ($highRoles as $roles) {
            $teams[] = $this->createTeam(
                1,
                $damage,
                1,
                $roles,
                stage: 4,
                createdAt: $createdAt
            );
        }

        return $teams;
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function sixHitCachePayloads(): array
    {
        $store = Cache::store()->getStore();
        $this->assertInstanceOf(\Illuminate\Cache\ArrayStore::class, $store);
        $property = new \ReflectionProperty($store, 'storage');
        $property->setAccessible(true);
        $payloads = [];

        foreach ($property->getValue($store) as $key => $entry) {
            if (! str_contains((string) $key, 'six-hit:final:v1:')) {
                continue;
            }

            $payload = $entry['value'] ?? null;

            if (is_array($payload)) {
                $payloads[] = $payload;
            }
        }

        return array_values($payloads);
    }

    /**
     * @param  array<int, array<int, int>>  $lowRoles
     * @param  array<int, array<int, int>>  $highRoles
     * @return array<int, Team>
     */
    private function createTripleScenario(
        array $lowRoles,
        array $highRoles,
        ?Carbon $createdAt = null
    ): array {
        $teams = [];

        foreach ($lowRoles as $index => $roles) {
            $teams[] = $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: $index === 2 ? 3 : 2,
                createdAt: $createdAt
            );
        }

        foreach ($highRoles as $roles) {
            $teams[] = $this->createTeam(
                1,
                10,
                1,
                $roles,
                stage: 4,
                createdAt: $createdAt
            );
        }

        return $teams;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function findTeamWithRole(array $package, int $roleId): array
    {
        foreach ($package['pairs'] as $pair) {
            foreach (['low', 'high'] as $side) {
                $team = $pair[$side];

                foreach ($team['roles'] as $role) {
                    if ((int) $role['role_id'] === $roleId) {
                        return $team;
                    }
                }
            }
        }

        $this->fail("未找到包含角色 {$roleId} 的作业");
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<int, int>
     */
    private function packageTeamIds(array $package): array
    {
        $teamIds = [];

        foreach ($package['pairs'] as $pair) {
            $teamIds[] = (int) $pair['low']['id'];
            $teamIds[] = (int) $pair['high']['id'];
        }

        return $teamIds;
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
            'team_key' => 'system-six-'.$this->teamSequence,
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
            'url' => 'https://example.com/six-'.$team->id.'-'.$difficulty,
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

    private function createTestTables(): void
    {
        foreach ([
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

    }
}
