<?php

namespace Tests\Feature;

use App\Models\CardStatistic;
use App\Models\GameRole;
use App\Models\User;
use App\Models\UserRolePool;
use App\Services\CardStatisticsRoleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserCardStatisticsApiTest extends TestCase
{
    private User $user;

    private User $guest;

    private UserRolePool $pool;

    private UserRolePool $secondPool;

    private UserRolePool $otherPool;

    private UserRolePool $deletedPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();
        Cache::flush();

        $this->user = $this->createUser('owner@example.com', '账号主人', 1);
        $this->guest = $this->createUser('guest@example.com', '游客', 0);
        $otherUser = $this->createUser('other@example.com', '其他用户', 1);

        $this->pool = $this->createPool($this->user, '主账号');
        $this->secondPool = $this->createPool($this->user, '小号');
        $this->otherPool = $this->createPool($otherUser, '别人的账号');
        $this->deletedPool = $this->createPool($this->user, '已删除账号', 0);

        $this->createGameRole(101, '2025 年末角色', '2025-12-20', 1);
        $this->createGameRole(102, '2026 角色', '2026-01-10', 2, 1, 1, 6032);
        $this->createGameRole(103, '停用角色', '2025-06-01', 1, 0);
        $this->createGameRole(104, '非抽卡角色', '2025-07-01', 1, 1, 2);
        $this->createGameRole(105, '无卡池日期角色', null, 1);
        $this->createGameRole(106, '2025 春季角色', '2025-03-01', 2);
        $this->createGameRole(107, '2024 角色', '2024-11-01', 1);

        Sanctum::actingAs($this->user);
    }

    public function test_guest_and_unauthenticated_users_are_rejected(): void
    {
        Sanctum::actingAs($this->guest);

        $this->getJson($this->rolesUrl())
            ->assertUnauthorized()
            ->assertJsonPath('status', 1);

        $this->putJson('/api/user/card-statistics', $this->savePayload())
            ->assertUnauthorized()
            ->assertJsonPath('status', 1);

        Auth::forgetGuards();

        $this->getJson($this->rolesUrl())->assertUnauthorized();
        $this->putJson('/api/user/card-statistics', $this->savePayload())
            ->assertUnauthorized();
    }

    public function test_account_must_belong_to_the_current_user(): void
    {
        $this->getJson($this->rolesUrl($this->otherPool->id))
            ->assertNotFound()
            ->assertJsonPath('status', 1)
            ->assertJsonPath('msg', '游戏账号不存在或不属于当前用户');

        $this->putJson('/api/user/card-statistics', [
            'account_id' => $this->otherPool->id,
            'year' => 2025,
            'data' => [],
        ])
            ->assertNotFound()
            ->assertJsonPath('msg', '游戏账号不存在或不属于当前用户');

        $this->getJson($this->rolesUrl($this->deletedPool->id))
            ->assertNotFound();
    }

    public function test_roles_are_filtered_sorted_and_default_to_the_latest_year(): void
    {
        $this->getJson($this->rolesUrl())
            ->assertOk()
            ->assertJsonPath('data.available_years', [2026, 2025, 2024])
            ->assertJsonPath('data.year', 2026)
            ->assertJsonCount(1, 'data.roles')
            ->assertJsonPath('data.roles.0.id', 102)
            ->assertJsonPath('data.roles.0.image_id', 6032)
            ->assertJsonPath(
                'data.roles.0.image_url',
                url('images/roles/6032.webp')
            )
            ->assertJsonPath('data.roles.0.saved_count', null)
            ->assertJsonPath('data.roles.0.evaluation', null);

        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertOk()
            ->assertJsonCount(2, 'data.roles')
            ->assertJsonPath('data.roles.0.id', 106)
            ->assertJsonPath('data.roles.1.id', 101);
    }

    public function test_saved_zero_is_returned_as_zero_and_missing_values_as_null(): void
    {
        CardStatistic::create([
            'uid' => $this->user->id,
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 0],
                ['id' => 106, 'count' => 200],
            ],
        ]);

        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertOk()
            ->assertJsonPath('data.roles.0.id', 106)
            ->assertJsonPath('data.roles.0.saved_count', 200)
            ->assertJsonPath('data.roles.0.evaluation.title', '保底之王')
            ->assertJsonPath('data.roles.0.evaluation.level', 8)
            ->assertJsonPath('data.roles.1.id', 101)
            ->assertJsonPath('data.roles.1.saved_count', 0)
            ->assertJsonPath('data.roles.1.evaluation.title', '暂无评价')
            ->assertJsonPath('data.roles.1.evaluation.level', 0);
    }

    public function test_role_cache_hits_and_can_be_invalidated(): void
    {
        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertJsonPath('data.roles.0.id', 106);

        DB::table('game_roles')
            ->where('id', 106)
            ->update(['name' => '缓存外修改']);

        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertJsonPath('data.roles.0.name', '2025 春季角色');

        app(CardStatisticsRoleService::class)->forgetCache();

        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertJsonPath('data.roles.0.name', '缓存外修改');
    }

    public function test_probability_change_uses_new_evaluation_after_cache_invalidation(): void
    {
        CardStatistic::create([
            'uid' => $this->user->id,
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 3],
            ],
        ]);

        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertJsonPath('data.roles.1.evaluation.title', '天选之子');

        DB::table('game_roles')
            ->where('id', 101)
            ->update(['probability' => 2]);
        app(CardStatisticsRoleService::class)->forgetCache();

        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertJsonPath('data.roles.1.evaluation.title', '欧皇');
    }

    public function test_save_persists_numeric_json_and_returns_a_sorted_report(): void
    {
        $this->putJson('/api/user/card-statistics', [
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 200],
                ['id' => 106, 'count' => 0],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.report.0.id', 106)
            ->assertJsonPath('data.report.0.count', 0)
            ->assertJsonPath('data.report.0.evaluation.title', '暂无评价')
            ->assertJsonPath('data.report.1.id', 101)
            ->assertJsonPath('data.report.1.count', 200)
            ->assertJsonPath('data.report.1.evaluation.title', '保底之王');

        $statistic = CardStatistic::query()->firstOrFail();

        $this->assertSame([
            ['id' => 106, 'count' => 0],
            ['id' => 101, 'count' => 200],
        ], $statistic->data);
        $this->assertSame(1, CardStatistic::query()->count());
    }

    public function test_hidden_history_is_preserved_while_visible_omissions_are_removed(): void
    {
        CardStatistic::create([
            'uid' => $this->user->id,
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 77],
                ['id' => 106, 'count' => 10],
                ['id' => 999, 'count' => 50],
            ],
        ]);

        $this->putJson('/api/user/card-statistics', [
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 0],
            ],
        ])->assertOk();

        $data = CardStatistic::query()->firstOrFail()->data;
        $dataById = collect($data)->keyBy('id')->all();

        $this->assertSame(50, $dataById[999]['count']);
        $this->assertSame(0, $dataById[101]['count']);
        $this->assertArrayNotHasKey(106, $dataById);

        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertJsonPath('data.roles.0.saved_count', null)
            ->assertJsonPath('data.roles.1.saved_count', 0);
    }

    public function test_empty_data_clears_visible_records_without_filling_zeroes(): void
    {
        CardStatistic::create([
            'uid' => $this->user->id,
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 77],
                ['id' => 106, 'count' => 10],
                ['id' => 999, 'count' => 50],
            ],
        ]);

        $this->putJson('/api/user/card-statistics', [
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [],
        ])
            ->assertOk()
            ->assertJsonPath('data.report', []);

        $this->assertSame([
            ['id' => 999, 'count' => 50],
        ], CardStatistic::query()->firstOrFail()->data);
    }

    public function test_invalid_payloads_are_rejected_without_modifying_existing_data(): void
    {
        CardStatistic::create([
            'uid' => $this->user->id,
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 77],
            ],
        ]);

        $invalidPayloads = [
            [['id' => 101, 'count' => 201]],
            [['id' => 101, 'count' => -1]],
            [['id' => 101, 'count' => 1.5]],
            [['id' => 101, 'count' => '1']],
            [
                ['id' => 101, 'count' => 1],
                ['id' => 101, 'count' => 2],
            ],
            [['id' => 102, 'count' => 1]],
        ];

        foreach ($invalidPayloads as $data) {
            $this->putJson('/api/user/card-statistics', [
                'account_id' => $this->pool->id,
                'year' => 2025,
                'data' => $data,
            ])->assertUnprocessable();
        }

        $this->assertSame([
            ['id' => 101, 'count' => 77],
        ], CardStatistic::query()->firstOrFail()->data);
    }

    public function test_account_year_rows_are_isolated_and_upserted(): void
    {
        $this->putJson('/api/user/card-statistics', [
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 1],
            ],
        ])->assertOk();

        $this->putJson('/api/user/card-statistics', [
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 2],
            ],
        ])->assertOk();

        $this->putJson('/api/user/card-statistics', [
            'account_id' => $this->secondPool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 3],
            ],
        ])->assertOk();

        $this->putJson('/api/user/card-statistics', [
            'account_id' => $this->pool->id,
            'year' => 2026,
            'data' => [
                ['id' => 102, 'count' => 4],
            ],
        ])->assertOk();

        $this->assertSame(3, CardStatistic::query()->count());

        $this->getJson($this->rolesUrl($this->pool->id, 2025))
            ->assertJsonPath('data.roles.1.saved_count', 2);
        $this->getJson($this->rolesUrl($this->secondPool->id, 2025))
            ->assertJsonPath('data.roles.1.saved_count', 3);
        $this->getJson($this->rolesUrl($this->pool->id, 2026))
            ->assertJsonPath('data.roles.0.saved_count', 4);
    }

    private function rolesUrl(
        ?int $accountId = null,
        ?int $year = null
    ): string {
        $query = [
            'account_id' => $accountId ?? $this->pool->id,
        ];

        if ($year !== null) {
            $query['year'] = $year;
        }

        return '/api/user/card-statistics/roles?'
            . http_build_query($query);
    }

    /**
     * @return array{account_id: int, year: int, data: array<int, array{id: int, count: int}>}
     */
    private function savePayload(): array
    {
        return [
            'account_id' => $this->pool->id,
            'year' => 2025,
            'data' => [
                ['id' => 101, 'count' => 1],
            ],
        ];
    }

    private function createTestTables(): void
    {
        foreach ([
            'card_statistics',
            'user_role_pools',
            'game_roles',
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

        Schema::create('user_role_pools', function (Blueprint $table): void {
            $table->id();
            $table->integer('uid');
            $table->string('nickname', 64);
            $table->json('roles');
            $table->json('coin')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('card_statistics', function (Blueprint $table): void {
            $table->id();
            $table->integer('uid');
            $table->unsignedBigInteger('account_id');
            $table->unsignedSmallInteger('year');
            $table->json('data');
            $table->timestamps();
            $table->unique(['uid', 'account_id', 'year']);
        });
    }

    private function createUser(
        string $email,
        string $nickname,
        int $status
    ): User {
        return User::create([
            'ip' => '127.0.0.' . (User::query()->count() + 1),
            'nickname' => $nickname,
            'email' => $email,
            'password' => 'secret',
            'status' => $status,
        ]);
    }

    private function createPool(
        User $user,
        string $nickname,
        int $status = 1
    ): UserRolePool {
        return UserRolePool::create([
            'uid' => $user->id,
            'nickname' => $nickname,
            'roles' => [],
            'coin' => null,
            'status' => $status,
        ]);
    }

    private function createGameRole(
        int $id,
        string $name,
        ?string $upTime,
        int $probability,
        int $status = 1,
        int $obtain = 1,
        ?int $roleId6 = null
    ): GameRole {
        return GameRole::create([
            'id' => $id,
            'role_id' => $id * 100 + 1,
            'role_id_1' => $id * 100 + 11,
            'role_id_3' => $id * 100 + 31,
            'role_id_6' => $roleId6,
            'element' => 1,
            'atk_type' => 1,
            'is_clan_battle' => false,
            'name' => $name,
            'nickname' => $name,
            'search_area_width' => 300,
            'position' => 1,
            'obtain' => $obtain,
            'probability' => $probability,
            'up_time' => $upTime,
            'status' => $status,
        ]);
    }
}
