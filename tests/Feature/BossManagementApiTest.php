<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\BossManagementSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BossManagementApiTest extends TestCase
{
    private Admin $admin;

    private Role $superAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));
        $this->createTestTables();

        $this->superAdminRole = Role::create([
            'name' => 'Super Admin',
            'code' => 'super_admin',
            'description' => '',
            'sort' => 1,
            'status' => 1,
        ]);

        $this->admin = Admin::create([
            'username' => 'boss-admin',
            'password' => 'secret',
            'nickname' => 'BOSS Admin',
            'status' => 1,
        ]);
        $this->admin->roles()->attach($this->superAdminRole->id);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_current_overview_falls_back_to_previous_boss_for_each_seat(): void
    {
        foreach (range(1, 5) as $sort) {
            DB::table('bosses')->insert([
                [
                    'id' => 100 + $sort,
                    'name' => "上月 BOSS {$sort}",
                    'file_path' => "previous-{$sort}.jpeg",
                    'year_month' => '202610',
                    'status' => 1,
                    'sort' => $sort,
                ],
                [
                    'id' => 200 + $sort,
                    'name' => "候选 BOSS {$sort}",
                    'file_path' => "candidate-{$sort}.jpeg",
                    'year_month' => null,
                    'status' => 0,
                    'sort' => $sort,
                ],
            ]);
        }

        $response = $this->getJson(
            '/api/admin/game/bosses?period=current'
        );

        $response->assertOk()
            ->assertJsonPath('data.period', 'current')
            ->assertJsonPath('data.year_month', '202611')
            ->assertJsonPath('data.previous_year_month', '202610')
            ->assertJsonPath('data.read_only', false)
            ->assertJsonCount(5, 'data.seats')
            ->assertJsonPath('data.seats.0.display_source', 'previous')
            ->assertJsonPath('data.seats.0.current_boss', null)
            ->assertJsonPath('data.seats.0.display_boss.id', 101)
            ->assertJsonPath('data.seats.0.previous_boss.id', 101)
            ->assertJsonCount(2, 'data.seats.0.options');

        $options = $response->json('data.seats.0.options');
        $candidate = collect($options)->firstWhere('id', 201);

        $this->assertEqualsCanonicalizing(
            [101, 201],
            array_column($options, 'id')
        );
        $this->assertSame('candidate-1.jpeg', $candidate['file_path']);
        $this->assertSame(
            rtrim((string) config('app.url'), '/')
                .'/images/bosses/candidate-1.jpeg',
            $candidate['image_url']
        );
        $this->assertSame('file:candidate-1.jpeg', $candidate['candidate_key']);
    }

    public function test_current_overview_mixes_current_and_previous_seats(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 101,
                'name' => '本月一王',
                'file_path' => 'current-one.jpeg',
                'year_month' => '202611',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 102,
                'name' => '本月二王旧选择',
                'file_path' => 'current-two-old.jpeg',
                'year_month' => '202611',
                'status' => 0,
                'sort' => 2,
            ],
            [
                'id' => 201,
                'name' => '上月一王',
                'file_path' => 'previous-one.jpeg',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 202,
                'name' => '上月二王',
                'file_path' => 'previous-two.jpeg',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 2,
            ],
        ]);

        $response = $this->getJson(
            '/api/admin/game/bosses?period=current'
        );

        $response->assertOk()
            ->assertJsonPath('data.seats.0.display_source', 'current')
            ->assertJsonPath('data.seats.0.display_boss.id', 101)
            ->assertJsonPath('data.seats.1.display_source', 'previous')
            ->assertJsonPath('data.seats.1.display_boss.id', 202)
            ->assertJsonPath('data.seats.1.current_boss', null)
            ->assertJsonCount(2, 'data.seats.1.options');

        $this->assertEqualsCanonicalizing(
            [102, 202],
            array_column($response->json('data.seats.1.options'), 'id')
        );
    }

    public function test_current_overview_lists_every_unique_boss_for_the_same_sort(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 701,
                'name' => '五月一王',
                'file_path' => 'may-one.jpeg',
                'year_month' => '202605',
                'status' => 0,
                'sort' => 1,
            ],
            [
                'id' => 702,
                'name' => '八月一王',
                'file_path' => 'august-one.jpeg',
                'year_month' => '202608',
                'status' => 0,
                'sort' => 1,
            ],
            [
                'id' => 703,
                'name' => '十月一王',
                'file_path' => 'october-one.jpeg',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 704,
                'name' => '十一月一王',
                'file_path' => 'november-one.jpeg',
                'year_month' => '202611',
                'status' => 0,
                'sort' => 1,
            ],
        ]);

        $response = $this->getJson(
            '/api/admin/game/bosses?period=current'
        );

        $response->assertOk()
            ->assertJsonCount(4, 'data.seats.0.options');

        $this->assertEqualsCanonicalizing(
            [701, 702, 703, 704],
            array_column($response->json('data.seats.0.options'), 'id')
        );
    }

    public function test_current_overview_deduplicates_month_snapshots_by_boss_image(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 711,
                'name' => '历史一王',
                'file_path' => 'same-boss.jpeg',
                'year_month' => '202609',
                'status' => 0,
                'sort' => 1,
            ],
            [
                'id' => 712,
                'name' => '历史一王',
                'file_path' => 'same-boss.jpeg',
                'year_month' => '202611',
                'status' => 0,
                'sort' => 1,
            ],
        ]);

        $response = $this->getJson(
            '/api/admin/game/bosses?period=current'
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data.seats.0.options')
            ->assertJsonPath('data.seats.0.options.0.id', 712)
            ->assertJsonPath(
                'data.seats.0.options.0.candidate_key',
                'file:same-boss.jpeg'
            );
    }

    public function test_previous_overview_is_editable_and_does_not_mix_current_display(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 301,
                'name' => '上月一王',
                'file_path' => 'previous-one.jpeg',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 302,
                'name' => '本月二王',
                'file_path' => 'current-two.jpeg',
                'year_month' => '202611',
                'status' => 1,
                'sort' => 2,
            ],
        ]);

        $response = $this->getJson(
            '/api/admin/game/bosses?period=previous'
        );

        $response->assertOk()
            ->assertJsonPath('data.period', 'previous')
            ->assertJsonPath('data.year_month', '202610')
            ->assertJsonPath('data.read_only', false)
            ->assertJsonCount(5, 'data.seats')
            ->assertJsonPath('data.seats.0.display_source', 'previous')
            ->assertJsonPath('data.seats.0.display_boss.id', 301)
            ->assertJsonPath('data.seats.0.current_boss', null)
            ->assertJsonCount(1, 'data.seats.0.options')
            ->assertJsonPath('data.seats.1.display_boss', null)
            ->assertJsonPath('data.seats.1.display_source', 'none')
            ->assertJsonPath('data.seats.1.current_boss.id', 302)
            ->assertJsonCount(1, 'data.seats.1.options')
            ->assertJsonPath('data.seats.1.options.0.id', 302);
    }

    public function test_select_current_boss_rotates_old_current_into_previous_month(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 401,
                'name' => '本月旧一王',
                'file_path' => 'old-one.jpeg',
                'year_month' => '202611',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 402,
                'name' => '空候选一王',
                'file_path' => 'new-one.jpeg',
                'year_month' => null,
                'status' => 0,
                'sort' => 1,
            ],
            [
                'id' => 403,
                'name' => '空候选二王',
                'file_path' => 'new-two.jpeg',
                'year_month' => null,
                'status' => 0,
                'sort' => 2,
            ],
            [
                'id' => 501,
                'name' => '上月一王',
                'file_path' => 'previous-one.jpeg',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
        ]);

        $response = $this->putJson('/api/admin/game/bosses/select', [
            'boss_id' => 402,
            'sort' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.sort', 1)
            ->assertJsonPath('data.display_source', 'current')
            ->assertJsonPath('data.display_boss.id', 402)
            ->assertJsonPath('data.previous_boss.file_path', 'old-one.jpeg');

        $this->assertDatabaseHas('bosses', [
            'id' => 402,
            'year_month' => '202611',
            'status' => 1,
            'sort' => 1,
        ]);
        $this->assertDatabaseHas('bosses', [
            'id' => 401,
            'year_month' => '202611',
            'status' => 0,
            'sort' => 1,
        ]);
        $this->assertDatabaseHas('bosses', [
            'id' => 501,
            'year_month' => '202610',
            'status' => 0,
            'sort' => 1,
        ]);
        $rotatedPrevious = DB::table('bosses')
            ->where('year_month', '202610')
            ->where('sort', 1)
            ->where('status', 1)
            ->first();

        $this->assertNotNull($rotatedPrevious);
        $this->assertSame('old-one.jpeg', $rotatedPrevious->file_path);
        $this->assertDatabaseHas('bosses', [
            'id' => 403,
            'year_month' => null,
            'status' => 0,
            'sort' => 2,
        ]);
        $this->assertDatabaseCount('bosses', 5);

        $this->getJson('/api/game/bosses?period=current')
            ->assertOk()
            ->assertJsonPath('data.0.name', '空候选一王')
            ->assertJsonPath('data.0.year_month', '202611');

        $this->getJson('/api/game/bosses?period=previous')
            ->assertOk()
            ->assertJsonPath('data.0.name', '本月旧一王')
            ->assertJsonPath('data.0.year_month', '202610');
    }

    public function test_select_historical_candidate_copies_it_without_changing_history(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 801,
                'name' => '本月旧一王',
                'file_path' => 'current-old-one.jpeg',
                'year_month' => '202611',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 802,
                'name' => '历史一王',
                'file_path' => 'history-one.jpeg',
                'year_month' => '202609',
                'status' => 0,
                'sort' => 1,
            ],
            [
                'id' => 803,
                'name' => '其他王位',
                'file_path' => 'other-seat.jpeg',
                'year_month' => null,
                'status' => 0,
                'sort' => 2,
            ],
        ]);

        $response = $this->putJson('/api/admin/game/bosses/select', [
            'boss_id' => 802,
            'sort' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.display_source', 'current')
            ->assertJsonPath('data.display_boss.name', '历史一王')
            ->assertJsonPath('data.display_boss.year_month', '202611');

        $selected = DB::table('bosses')
            ->where('year_month', '202611')
            ->where('sort', 1)
            ->where('status', 1)
            ->first();

        $this->assertNotNull($selected);
        $this->assertSame('历史一王', $selected->name);
        $this->assertSame('history-one.jpeg', $selected->file_path);
        $this->assertNotSame(802, (int) $selected->id);
        $this->assertDatabaseHas('bosses', [
            'id' => 802,
            'year_month' => '202609',
            'status' => 0,
            'sort' => 1,
        ]);
        $this->assertDatabaseHas('bosses', [
            'id' => 801,
            'year_month' => '202611',
            'status' => 0,
            'sort' => 1,
        ]);
        $rotatedPrevious = DB::table('bosses')
            ->where('year_month', '202610')
            ->where('sort', 1)
            ->where('status', 1)
            ->first();

        $this->assertNotNull($rotatedPrevious);
        $this->assertSame('current-old-one.jpeg', $rotatedPrevious->file_path);

        $countAfterFirstSelection = DB::table('bosses')->count();

        $this->putJson('/api/admin/game/bosses/select', [
            'boss_id' => 802,
            'sort' => 1,
        ])->assertOk();

        $this->assertSame(
            $countAfterFirstSelection,
            DB::table('bosses')->count()
        );
    }

    public function test_previous_selection_changes_only_previous_month(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 851,
                'name' => '本月一王',
                'file_path' => 'current-one.jpeg',
                'year_month' => '202611',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 852,
                'name' => '上月旧一王',
                'file_path' => 'previous-old-one.jpeg',
                'year_month' => '202610',
                'status' => 1,
                'sort' => 1,
            ],
            [
                'id' => 853,
                'name' => '历史一王',
                'file_path' => 'history-one.jpeg',
                'year_month' => '202609',
                'status' => 0,
                'sort' => 1,
            ],
        ]);

        $response = $this->putJson('/api/admin/game/bosses/select', [
            'boss_id' => 853,
            'sort' => 1,
            'period' => 'previous',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.display_source', 'previous')
            ->assertJsonPath('data.current_boss.id', 851)
            ->assertJsonPath('data.display_boss.name', '历史一王')
            ->assertJsonPath('data.display_boss.year_month', '202610');

        $this->assertDatabaseHas('bosses', [
            'id' => 851,
            'year_month' => '202611',
            'status' => 1,
            'sort' => 1,
        ]);
        $this->assertDatabaseHas('bosses', [
            'id' => 852,
            'year_month' => '202610',
            'status' => 0,
            'sort' => 1,
        ]);
        $this->assertDatabaseHas('bosses', [
            'id' => 853,
            'year_month' => '202609',
            'status' => 0,
            'sort' => 1,
        ]);

        $previousSelected = DB::table('bosses')
            ->where('year_month', '202610')
            ->where('sort', 1)
            ->where('status', 1)
            ->first();

        $this->assertNotNull($previousSelected);
        $this->assertSame('history-one.jpeg', $previousSelected->file_path);
        $this->assertDatabaseCount('bosses', 4);
    }

    public function test_select_rejects_sort_mismatch_and_missing_boss_without_writes(): void
    {
        DB::table('bosses')->insert([
            [
                'id' => 602,
                'name' => '二王候选',
                'file_path' => 'two.jpeg',
                'year_month' => null,
                'status' => 0,
                'sort' => 2,
            ],
        ]);

        foreach ([
            ['boss_id' => 602, 'sort' => 1],
            ['boss_id' => 999, 'sort' => 1],
        ] as $payload) {
            $this->putJson('/api/admin/game/bosses/select', $payload)
                ->assertStatus(422)
                ->assertJsonPath('status', 1);
        }

        $this->putJson('/api/admin/game/bosses/select', [
            'boss_id' => 602,
            'period' => 'future',
            'sort' => 2,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('period');

        $this->assertDatabaseHas('bosses', [
            'id' => 602,
            'year_month' => null,
            'status' => 0,
            'sort' => 2,
        ]);
    }

    public function test_boss_management_routes_require_permission(): void
    {
        $limitedAdmin = Admin::create([
            'username' => 'limited-boss-admin',
            'password' => 'secret',
            'nickname' => 'Limited Boss Admin',
            'status' => 1,
        ]);
        Sanctum::actingAs($limitedAdmin);

        $this->getJson('/api/admin/game/bosses')->assertForbidden();
        $this->putJson('/api/admin/game/bosses/select', [
            'boss_id' => 1,
            'sort' => 1,
        ])->assertForbidden();
    }

    public function test_boss_management_seeder_is_idempotent_and_grants_super_admin(): void
    {
        $gameMenu = Menu::create([
            'parent_id' => 0,
            'type' => 1,
            'name' => 'Game',
            'path' => '/game',
            'component' => '',
            'redirect' => '',
            'title' => '游戏管理',
            'icon' => 'lucide:gamepad-2',
            'permission' => '',
            'sort' => 0,
            'hidden' => 0,
            'keep_alive' => 1,
            'affix_tab' => 0,
            'iframe_src' => '',
            'link' => '',
            'status' => 1,
        ]);

        $this->seed(BossManagementSeeder::class);
        $this->seed(BossManagementSeeder::class);

        $menu = Menu::query()
            ->where('name', 'BossManagement')
            ->firstOrFail();

        $this->assertSame($gameMenu->id, $menu->parent_id);
        $this->assertSame('/game/bosses', $menu->path);
        $this->assertSame('/game/bosses/index', $menu->component);
        $this->assertSame('BOSS 管理', $menu->title);
        $this->assertSame(2, Permission::query()
            ->where('name', 'like', 'system:boss:%')
            ->count());
        $this->assertDatabaseHas('role_menu', [
            'role_id' => $this->superAdminRole->id,
            'menu_id' => $menu->id,
        ]);
        $this->assertSame(2, $this->superAdminRole->permissions()
            ->where('permissions.name', 'like', 'system:boss:%')
            ->count());
    }

    private function createTestTables(): void
    {
        foreach ([
            'bosses',
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

        Schema::create('bosses', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('file_path');
            $table->char('year_month', 6)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->unsignedTinyInteger('sort')->nullable();
        });

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
    }
}
