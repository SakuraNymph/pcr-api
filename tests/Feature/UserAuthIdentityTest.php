<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Models\UserGuideConfig;
use App\Models\VisitorToken;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAuthIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00'));

        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================== 访客初始化 ====================

    public function test_visitor_initialization_creates_guest_with_hashed_token(): void
    {
        $response = $this->postJson('/api/user/visitor')
            ->assertOk()
            ->assertJsonPath('status', 0);

        $token = $response->json('data.accessToken');
        $visitor = $response->json('data.visitor');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        $this->assertSame(0, $visitor['status']);

        $user = User::query()->find($visitor['id']);
        $this->assertNotNull($user);
        $this->assertSame(0, (int) $user->status);
        $this->assertNull($user->public_uid);

        $this->assertDatabaseCount('visitor_tokens', 1);

        $record = VisitorToken::query()->first();
        $this->assertSame(VisitorToken::hashToken($token), $record->token_hash);

        $plain = explode('|', $token, 2)[1];
        $this->assertStringNotContainsString($plain, $record->token_hash);
    }

    public function test_visitor_initialization_reuses_valid_visitor_token(): void
    {
        $first = $this->postJson('/api/user/visitor')
            ->assertOk()
            ->json('data');

        $second = $this->withHeader(
            'Authorization',
            'Bearer '.$first['accessToken']
        )
            ->postJson('/api/user/visitor')
            ->assertOk()
            ->json('data');

        $this->assertSame($first['visitor']['id'], $second['visitor']['id']);
        $this->assertSame($first['accessToken'], $second['accessToken']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('visitor_tokens', 1);
    }

    public function test_visitor_initialization_rejects_registered_user(): void
    {
        $user = $this->createUser('owner@example.com', 'secret', 1);
        $token = $user->createToken('user-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/visitor')
            ->assertStatus(409);
    }

    public function test_two_visitors_may_share_the_same_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->postJson('/api/user/visitor')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->postJson('/api/user/visitor')
            ->assertOk();

        $this->assertDatabaseCount('users', 2);
        $this->assertSame(
            2,
            User::query()
                ->where('ip', '203.0.113.5')
                ->where('status', 0)
                ->count()
        );
    }

    // ==================== 认证中间件矩阵 ====================

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/user/me')->assertUnauthorized();
        $this->getJson('/api/user/role-pools')->assertUnauthorized();
        $this->getJson('/api/user/guide-config?location=list')
            ->assertUnauthorized();
    }

    public function test_visitor_token_allows_any_routes_but_not_registered(): void
    {
        [, $visitorToken] = $this->makeVisitor();

        $response = $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=list')
            ->assertOk();
        $this->assertTrue($response->json());

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/me')
            ->assertUnauthorized();
    }

    public function test_completing_post_guide_does_not_disable_list_guide(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=post')
            ->assertOk()
            ->assertContent('true');

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/guide-config', ['location' => 'post'])
            ->assertOk()
            ->assertJsonPath('status', 0);

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=post')
            ->assertOk()
            ->assertContent('false');

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=list')
            ->assertOk()
            ->assertContent('true');

        $configuration = UserGuideConfig::query()
            ->where('uid', $guest->getKey())
            ->firstOrFail()
            ->configuration;

        $this->assertFalse($configuration['post']);
        $this->assertTrue($configuration['list']);
    }

    public function test_completing_team_guide_does_not_disable_post_or_list(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=team')
            ->assertOk()
            ->assertContent('true');

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/guide-config', ['location' => 'team'])
            ->assertOk()
            ->assertJsonPath('status', 0);

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=team')
            ->assertOk()
            ->assertContent('false');

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=post')
            ->assertOk()
            ->assertContent('true');

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=list')
            ->assertOk()
            ->assertContent('true');

        $configuration = UserGuideConfig::query()
            ->where('uid', $guest->getKey())
            ->firstOrFail()
            ->configuration;

        $this->assertFalse($configuration['team']);
        $this->assertTrue($configuration['post']);
        $this->assertTrue($configuration['list']);
    }

    public function test_completing_group_guide_does_not_disable_other_guides(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=group')
            ->assertOk()
            ->assertContent('true');

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/guide-config', ['location' => 'group'])
            ->assertOk()
            ->assertJsonPath('status', 0);

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=group')
            ->assertOk()
            ->assertContent('false');

        foreach (['list', 'post', 'team', 'account'] as $location) {
            $this->withHeader('Authorization', 'Bearer '.$visitorToken)
                ->getJson('/api/user/guide-config?location='.$location)
                ->assertOk()
                ->assertContent('true');
        }

        $configuration = UserGuideConfig::query()
            ->where('uid', $guest->getKey())
            ->firstOrFail()
            ->configuration;

        $this->assertFalse($configuration['group']);
        $this->assertTrue($configuration['list']);
        $this->assertTrue($configuration['post']);
        $this->assertTrue($configuration['team']);
        $this->assertTrue($configuration['account']);
    }

    public function test_account_guide_defaults_and_is_isolated_from_other_guides(): void
    {
        $user = $this->createUser('account-guide@example.com', 'secret', 1);
        $token = $user->createToken('user-token')->plainTextToken;
        $authorization = 'Bearer '.$token;

        $this->withHeader('Authorization', $authorization)
            ->getJson('/api/user/guide-config?location=account')
            ->assertOk()
            ->assertContent('true');

        $configuration = UserGuideConfig::query()
            ->where('uid', $user->getKey())
            ->firstOrFail()
            ->configuration;

        foreach (['account', 'list', 'post', 'team', 'group'] as $location) {
            $this->assertTrue($configuration[$location]);
        }

        $this->withHeader('Authorization', $authorization)
            ->postJson('/api/user/guide-config', ['location' => 'account'])
            ->assertOk()
            ->assertJsonPath('status', 0);

        $this->withHeader('Authorization', $authorization)
            ->getJson('/api/user/guide-config?location=account')
            ->assertOk()
            ->assertContent('false');

        $this->withHeader('Authorization', $authorization)
            ->getJson('/api/user/guide-config?location=list')
            ->assertOk()
            ->assertContent('true');

        $configuration = UserGuideConfig::query()
            ->where('uid', $user->getKey())
            ->firstOrFail()
            ->configuration;

        $this->assertFalse($configuration['account']);
        $this->assertTrue($configuration['list']);
        $this->assertTrue($configuration['post']);
        $this->assertTrue($configuration['team']);
        $this->assertTrue($configuration['group']);

        $this->withHeader('Authorization', $authorization)
            ->postJson('/api/user/guide-config', ['location' => 'reset'])
            ->assertOk()
            ->assertJsonPath('status', 0);

        $this->withHeader('Authorization', $authorization)
            ->getJson('/api/user/guide-config?location=account')
            ->assertOk()
            ->assertContent('true');
    }

    public function test_guide_reset_endpoint_restores_all_defaults(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        UserGuideConfig::create([
            'uid' => $guest->getKey(),
            'configuration' => array_fill_keys(UserGuideConfig::LOCATIONS, false),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/guide-config/reset')
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('code', 200)
            ->assertJsonPath('msg', '新手指引已重置')
            ->assertJsonPath('data', null);

        $configuration = UserGuideConfig::query()
            ->where('uid', $guest->getKey())
            ->firstOrFail()
            ->configuration;

        foreach (UserGuideConfig::LOCATIONS as $location) {
            $this->assertTrue($configuration[$location]);
        }

        foreach (UserGuideConfig::LOCATIONS as $location) {
            $this->withHeader('Authorization', 'Bearer '.$visitorToken)
                ->getJson('/api/user/guide-config?location='.$location)
                ->assertOk()
                ->assertContent('true');
        }
    }

    public function test_guide_reset_endpoint_creates_default_record_when_missing(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        $this->assertDatabaseMissing('user_guide_configs', [
            'uid' => $guest->getKey(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/guide-config/reset')
            ->assertOk()
            ->assertJsonPath('status', 0);

        $this->assertDatabaseHas('user_guide_configs', [
            'uid' => $guest->getKey(),
        ]);

        $configuration = UserGuideConfig::query()
            ->where('uid', $guest->getKey())
            ->firstOrFail()
            ->configuration;

        foreach (UserGuideConfig::LOCATIONS as $location) {
            $this->assertTrue($configuration[$location]);
        }
    }

    public function test_guide_reset_endpoint_only_resets_current_user(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();
        $other = $this->createUser('other@example.com', 'secret', 0);

        foreach ([$guest, $other] as $user) {
            UserGuideConfig::create([
                'uid' => $user->getKey(),
                'configuration' => array_fill_keys(UserGuideConfig::LOCATIONS, false),
            ]);
        }

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/guide-config/reset')
            ->assertOk()
            ->assertJsonPath('status', 0);

        $currentConfiguration = UserGuideConfig::query()
            ->where('uid', $guest->getKey())
            ->firstOrFail()
            ->configuration;
        $otherConfiguration = UserGuideConfig::query()
            ->where('uid', $other->getKey())
            ->firstOrFail()
            ->configuration;

        $this->assertTrue($currentConfiguration['list']);
        $this->assertFalse($otherConfiguration['list']);
    }

    public function test_registered_user_can_reset_guide_config(): void
    {
        $user = $this->createUser('owner@example.com', 'secret', 1);
        $token = $user->createToken('user-token')->plainTextToken;

        UserGuideConfig::create([
            'uid' => $user->getKey(),
            'configuration' => array_fill_keys(UserGuideConfig::LOCATIONS, false),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/guide-config/reset')
            ->assertOk()
            ->assertJsonPath('status', 0);

        $configuration = UserGuideConfig::query()
            ->where('uid', $user->getKey())
            ->firstOrFail()
            ->configuration;

        $this->assertTrue($configuration['list']);
    }

    public function test_guide_reset_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/user/guide-config/reset')
            ->assertUnauthorized();
    }

    public function test_update_guide_config_reset_location_still_resets_all(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        UserGuideConfig::create([
            'uid' => $guest->getKey(),
            'configuration' => array_fill_keys(UserGuideConfig::LOCATIONS, false),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/guide-config', ['location' => 'reset'])
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('msg', '新手指引已重置');

        $configuration = UserGuideConfig::query()
            ->where('uid', $guest->getKey())
            ->firstOrFail()
            ->configuration;

        foreach (UserGuideConfig::LOCATIONS as $location) {
            $this->assertTrue($configuration[$location]);
        }
    }

    public function test_visitor_token_reaches_guest_only_register_validation(): void
    {
        [, $visitorToken] = $this->makeVisitor();

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/register', [])
            ->assertUnprocessable();
    }

    public function test_registered_token_is_rejected_on_guest_only_route(): void
    {
        $user = $this->createUser('owner@example.com', 'secret', 1);
        $token = $user->createToken('user-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/register', [])
            ->assertForbidden();
    }

    public function test_admin_token_is_rejected_on_user_routes(): void
    {
        $admin = Admin::create([
            'username' => 'auth-admin',
            'password' => 'secret',
            'nickname' => 'Auth Admin',
            'status' => 1,
        ]);
        $adminToken = $admin->createToken('admin-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/user/me')
            ->assertForbidden();
    }

    public function test_registered_user_token_wins_over_conflicting_visitor_record(): void
    {
        $registered = $this->createUser('owner@example.com', 'secret', 1);
        $token = $registered->createToken('user-token')->plainTextToken;
        $plain = explode('|', $token, 2)[1];

        // 人为构造冲突：同一明文的哈希同时存在于 visitor_tokens，
        // 且指向另一个访客。正式用户身份必须优先，不能被访客身份遮蔽。
        $guest = $this->createUser('guest@example.com', 'old-password', 0);
        VisitorToken::create([
            'uid' => $guest->getKey(),
            'token_hash' => hash('sha256', $plain),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user/me')
            ->assertOk()
            ->assertJsonPath('data.id', $registered->getKey());
    }

    public function test_visitor_token_of_registered_user_is_not_accepted(): void
    {
        $registered = $this->createUser('owner@example.com', 'secret', 1);

        $plain = bin2hex(random_bytes(32));
        $record = VisitorToken::create([
            'uid' => $registered->getKey(),
            'token_hash' => hash('sha256', $plain),
        ]);
        $staleToken = $record->getKey().'|'.$plain;

        $this->withHeader('Authorization', 'Bearer '.$staleToken)
            ->getJson('/api/user/guide-config?location=list')
            ->assertUnauthorized();
    }

    public function test_uid_resolves_correctly_for_visitor_and_registered_user(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        UserGuideConfig::create([
            'uid' => $guest->getKey(),
            'configuration' => ['list' => false],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=list')
            ->assertOk();
        $response->assertContent('false');

        $user = $this->createUser('owner@example.com', 'secret', 1);
        $userToken = $user->createToken('user-token')->plainTextToken;

        $this->flushHeaders();
        $response = $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->getJson('/api/user/guide-config?location=list')
            ->assertOk();
        $this->assertTrue($response->json());
    }

    // ==================== 注册升级 ====================

    public function test_registration_keeps_uid_assigns_public_uid_and_switches_credentials(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        UserGuideConfig::create([
            'uid' => $guest->getKey(),
            'configuration' => ['list' => false],
        ]);

        Cache::put(
            'register_code:new@example.com',
            '123456',
            now()->addMinutes(5)
        );

        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$visitorToken
        )
            ->postJson('/api/user/register', [
                'nickname' => '新注册用户',
                'email' => 'new@example.com',
                'code' => '123456',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->assertJsonPath('status', 0);

        $session = $response->json('data');

        // 保留原 users.id。
        $this->assertSame($guest->getKey(), $session['user']['id']);
        $this->assertSame(1, $session['user']['status']);

        // 六位唯一 public_uid。
        $publicUid = $session['user']['public_uid'];
        $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', (string) $publicUid);

        // 旧访客 Token 失效。
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->getJson('/api/user/guide-config?location=list')
            ->assertUnauthorized();

        // 新 user-token 可用，业务数据（同 uid 的指引配置）保留。
        $this->flushHeaders();
        $response = $this->withHeader(
            'Authorization',
            'Bearer '.$session['accessToken']
        )
            ->getJson('/api/user/guide-config?location=list')
            ->assertOk();
        $response->assertContent('false');

        $this->assertDatabaseHas('user_guide_configs', [
            'uid' => $guest->getKey(),
        ]);
        $this->assertDatabaseCount('visitor_tokens', 0);
    }

    public function test_duplicate_public_uid_is_rejected_by_unique_index(): void
    {
        $first = $this->createUser('a@example.com', 'secret', 1);
        $second = $this->createUser('b@example.com', 'secret', 1);

        $first->public_uid = 123456;
        $first->save();

        $second->public_uid = 123456;

        $this->assertThrows(
            fn () => $second->save(),
            UniqueConstraintViolationException::class
        );
    }

    // ==================== 登录 ====================

    public function test_login_allows_users_sharing_one_ip(): void
    {
        $this->createUser('a@example.com', 'secret', 1, '203.0.113.9');
        $this->createUser('b@example.com', 'secret', 1, '203.0.113.9');

        $this->postJson('/api/user/login', [
            'email' => 'b@example.com',
            'password' => 'secret',
        ])
            ->assertOk()
            ->assertJsonPath('status', 0);
    }

    public function test_login_revokes_carried_visitor_token_without_merging(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();
        $user = $this->createUser('a@example.com', 'secret', 1);

        $this->withHeader('Authorization', 'Bearer '.$visitorToken)
            ->postJson('/api/user/login', [
                'email' => 'a@example.com',
                'password' => 'secret',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->getKey());

        $this->assertDatabaseCount('visitor_tokens', 0);

        $guest->refresh();
        $this->assertSame(0, (int) $guest->status);
        $this->assertNotSame($guest->getKey(), $user->getKey());
    }

    // ==================== 退出 ====================

    public function test_logout_invalidates_token_and_next_visitor_is_fresh(): void
    {
        [$guest, $visitorToken] = $this->makeVisitor();

        Cache::put(
            'register_code:new@example.com',
            '123456',
            now()->addMinutes(5)
        );

        $register = $this->withHeader(
            'Authorization',
            'Bearer '.$visitorToken
        )
            ->postJson('/api/user/register', [
                'nickname' => '新注册用户',
                'email' => 'new@example.com',
                'code' => '123456',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->json('data');

        $userToken = $register['accessToken'];

        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->postJson('/api/user/logout')
            ->assertOk();

        Auth::forgetGuards();

        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->getJson('/api/user/me')
            ->assertUnauthorized();

        Auth::forgetGuards();

        $this->flushHeaders();
        $fresh = $this->postJson('/api/user/visitor')
            ->assertOk()
            ->json('data');

        $this->assertNotSame($guest->getKey(), $fresh['visitor']['id']);
        $this->assertSame(0, $fresh['visitor']['status']);
    }

    // ==================== 旧版兼容 ====================

    public function test_legacy_sanctum_visitor_token_still_authenticates(): void
    {
        $guest = $this->createUser('guest@example.com', 'old-password', 0);
        $legacyToken = $guest->createToken('visitor-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$legacyToken)
            ->getJson('/api/user/guide-config?location=list')
            ->assertOk();
        $this->assertTrue($response->json());

        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$legacyToken)
            ->postJson('/api/user/visitor')
            ->assertOk()
            ->assertJsonPath('data.visitor.id', $guest->getKey());
    }

    // ==================== 工具方法 ====================

    private function makeVisitor(): array
    {
        $data = $this->postJson('/api/user/visitor')
            ->assertOk()
            ->json('data');

        return [
            User::query()->findOrFail($data['visitor']['id']),
            $data['accessToken'],
        ];
    }

    private function createUser(
        string $email,
        string $password,
        int $status,
        ?string $ip = null,
        ?int $publicUid = null
    ): User {
        return User::create([
            'ip' => $ip ?? '10.0.0.'.(User::query()->count() + 1),
            'nickname' => '测试用户',
            'email' => $email,
            'password' => $password,
            'avatar' => 105811,
            'public_uid' => $publicUid,
            'status' => $status,
            'is_subscribe' => 1,
        ]);
    }

    private function createTestTables(): void
    {
        foreach ([
            'personal_access_tokens',
            'visitor_tokens',
            'user_guide_configs',
            'admins',
            'users',
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

        Schema::create('user_guide_configs', function (Blueprint $table): void {
            $table->unsignedInteger('uid')->primary();
            $table->json('configuration');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('password');
            $table->string('nickname');
            $table->tinyInteger('status')->default(1);
            $table->rememberToken();
            $table->timestamps();
        });
    }
}
