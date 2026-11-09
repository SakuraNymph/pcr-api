<?php

namespace Tests\Feature;

use App\Models\GameRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00'));
        $this->createTestTables();

        $this->user = $this->createUser(
            'owner@example.com',
            '原昵称',
            1,
            105811
        );
        $this->user->forceFill([
            'country' => '中国',
            'province' => '浙江省',
            'city' => '杭州市',
            'is_subscribe' => 1,
        ])->save();

        $this->createRole(101, '火角色', '火角色昵称', 1, 10161);
        $this->createRole(102, '水角色', '水角色昵称', 2);
        $this->createRole(103, '风角色', '风角色昵称', 3, 10361, 0);
        $this->createRole(9999, '', '', 4);
        $this->createRole(1360, '缺失图片角色', '缺图昵称', 5);
        $this->createRole(1701, '六星文件但无字段', '环奈', 1);

        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_me_returns_profile_and_avatar_fields(): void
    {
        $this->getJson('/api/user/me')
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.nickname', '原昵称')
            ->assertJsonPath('data.avatar', 105811)
            ->assertJsonPath('data.avatar_url', url('images/roles/105811.webp'))
            ->assertJsonPath('data.nickname_updated_at', null)
            ->assertJsonPath('data.country', '中国')
            ->assertJsonPath('data.province', '浙江省')
            ->assertJsonPath('data.city', '杭州市');
    }

    public function test_first_nickname_change_succeeds_and_preserves_other_fields(): void
    {
        $this->putJson('/api/user/profile', ['nickname' => '新昵称'])
            ->assertOk()
            ->assertJsonPath('data.nickname', '新昵称')
            ->assertJsonPath('data.email', 'owner@example.com')
            ->assertJsonPath('data.country', '中国')
            ->assertJsonPath('data.province', '浙江省')
            ->assertJsonPath('data.city', '杭州市')
            ->assertJsonPath('data.avatar', 105811);

        $this->user->refresh();
        $this->assertNotNull($this->user->nickname_updated_at);
        $this->assertSame('新昵称', $this->user->nickname);
    }

    public function test_nickname_cooldown_rejects_until_thirty_days_have_passed(): void
    {
        $this->putJson('/api/user/profile', ['nickname' => '第一次修改'])
            ->assertOk();

        $firstUpdatedAt = $this->user->fresh()->nickname_updated_at;

        Carbon::setTestNow(Carbon::parse('2026-12-03 12:00:00'));

        $this->putJson('/api/user/profile', ['nickname' => '第二次修改'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname']);

        $this->assertSame(
            '第一次修改',
            $this->user->fresh()->nickname
        );
        $this->assertTrue(
            $firstUpdatedAt->equalTo($this->user->fresh()->nickname_updated_at)
        );

        Carbon::setTestNow(Carbon::parse('2026-12-04 12:00:00'));

        $this->putJson('/api/user/profile', ['nickname' => '第二次修改'])
            ->assertOk()
            ->assertJsonPath('data.nickname', '第二次修改');
    }

    public function test_same_nickname_does_not_consume_cooldown(): void
    {
        $this->user->forceFill([
            'nickname_updated_at' => Carbon::parse('2026-11-01 12:00:00'),
        ])->save();

        $this->putJson('/api/user/profile', ['nickname' => '原昵称'])
            ->assertOk()
            ->assertJsonPath('data.nickname', '原昵称');

        $this->assertTrue(
            Carbon::parse('2026-11-01 12:00:00')
                ->equalTo($this->user->fresh()->nickname_updated_at)
        );
    }

    public function test_nickname_length_is_validated(): void
    {
        $this->putJson('/api/user/profile', ['nickname' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname']);

        $this->putJson('/api/user/profile', ['nickname' => str_repeat('长', 9)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname']);

        $this->putJson('/api/user/profile', ['nickname' => str_repeat('a', 25)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname']);
    }

    public function test_single_character_nickname_is_allowed(): void
    {
        $this->putJson('/api/user/profile', ['nickname' => '一'])
            ->assertOk()
            ->assertJsonPath('data.nickname', '一');
    }

    public function test_nickname_accepts_twenty_four_utf8_bytes(): void
    {
        $nickname = str_repeat('长', 8);

        $this->putJson('/api/user/profile', ['nickname' => $nickname])
            ->assertOk()
            ->assertJsonPath('data.nickname', $nickname);
    }

    public function test_nickname_accepts_twenty_four_ascii_bytes(): void
    {
        $nickname = str_repeat('a', 24);

        $this->putJson('/api/user/profile', ['nickname' => $nickname])
            ->assertOk()
            ->assertJsonPath('data.nickname', $nickname);
    }

    public function test_registration_enforces_nickname_and_password_length(): void
    {
        $badNicknameGuest = $this->createUser('guest-bad-nickname@example.com', '游客', 0);
        Cache::put('register_code:bad-nickname@example.com', '123456', now()->addMinutes(5));
        Sanctum::actingAs($badNicknameGuest);

        $this->postJson('/api/user/register', [
            'nickname' => str_repeat('长', 9),
            'email' => 'bad-nickname@example.com',
            'code' => '123456',
            'password' => '123456',
            'password_confirmation' => '123456',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname']);

        $shortPasswordGuest = $this->createUser('guest-short-password@example.com', '游客', 0);
        Cache::put('register_code:short-password@example.com', '123456', now()->addMinutes(5));
        Sanctum::actingAs($shortPasswordGuest);

        $this->postJson('/api/user/register', [
            'nickname' => '八位昵称名',
            'email' => 'short-password@example.com',
            'code' => '123456',
            'password' => '12345',
            'password_confirmation' => '12345',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $validGuest = $this->createUser('guest-valid-register@example.com', '游客', 0);
        Cache::put('register_code:valid-register@example.com', '123456', now()->addMinutes(5));
        Sanctum::actingAs($validGuest);

        $this->postJson('/api/user/register', [
            'nickname' => str_repeat('长', 8),
            'email' => 'valid-register@example.com',
            'code' => '123456',
            'password' => '123456',
            'password_confirmation' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('status', 0);
    }

    public function test_valid_one_three_and_six_star_avatars_can_be_saved(): void
    {
        foreach ([10111, 10131, 10161] as $avatarId) {
            $this->putJson('/api/user/profile', ['avatar' => $avatarId])
                ->assertOk()
                ->assertJsonPath('data.avatar', $avatarId);

            $this->assertSame($avatarId, (int) $this->user->fresh()->avatar);
        }
    }

    public function test_invalid_avatar_ids_are_rejected(): void
    {
        foreach ([
            10261,
            999911,
            10311,
            99999999,
        ] as $avatarId) {
            $this->putJson('/api/user/profile', ['avatar' => $avatarId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['avatar']);
        }

        $this->assertSame(105811, (int) $this->user->fresh()->avatar);
    }

    public function test_avatar_options_follow_database_candidates_without_file_filtering(): void
    {
        $response = $this->getJson('/api/user/avatar-options')
            ->assertOk()
            ->assertJsonPath('status', 0);

        $roles = collect($response->json('data'))->keyBy('id');

        $this->assertFalse($roles->has(9999));
        $this->assertFalse($roles->has(103));
        $this->assertTrue($roles->has(1360));
        $this->assertTrue($roles->has(1701));
        $this->assertSame(10161, $roles[101]['avatar_6']['id']);
        $this->assertNull($roles[102]['avatar_6']);
        $this->assertNull($roles[1701]['avatar_6']);
        $this->assertSame(136011, $roles[1360]['avatar_1']['id']);
    }

    public function test_missing_avatar_file_falls_back_to_default_url_without_changing_id(): void
    {
        $this->user->forceFill(['avatar' => 136011])->save();

        $this->getJson('/api/user/me')
            ->assertOk()
            ->assertJsonPath('data.avatar', 136011)
            ->assertJsonPath('data.avatar_url', url('images/roles/105811.webp'));

        $this->assertSame(136011, (int) $this->user->fresh()->avatar);
    }

    public function test_avatar_only_update_does_not_change_nickname_or_cooldown(): void
    {
        $this->user->forceFill([
            'nickname_updated_at' => Carbon::parse('2026-11-01 12:00:00'),
        ])->save();

        $this->putJson('/api/user/profile', ['avatar' => 10131])
            ->assertOk()
            ->assertJsonPath('data.avatar', 10131)
            ->assertJsonPath('data.nickname', '原昵称');

        $this->assertTrue(
            Carbon::parse('2026-11-01 12:00:00')
                ->equalTo($this->user->fresh()->nickname_updated_at)
        );
    }

    public function test_combined_update_is_atomic_when_nickname_is_in_cooldown(): void
    {
        $this->putJson('/api/user/profile', [
            'nickname' => '第一次修改',
            'avatar' => 10131,
        ])
            ->assertOk()
            ->assertJsonPath('data.nickname', '第一次修改')
            ->assertJsonPath('data.avatar', 10131);

        $this->putJson('/api/user/profile', [
            'nickname' => '第二次修改',
            'avatar' => 10111,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname']);

        $fresh = $this->user->fresh();

        $this->assertSame('第一次修改', $fresh->nickname);
        $this->assertSame(10131, (int) $fresh->avatar);
    }

    public function test_password_update_keeps_current_token_and_allows_login(): void
    {
        $token = $this->user->createToken('user-token')->plainTextToken;
        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/password', [
                'current_password' => 'old-password',
                'new_password' => 'new-password',
                'new_password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->assertJsonPath('status', 0);

        $this->assertTrue(
            Hash::check('new-password', $this->user->fresh()->password)
        );

        $this->getJson('/api/user/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id);

        $this->postJson('/api/user/login', [
            'email' => 'owner@example.com',
            'password' => 'new-password',
        ])
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.user.id', $this->user->id);
    }

    public function test_password_validation_rejects_wrong_or_invalid_passwords(): void
    {
        $this->putJson('/api/user/password', [
            'current_password' => 'wrong-password',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'new-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->putJson('/api/user/password', [
            'current_password' => 'old-password',
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_password']);

        $this->putJson('/api/user/password', [
            'current_password' => 'old-password',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'different-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_password']);

        $this->putJson('/api/user/password', [
            'current_password' => 'old-password',
            'new_password' => 'old-password',
            'new_password_confirmation' => 'old-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_password']);
    }

    public function test_registration_assigns_default_avatar_to_formal_user(): void
    {
        $guest = $this->createUser(
            'guest@example.com',
            '游客',
            0,
            null,
            '127.0.0.1'
        );

        $this->assertNull($guest->avatar);

        Cache::put(
            'register_code:new@example.com',
            '123456',
            now()->addMinutes(5)
        );

        Sanctum::actingAs($guest);

        $this->postJson('/api/user/register', [
            'nickname' => '新注册用户',
            'email' => 'new@example.com',
            'code' => '123456',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.user.avatar', 105811)
            ->assertJsonPath('data.user.status', 1);

        $this->assertSame(105811, (int) $guest->fresh()->avatar);
    }

    public function test_guest_cannot_update_profile_or_password(): void
    {
        $guest = $this->createUser('guest@example.com', '游客', 0);
        Sanctum::actingAs($guest);

        $this->putJson('/api/user/profile', ['nickname' => '游客新昵称'])
            ->assertUnauthorized();

        $this->putJson('/api/user/password', [
            'current_password' => 'old-password',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'new-password',
        ])->assertUnauthorized();

        $this->getJson('/api/user/avatar-options')->assertUnauthorized();
    }

    private function createTestTables(): void
    {
        foreach ([
            'personal_access_tokens',
            'game_roles',
            'visitor_tokens',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('public_uid')->nullable()->unique();
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

        Schema::create('visitor_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uid');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('uid');
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
    }

    private function createUser(
        string $email,
        string $nickname,
        int $status,
        ?int $avatar = null,
        ?string $ip = null
    ): User {
        return User::create([
            'ip' => $ip ?? '10.0.0.'.(User::query()->count() + 1),
            'nickname' => $nickname,
            'email' => $email,
            'password' => 'old-password',
            'avatar' => $avatar,
            'status' => $status,
            'is_subscribe' => 1,
        ]);
    }

    private function createRole(
        int $id,
        string $name,
        string $nickname,
        int $element,
        ?int $roleId6 = null,
        int $status = 1
    ): GameRole {
        return GameRole::create([
            'id' => $id,
            'role_id' => $id * 100 + 1,
            'role_id_1' => $id * 100 + 11,
            'role_id_3' => $id * 100 + 31,
            'role_id_6' => $roleId6,
            'element' => $element,
            'atk_type' => 1,
            'is_clan_battle' => false,
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
