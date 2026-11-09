<?php

namespace Tests\Feature;

use App\Models\GameRole;
use App\Models\Team;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuestTeamFavoriteApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));

        $this->createTestTables();

        $this->createGameRole(101, '物理角色', 1, 100);
        $this->createGameRole(102, '魔法角色', -1, 500);

        $this->systemTeam = Team::query()->create([
            'stage' => 4,
            'uid' => 0,
            'boss' => 1,
            'damage' => 12000,
            'team_key' => '101,102',
            'status' => 1,
            'mode' => 1,
            'atk_value' => 0,
        ]);
        $this->systemTeam->teamRoles()->createMany([
            ['role_id' => 101, 'status' => 1],
            ['role_id' => 102, 'status' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_favorite_survives_reload_of_public_team_list(): void
    {
        // 匿名访问时不带任何身份，has_add 保持 0。
        $this->getJson('/api/game/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.has_add', 0);

        // 初始化游客身份。
        $visitorResponse = $this->postJson('/api/user/visitor')
            ->assertOk()
            ->assertJsonPath('data.visitor.status', 0);

        $visitorToken = $visitorResponse->json('data.accessToken');

        // 游客收藏系统作业，并标记缺失角色。
        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson(
                '/api/user/teams/from-system/'.$this->systemTeam->id,
                ['missing_role_id' => 101]
            )
            ->assertOk()
            ->assertJsonPath('status', 0);

        // 游客重新加载公共作业列表时，收藏状态必须仍然保留。
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/game/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.has_add', 1)
            ->assertJsonPath('data.0.missing_role_id', 101);
    }

    private function createGameRole(
        int $id,
        string $name,
        int $atkType,
        int $searchAreaWidth
    ): void {
        GameRole::query()->create([
            'id' => $id,
            'role_id' => $id,
            'role_id_1' => $id,
            'role_id_3' => $id,
            'role_id_6' => null,
            'element' => 1,
            'obtain' => null,
            'probability' => null,
            'up_time' => null,
            'atk_type' => $atkType,
            'is_clan_battle' => false,
            'name' => $name,
            'nickname' => $name,
            'search_area_width' => $searchAreaWidth,
            'position' => 1,
            'status' => true,
        ]);
    }

    private function createTestTables(): void
    {
        foreach ([
            'personal_access_tokens',
            'visitor_tokens',
            'users',
            'team_roles',
            'team_links',
            'teams',
            'game_roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('public_uid')->nullable()->unique();
            $table->string('ip', 45);
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

        Schema::create('visitor_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uid');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('uid');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
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
    }
}
