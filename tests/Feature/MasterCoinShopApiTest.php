<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\GameRole;
use App\Models\GameRoleImage;
use App\Models\GameRoleStatistics;
use App\Models\MasterCoinShopRole;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Services\CardStatisticsRoleService;
use Database\Seeders\MasterCoinShopSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterCoinShopApiTest extends TestCase
{
    private Admin $admin;

    private Role $superAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();

        $this->superAdminRole = Role::create([
            'name' => 'Super Admin',
            'code' => 'super_admin',
            'description' => '',
            'sort' => 1,
            'status' => 1,
        ]);

        $this->admin = Admin::create([
            'username' => 'master-coin-shop-admin',
            'password' => 'secret',
            'nickname' => 'Master Coin Shop Admin',
            'status' => 1,
        ]);
        $this->admin->roles()->attach($this->superAdminRole->id);
        Sanctum::actingAs($this->admin);

        $this->createGameRole(101, '真步', '真步昵称', true);
        $this->createGameRole(102, '凯露', '凯露昵称', true);
        $this->createGameRole(103, '可可萝', '可可萝昵称', true);
        $this->createGameRole(104, '停用角色', '停用昵称', false);
    }

    public function test_add_roles_to_both_groups_and_sort_each_group_independently(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertOk()
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonPath('data.type', 1);

        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 102,
            'type' => 1,
        ])->assertOk()
            ->assertJsonPath('data.sort_order', 2);

        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 103,
            'type' => 2,
        ])->assertOk()
            ->assertJsonPath('data.sort_order', 1);

        $this->getJson('/api/admin/game/master-coin-shop')
            ->assertOk()
            ->assertJsonCount(2, 'data.fes')
            ->assertJsonCount(1, 'data.non_fes')
            ->assertJsonPath('data.fes.0.game_role_id', 102)
            ->assertJsonPath('data.fes.0.sort_order', 2)
            ->assertJsonPath('data.fes.1.game_role_id', 101)
            ->assertJsonPath('data.non_fes.0.game_role_id', 103)
            ->assertJsonPath('data.non_fes.0.sort_order', 1);
    }

    public function test_duplicate_add_returns_conflict_without_creating_another_row(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertOk();

        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 2,
        ])->assertStatus(409)
            ->assertJsonPath('status', 1)
            ->assertJsonPath('msg', '该角色已加入大师币商店');

        $this->assertDatabaseCount('master_coin_shop_roles', 1);
    }

    public function test_invalid_type_and_missing_game_role_are_validation_errors(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 3,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 999,
            'type' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['game_role_id']);

        $this->assertDatabaseCount('master_coin_shop_roles', 0);
    }

    public function test_missing_game_role_on_move_and_remove_returns_api_404(): void
    {
        $this->putJson('/api/admin/game/master-coin-shop/roles/999/type', [
            'type' => 2,
        ])->assertNotFound()
            ->assertJsonPath('status', 1)
            ->assertJsonPath('msg', '所选游戏角色不存在');

        $this->deleteJson('/api/admin/game/master-coin-shop/roles/999')
            ->assertNotFound()
            ->assertJsonPath('status', 1)
            ->assertJsonPath('msg', '所选游戏角色不存在');
    }

    public function test_move_role_to_another_group_puts_it_first_and_rejects_same_group(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertOk();
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 102,
            'type' => 1,
        ])->assertOk();
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 103,
            'type' => 2,
        ])->assertOk();

        $this->putJson('/api/admin/game/master-coin-shop/roles/101/type', [
            'type' => 2,
        ])->assertOk()
            ->assertJsonPath('data.type', 2)
            ->assertJsonPath('data.sort_order', 2);

        $this->getJson('/api/admin/game/master-coin-shop')
            ->assertOk()
            ->assertJsonPath('data.fes.0.game_role_id', 102)
            ->assertJsonPath('data.non_fes.0.game_role_id', 101)
            ->assertJsonPath('data.non_fes.1.game_role_id', 103);

        $this->putJson('/api/admin/game/master-coin-shop/roles/101/type', [
            'type' => 2,
        ])->assertStatus(409);
    }

    public function test_sort_group_updates_complete_order_without_affecting_other_group(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertOk();
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 102,
            'type' => 1,
        ])->assertOk();
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 103,
            'type' => 1,
        ])->assertOk();
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 104,
            'type' => 2,
        ])->assertOk();

        $this->putJson('/api/admin/game/master-coin-shop/groups/1/sort', [
            'game_role_ids' => [102, 103, 101],
        ])->assertOk()
            ->assertJsonPath('data.fes.0.game_role_id', 102)
            ->assertJsonPath('data.fes.0.sort_order', 3)
            ->assertJsonPath('data.fes.1.game_role_id', 103)
            ->assertJsonPath('data.fes.1.sort_order', 2)
            ->assertJsonPath('data.fes.2.game_role_id', 101)
            ->assertJsonPath('data.fes.2.sort_order', 1)
            ->assertJsonPath('data.non_fes.0.game_role_id', 104);

        $this->assertDatabaseHas('master_coin_shop_roles', [
            'game_role_id' => 104,
            'type' => 2,
            'sort_order' => 1,
        ]);
    }

    public function test_sort_group_rejects_duplicate_partial_extra_and_invalid_membership(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertOk();
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 102,
            'type' => 1,
        ])->assertOk();

        $this->putJson('/api/admin/game/master-coin-shop/groups/1/sort', [
            'game_role_ids' => [101, 101],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['game_role_ids.0']);

        $this->putJson('/api/admin/game/master-coin-shop/groups/1/sort', [
            'game_role_ids' => [101],
        ])->assertStatus(409)
            ->assertJsonPath('status', 1)
            ->assertJsonPath('msg', '排序角色列表与当前分组不一致，请刷新后重试');

        $this->putJson('/api/admin/game/master-coin-shop/groups/1/sort', [
            'game_role_ids' => [101, 102, 103],
        ])->assertStatus(409);

        $this->putJson('/api/admin/game/master-coin-shop/groups/3/sort', [
            'game_role_ids' => [101, 102],
        ])->assertUnprocessable()
            ->assertJsonPath('status', 1)
            ->assertJsonPath('msg', '商店分组无效');
    }

    public function test_remove_only_deletes_shop_config_and_does_not_reorder_remaining_rows(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertOk();
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 102,
            'type' => 1,
        ])->assertOk();

        $this->deleteJson('/api/admin/game/master-coin-shop/roles/101')
            ->assertOk();

        $this->assertDatabaseMissing('master_coin_shop_roles', [
            'game_role_id' => 101,
        ]);
        $this->assertDatabaseHas('game_roles', ['id' => 101]);
        $this->assertDatabaseHas('master_coin_shop_roles', [
            'game_role_id' => 102,
            'sort_order' => 2,
        ]);

        $this->getJson('/api/admin/game/master-coin-shop')
            ->assertOk()
            ->assertJsonCount(1, 'data.fes')
            ->assertJsonPath('data.fes.0.sort_order', 2);

        $this->deleteJson('/api/admin/game/master-coin-shop/roles/101')
            ->assertStatus(404);
    }

    public function test_deleting_game_role_cleans_up_shop_config(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertOk();

        GameRoleImage::create([
            'game_role_id' => 101,
            'image_id' => 1031,
            'is_6_star' => false,
            'is_download' => true,
        ]);
        GameRoleStatistics::create([
            'game_role_id' => 101,
            'use_times' => 1,
        ]);

        $this->deleteJson('/api/admin/game/roles/101')
            ->assertOk();

        $this->assertDatabaseMissing('game_roles', ['id' => 101]);
        $this->assertDatabaseMissing('master_coin_shop_roles', [
            'game_role_id' => 101,
        ]);
    }

    public function test_deleting_game_role_invalidates_card_statistics_role_cache(): void
    {
        Cache::put(
            CardStatisticsRoleService::CACHE_KEY,
            [['id' => 101]],
            now()->addDay()
        );

        $this->deleteJson('/api/admin/game/roles/101')->assertOk();

        $this->assertFalse(
            Cache::has(CardStatisticsRoleService::CACHE_KEY)
        );
    }

    public function test_updating_game_role_invalidates_card_statistics_role_cache(): void
    {
        Cache::put(
            CardStatisticsRoleService::CACHE_KEY,
            [['id' => 101]],
            now()->addDay()
        );
        GameRoleImage::create([
            'game_role_id' => 101,
            'image_id' => 10131,
            'is_6_star' => false,
            'is_download' => true,
        ]);

        $this->putJson('/api/admin/game/roles/101', [
            'name_a' => '真步',
            'nickname' => '真步昵称',
            'search_area_width' => 300,
            'position' => 1,
            'atk_type' => 1,
            'element' => 1,
            'obtain' => 1,
            'probability' => 2,
            'up_time' => '2025-12-20',
            'is_clan_battle' => true,
            'status' => true,
        ])->assertOk();

        $this->assertFalse(
            Cache::has(CardStatisticsRoleService::CACHE_KEY)
        );
    }

    public function test_search_returns_all_matching_roles_with_shop_type_and_status(): void
    {
        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertOk();

        $this->getJson('/api/admin/game/master-coin-shop/game-roles?keyword=停用')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.game_role_id', 104)
            ->assertJsonPath('data.0.name', '停用角色')
            ->assertJsonPath('data.0.status', false)
            ->assertJsonPath('data.0.shop_type', null);

        $this->getJson('/api/admin/game/master-coin-shop/game-roles?keyword=真步')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'game_role_id' => 101,
                'name' => '真步',
                'shop_type' => 1,
            ]);

        $this->getJson('/api/admin/game/master-coin-shop/game-roles?keyword=停用昵称')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.game_role_id', 104);

        $this->getJson('/api/admin/game/master-coin-shop/game-roles?keyword=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keyword']);
    }

    public function test_non_super_admin_requires_exact_permission_for_each_endpoint(): void
    {
        $limitedRole = Role::create([
            'name' => 'Limited Admin',
            'code' => 'limited_admin',
            'description' => '',
            'sort' => 2,
            'status' => 1,
        ]);
        $limitedAdmin = Admin::create([
            'username' => 'limited-master-coin-shop-admin',
            'password' => 'secret',
            'nickname' => 'Limited Admin',
            'status' => 1,
        ]);
        $limitedAdmin->roles()->attach($limitedRole->id);

        Sanctum::actingAs($limitedAdmin);

        $this->getJson('/api/admin/game/master-coin-shop')
            ->assertForbidden();

        $listPermission = Permission::create([
            'name' => 'system:master-coin-shop:list',
            'display_name' => '大师币商店列表查询',
            'http_method' => 'GET',
            'http_path' => 'api/admin/game/master-coin-shop',
        ]);
        $limitedRole->permissions()->attach($listPermission->id);

        $this->getJson('/api/admin/game/master-coin-shop')
            ->assertOk();
        $this->getJson('/api/admin/game/master-coin-shop/game-roles?keyword=真步')
            ->assertForbidden();

        $this->postJson('/api/admin/game/master-coin-shop/roles', [
            'game_role_id' => 101,
            'type' => 1,
        ])->assertForbidden();

        $this->putJson('/api/admin/game/master-coin-shop/roles/101/type', [
            'type' => 2,
        ])->assertForbidden();

        $this->deleteJson('/api/admin/game/master-coin-shop/roles/101')
            ->assertForbidden();

        $this->putJson('/api/admin/game/master-coin-shop/groups/1/sort', [
            'game_role_ids' => [101],
        ])->assertForbidden();

        MasterCoinShopRole::create([
            'game_role_id' => 101,
            'type' => 1,
            'sort_order' => 1,
        ]);
        MasterCoinShopRole::create([
            'game_role_id' => 102,
            'type' => 1,
            'sort_order' => 2,
        ]);
        $sortPermission = Permission::create([
            'name' => 'system:master-coin-shop:sort',
            'display_name' => '大师币商店组内排序',
            'http_method' => 'PUT',
            'http_path' => 'api/admin/game/master-coin-shop/groups/{type}/sort',
        ]);
        $limitedRole->permissions()->attach($sortPermission->id);

        $this->putJson('/api/admin/game/master-coin-shop/groups/1/sort', [
            'game_role_ids' => [101, 102],
        ])->assertOk()
            ->assertJsonPath('data.fes.0.game_role_id', 101)
            ->assertJsonPath('data.fes.1.game_role_id', 102);
    }

    public function test_seeder_is_idempotent_and_grants_only_super_admin(): void
    {
        $gameMenu = Menu::create([
            'parent_id' => 0,
            'type' => 1,
            'name' => 'Game',
            'path' => '/game',
            'component' => '',
            'title' => '游戏',
            'sort' => 1,
            'status' => 1,
        ]);
        $dataAdmin = Role::create([
            'name' => 'Data Admin',
            'code' => 'data_admin',
            'description' => '',
            'sort' => 2,
            'status' => 1,
        ]);

        (new MasterCoinShopSeeder())->run();
        (new MasterCoinShopSeeder())->run();

        $menu = Menu::query()
            ->where('name', 'MasterCoinShop')
            ->firstOrFail();
        $this->assertSame($gameMenu->id, $menu->parent_id);
        $this->assertSame('/game/master-coin-shop/index', $menu->component);
        $this->assertSame(6, Permission::query()
            ->where('name', 'like', 'system:master-coin-shop:%')
            ->count());
        $this->assertDatabaseHas('role_menu', [
            'role_id' => $this->superAdminRole->id,
            'menu_id' => $menu->id,
        ]);
        $this->assertDatabaseMissing('role_menu', [
            'role_id' => $dataAdmin->id,
            'menu_id' => $menu->id,
        ]);
        $this->assertSame(6, $this->superAdminRole->permissions()
            ->where('permissions.name', 'like', 'system:master-coin-shop:%')
            ->count());
        $this->assertSame(0, $dataAdmin->permissions()
            ->where('permissions.name', 'like', 'system:master-coin-shop:%')
            ->count());

        $this->assertDatabaseHas('permissions', [
            'name' => 'system:master-coin-shop:update',
            'http_method' => 'PUT',
            'http_path' => 'api/admin/game/master-coin-shop/roles/{gameRole}/type',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'system:master-coin-shop:sort',
            'http_method' => 'PUT',
            'http_path' => 'api/admin/game/master-coin-shop/groups/{type}/sort',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'system:master-coin-shop:delete',
            'http_method' => 'DELETE',
            'http_path' => 'api/admin/game/master-coin-shop/roles/{gameRole}',
        ]);
    }

    private function createTestTables(): void
    {
        foreach ([
            'master_coin_shop_roles',
            'game_role_statistics',
            'game_role_images',
            'game_roles',
            'role_menu',
            'menus',
            'role_permission',
            'permissions',
            'admin_role',
            'admins',
            'roles',
        ] as $table) {
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

        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->tinyInteger('type')->default(1);
            $table->string('name', 100)->default('');
            $table->string('path', 255)->default('');
            $table->string('component', 255)->default('');
            $table->string('redirect', 255)->default('');
            $table->string('title', 100)->default('');
            $table->string('icon', 100)->default('');
            $table->string('permission', 255)->default('');
            $table->integer('sort')->default(0);
            $table->tinyInteger('hidden')->default(0);
            $table->tinyInteger('keep_alive')->default(1);
            $table->tinyInteger('affix_tab')->default(0);
            $table->string('iframe_src', 500)->default('');
            $table->string('link', 500)->default('');
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('role_menu', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('menu_id');
            $table->primary(['role_id', 'menu_id']);
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

        Schema::create('game_role_images', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('game_role_id');
            $table->unsignedBigInteger('image_id');
            $table->boolean('is_6_star')->default(false);
            $table->boolean('is_download')->default(false);
            $table->timestamps();
        });

        Schema::create('game_role_statistics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('game_role_id')->unique();
            $table->unsignedBigInteger('use_times')->default(0);
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
    }

    private function createGameRole(
        int $id,
        string $name,
        string $nickname,
        bool $status
    ): void {
        GameRole::create([
            'id' => $id,
            'role_id' => $id * 100 + 1,
            'role_id_1' => $id * 100 + 11,
            'role_id_3' => $id * 100 + 31,
            'role_id_6' => null,
            'element' => 1,
            'atk_type' => 1,
            'is_clan_battle' => true,
            'name' => $name,
            'nickname' => $nickname,
            'search_area_width' => 300,
            'position' => 1,
            'obtain' => 1,
            'probability' => 1,
            'up_time' => null,
            'status' => $status,
        ]);
    }
}
