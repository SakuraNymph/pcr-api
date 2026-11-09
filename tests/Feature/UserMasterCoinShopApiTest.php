<?php

namespace Tests\Feature;

use App\Models\GameRole;
use App\Models\MasterCoinShopRole;
use App\Models\User;
use App\Models\UserRolePool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserMasterCoinShopApiTest extends TestCase
{
    private User $user;

    private UserRolePool $primaryPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();

        $this->user = $this->createUser('owner@example.com', '账号主人', 1);
        $this->primaryPool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '主账号',
            'roles' => [101, 201],
            'coin' => null,
            'status' => 1,
        ]);

        $this->createGameRole(101, 'FES 角色一', null, 301);
        $this->createGameRole(102, 'FES 角色二', 602, 302);
        $this->createGameRole(201, '非 FES 角色一', null, 401);
        $this->createGameRole(202, '非 FES 角色二', null, 402);
        $this->createGameRole(301, '未配置角色', null, 501);

        $this->configureShopRole(101, 1, 1);
        $this->configureShopRole(102, 1, 2);
        $this->configureShopRole(201, 2, 1);
        $this->configureShopRole(202, 2, 2);

        Sanctum::actingAs($this->user);
    }

    public function test_user_roles_are_fes_first_and_stably_sorted_with_avatar_urls(): void
    {
        $this->getJson('/api/user/master-coin-shop/roles')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.id', 102)
            ->assertJsonPath('data.0.type', 1)
            ->assertJsonPath('data.0.name', 'FES 角色二')
            ->assertJsonPath(
                'data.0.image_url',
                url('images/roles/602.webp')
            )
            ->assertJsonPath('data.1.id', 101)
            ->assertJsonPath('data.1.type', 1)
            ->assertJsonPath(
                'data.1.image_url',
                url('images/roles/301.webp')
            )
            ->assertJsonPath('data.2.id', 202)
            ->assertJsonPath('data.2.type', 2)
            ->assertJsonPath('data.3.id', 201)
            ->assertJsonPath('data.3.type', 2);
    }

    public function test_null_coin_is_returned_as_empty_array(): void
    {
        $this->getJson(
            "/api/user/role-pools/{$this->primaryPool->id}/master-coin-shop"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $this->primaryPool->id)
            ->assertJsonPath('data.nickname', '主账号')
            ->assertJsonPath('data.coin', []);
    }

    public function test_update_saves_coin_json_and_does_not_change_roles(): void
    {
        $this->putJson(
            "/api/user/role-pools/{$this->primaryPool->id}/master-coin-shop",
            ['roles' => [101, 201]]
        )
            ->assertOk()
            ->assertJsonPath('data.id', $this->primaryPool->id)
            ->assertJsonPath('data.coin', [101, 201]);

        $this->assertDatabaseHas('user_role_pools', [
            'id' => $this->primaryPool->id,
            'roles' => json_encode([101, 201]),
            'coin' => json_encode([101, 201]),
        ]);

        $this->putJson(
            "/api/user/role-pools/{$this->primaryPool->id}/master-coin-shop",
            ['roles' => []]
        )
            ->assertOk()
            ->assertJsonPath('data.coin', []);

        $pool = $this->primaryPool->fresh();
        $this->assertSame([], $pool->coin);
        $this->assertSame([101, 201], $pool->roles);
    }

    public function test_multiple_pools_are_isolated(): void
    {
        $secondaryPool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '小号',
            'roles' => [202],
            'coin' => [202],
            'status' => 1,
        ]);

        $this->putJson(
            "/api/user/role-pools/{$this->primaryPool->id}/master-coin-shop",
            ['roles' => [102]]
        )->assertOk();

        $this->getJson(
            "/api/user/role-pools/{$secondaryPool->id}/master-coin-shop"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $secondaryPool->id)
            ->assertJsonPath('data.coin', [202]);
    }

    public function test_other_users_pools_and_deleted_pools_are_not_accessible(): void
    {
        $otherUser = $this->createUser('other@example.com', '其他用户', 1);
        $otherPool = UserRolePool::create([
            'uid' => $otherUser->id,
            'nickname' => '别人的账号',
            'roles' => [],
            'coin' => [101],
            'status' => 1,
        ]);
        $deletedPool = UserRolePool::create([
            'uid' => $this->user->id,
            'nickname' => '已删除账号',
            'roles' => [],
            'coin' => [101],
            'status' => 0,
        ]);

        $this->getJson(
            "/api/user/role-pools/{$otherPool->id}/master-coin-shop"
        )
            ->assertNotFound()
            ->assertJsonPath('status', 1)
            ->assertJsonPath('msg', '游戏账号不存在');

        $this->putJson(
            "/api/user/role-pools/{$otherPool->id}/master-coin-shop",
            ['roles' => []]
        )
            ->assertNotFound()
            ->assertJsonPath('msg', '游戏账号不存在');

        $this->getJson(
            "/api/user/role-pools/{$deletedPool->id}/master-coin-shop"
        )->assertNotFound();

        $this->assertSame([101], $otherPool->fresh()->coin);
    }

    public function test_duplicate_and_unconfigured_roles_are_rejected_without_writing(): void
    {
        $url = "/api/user/role-pools/{$this->primaryPool->id}/master-coin-shop";

        $this->putJson($url, ['roles' => [101, 101]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles.0']);

        $this->putJson($url, ['roles' => [301]])
            ->assertUnprocessable()
            ->assertJsonPath('status', 1)
            ->assertJsonPath('msg', '包含未配置到大师币商店的角色');

        $this->putJson($url, ['roles' => [99999]])
            ->assertUnprocessable()
            ->assertJsonPath('msg', '包含未配置到大师币商店的角色');

        $this->assertNull($this->primaryPool->fresh()->coin);
    }

    public function test_guest_and_unauthenticated_users_are_rejected(): void
    {
        $guest = $this->createUser('guest@example.com', '游客', 0);
        $poolUrl = "/api/user/role-pools/{$this->primaryPool->id}/master-coin-shop";
        Sanctum::actingAs($guest);

        $this->getJson('/api/user/master-coin-shop/roles')
            ->assertUnauthorized()
            ->assertJsonPath('status', 1);

        $this->getJson($poolUrl)->assertUnauthorized();
        $this->putJson($poolUrl, ['roles' => []])->assertUnauthorized();

        Auth::forgetGuards();

        $this->getJson('/api/user/master-coin-shop/roles')->assertUnauthorized();
        $this->getJson($poolUrl)->assertUnauthorized();
        $this->putJson($poolUrl, ['roles' => []])->assertUnauthorized();
    }

    private function createTestTables(): void
    {
        foreach ([
            'user_role_pools',
            'master_coin_shop_roles',
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

        Schema::create('master_coin_shop_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('game_role_id')->unique();
            $table->unsignedTinyInteger('type');
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->unique(['type', 'sort_order']);
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

    private function createGameRole(
        int $id,
        string $name,
        ?int $roleId6,
        int $roleId3
    ): void {
        GameRole::create([
            'id' => $id,
            'role_id' => $id * 100 + 1,
            'role_id_1' => $id * 100 + 11,
            'role_id_3' => $roleId3,
            'role_id_6' => $roleId6,
            'element' => 1,
            'atk_type' => 1,
            'is_clan_battle' => true,
            'name' => $name,
            'nickname' => $name,
            'search_area_width' => 300,
            'position' => 1,
            'obtain' => 1,
            'probability' => 1,
            'up_time' => null,
            'status' => true,
        ]);
    }

    private function configureShopRole(
        int $gameRoleId,
        int $type,
        int $sortOrder
    ): void {
        MasterCoinShopRole::create([
            'game_role_id' => $gameRoleId,
            'type' => $type,
            'sort_order' => $sortOrder,
        ]);
    }
}
