<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VisitorToken;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserPasswordResetApiTest extends TestCase
{
    private User $user;

    private string $token;

    private int $currentTokenId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00'));
        $this->createTestTables();

        $this->user = $this->createUser('owner@example.com', 'old-password', 1);

        $newToken = $this->user->createToken('user-token');
        $this->token = $newToken->plainTextToken;
        $this->currentTokenId = $newToken->accessToken->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_registered_user_can_request_code_for_email(): void
    {
        $response = $this->postJson('/api/user/password/reset-code', [
            'email' => $this->user->email,
        ])
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.retry_after', 60)
            ->assertJsonPath('data.expires_in', 300);

        $this->assertArrayNotHasKey('code', $response->json('data'));

        $messages = $this->mailMessages();
        $this->assertCount(1, $messages);

        $message = $messages->last()->getOriginalMessage();
        $this->assertSame(
            'owner@example.com',
            $message->getTo()[0]->getAddress()
        );
        $this->assertSame('密码重置验证码', $message->getSubject());

        $code = $this->lastMailCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $payload = Cache::get($this->codeKey('owner@example.com'));

        $this->assertIsArray($payload);
        $this->assertSame(hash('sha256', $code), $payload['code_hash']);
        $this->assertSame($this->user->getKey(), $payload['user_id']);
        $this->assertArrayNotHasKey('code', $payload);
        $this->assertStringNotContainsString(
            $code,
            (string) json_encode($payload)
        );
    }

    public function test_unauthenticated_and_guest_users_can_use_password_reset(): void
    {
        $guest = $this->createUser('guest@example.com', 'old-password', 0);
        $plain = bin2hex(random_bytes(32));
        $record = VisitorToken::create([
            'uid' => $guest->getKey(),
            'token_hash' => hash('sha256', $plain),
        ]);
        $guestToken = $record->getKey().'|'.$plain;

        $this->postJson('/api/user/password/reset-code', [
            'email' => $this->user->email,
        ])->assertOk();

        $code = $this->lastMailCode();

        $this->withHeader('Authorization', 'Bearer '.$guestToken)
            ->postJson('/api/user/password/reset-code', [
                'email' => 'guest@example.com',
            ])
            ->assertOk();

        // 游客账号即使有邮箱也不会收到找回密码邮件。
        $this->assertCount(1, $this->mailMessages());

        $this->flushHeaders();
        Auth::forgetGuards();

        $response = $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => $code,
        ])->assertOk();

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $response->json('data.reset_token'),
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(
            Hash::check('new-password', $this->user->fresh()->password)
        );
    }

    public function test_send_code_is_limited_to_once_per_sixty_seconds(): void
    {
        $this->postJson('/api/user/password/reset-code', [
            'email' => $this->user->email,
        ])->assertOk();

        $this->postJson('/api/user/password/reset-code', [
            'email' => $this->user->email,
        ])
            ->assertStatus(429)
            ->assertJsonPath('msg', '验证码发送过于频繁，请稍后再试');

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:01:01'));

        $this->postJson('/api/user/password/reset-code', [
            'email' => $this->user->email,
        ])->assertOk();
    }

    public function test_password_reset_requests_require_a_valid_email(): void
    {
        $this->postJson('/api/user/password/reset-code')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/user/password/reset-code', [
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/user/password/verify-code', [
            'code' => '123456',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertCount(0, $this->mailMessages());
    }

    public function test_mail_failure_does_not_store_code_or_send_limit(): void
    {
        Mail::shouldReceive('html')
            ->once()
            ->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/user/password/reset-code', [
            'email' => $this->user->email,
        ])
            ->assertStatus(500)
            ->assertJsonPath('msg', '验证码发送失败，请稍后重试');

        $this->assertFalse(
            Cache::has($this->codeKey((string) $this->user->email))
        );
        $this->assertFalse(
            Cache::has($this->sendLimitKey((string) $this->user->email))
        );
    }

    public function test_correct_code_verifies_and_returns_one_time_token(): void
    {
        $code = $this->sendCode();

        $response = $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonPath('status', 0);

        $resetToken = $response->json('data.reset_token');

        $this->assertIsString($resetToken);
        $this->assertNotSame('', $resetToken);
        $this->assertSame(600, $response->json('data.expires_in'));
        $this->assertFalse(
            Cache::has($this->codeKey((string) $this->user->email))
        );

        $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => $code,
        ])->assertStatus(422);
    }

    public function test_wrong_code_is_rejected_and_attempts_are_limited(): void
    {
        $code = $this->sendCode();
        $wrongCode = $code === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/user/password/verify-code', [
                'email' => $this->user->email,
                'code' => $wrongCode,
            ])
                ->assertStatus(422)
                ->assertJsonPath('msg', '验证码错误，请重新输入');
        }

        $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => $wrongCode,
        ])
            ->assertStatus(429)
            ->assertJsonPath(
                'msg',
                '验证码错误次数过多，请稍后重新发送'
            );

        $this->assertFalse(
            Cache::has($this->codeKey((string) $this->user->email))
        );

        $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => $code,
        ])->assertStatus(422);
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->sendCode();

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:06:01'));

        $response = $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => $code,
        ])->assertStatus(422);

        $this->assertStringContainsString(
            '过期',
            (string) $response->json('msg')
        );
    }

    public function test_code_cannot_be_used_across_users_or_businesses(): void
    {
        $code = $this->sendCode();

        $this->postJson('/api/user/password/verify-code', [
            'email' => 'other@example.com',
            'code' => $code,
        ])->assertStatus(422);

        Cache::put(
            'register_code:owner@example.com',
            '123456',
            now()->addMinutes(5)
        );

        $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => '123456',
        ])->assertStatus(422);

        $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => $code,
        ])->assertOk();
    }

    public function test_reset_password_keeps_current_token_and_revokes_others(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token);

        $otherToken = $this->user->createToken('other-device');
        $resetToken = $this->verifyCode();

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertOk()
            ->assertJsonPath('msg', '密码重置成功');

        $this->assertTrue(
            Hash::check('new-password', $this->user->fresh()->password)
        );
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $this->currentTokenId,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);

        $this->getJson('/api/user/me')->assertOk();

        $this->withHeader(
            'Authorization',
            'Bearer '.$otherToken->plainTextToken
        );
        Auth::forgetGuards();

        $this->getJson('/api/user/me')
            ->assertUnauthorized();
    }

    public function test_reset_rejects_invalid_and_used_tokens(): void
    {
        $resetToken = $this->verifyCode();

        $this->putJson('/api/user/password/reset', [
            'reset_token' => 'invalid-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'msg',
                '重置凭证无效或已过期，请重新验证邮箱'
            );

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'msg',
                '重置凭证无效或已过期，请重新验证邮箱'
            );
    }

    public function test_reset_rejects_expired_token(): void
    {
        $resetToken = $this->verifyCode();

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:11:00'));

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'msg',
                '重置凭证无效或已过期，请重新验证邮箱'
            );
    }

    public function test_reset_token_resolves_its_own_account_without_login(): void
    {
        $resetToken = $this->verifyCode();

        $otherUser = $this->createUser(
            'other@example.com',
            'old-password',
            1
        );

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(
            Hash::check('new-password', $this->user->fresh()->password)
        );
        $this->assertTrue(
            Hash::check('old-password', $otherUser->fresh()->password)
        );
    }

    public function test_reset_password_validation_and_same_password_rejection(): void
    {
        $resetToken = $this->verifyCode();

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'old-password',
            'password_confirmation' => 'old-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('msg', '新密码不能与原密码相同');

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();
    }

    public function test_ordinary_password_change_does_not_revoke_other_tokens(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token);

        $otherToken = $this->user->createToken('other-device');

        $this->putJson('/api/user/password', [
            'current_password' => 'old-password',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $this->currentTokenId,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);
    }

    public function test_unknown_email_returns_generic_success_without_sending_mail(): void
    {
        $this->postJson('/api/user/password/reset-code', [
            'email' => 'nobody@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.retry_after', 60)
            ->assertJsonPath('data.expires_in', 300);

        $this->assertCount(0, $this->mailMessages());
        $this->assertTrue(
            Cache::has($this->sendLimitKey('nobody@example.com'))
        );

        $this->postJson('/api/user/password/reset-code', [
            'email' => 'nobody@example.com',
        ])
            ->assertStatus(429)
            ->assertJsonPath('msg', '验证码发送过于频繁，请稍后再试');
    }

    public function test_duplicate_registered_emails_are_not_reset(): void
    {
        $this->createUser('owner@example.com', 'another-password', 1);

        $this->postJson('/api/user/password/reset-code', [
            'email' => 'owner@example.com',
        ])->assertOk();

        $this->assertCount(0, $this->mailMessages());

        $this->postJson('/api/user/password/verify-code', [
            'email' => 'owner@example.com',
            'code' => '123456',
        ])->assertStatus(422);
    }

    public function test_reset_without_login_revokes_all_user_tokens(): void
    {
        $otherToken = $this->user->createToken('other-device');
        $resetToken = $this->verifyCode();

        $this->putJson('/api/user/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $this->currentTokenId,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);

        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/user/me')
            ->assertUnauthorized();
    }

    public function test_reset_email_matching_is_case_insensitive(): void
    {
        $this->postJson('/api/user/password/reset-code', [
            'email' => 'OWNER@EXAMPLE.COM',
        ])->assertOk();

        $this->postJson('/api/user/password/verify-code', [
            'email' => 'owner@example.com',
            'code' => $this->lastMailCode(),
        ])->assertOk();
    }

    private function sendCode(array $payload = []): string
    {
        $this->postJson('/api/user/password/reset-code', array_merge([
            'email' => $this->user->email,
        ], $payload))
            ->assertOk();

        return $this->lastMailCode();
    }

    private function verifyCode(): string
    {
        $response = $this->postJson('/api/user/password/verify-code', [
            'email' => $this->user->email,
            'code' => $this->sendCode(),
        ])->assertOk();

        return (string) $response->json('data.reset_token');
    }

    private function codeKey(string $email): string
    {
        return 'password_reset_code:'.$this->emailKey($email);
    }

    private function sendLimitKey(string $email): string
    {
        return 'password_reset_send_limit:'.$this->emailKey($email);
    }

    private function emailKey(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    private function mailMessages(): Collection
    {
        return Mail::mailer()
            ->getSymfonyTransport()
            ->messages();
    }

    private function lastMailCode(): string
    {
        $messages = $this->mailMessages();

        $this->assertGreaterThan(0, $messages->count());

        $message = $messages->last()->getOriginalMessage();
        $html = (string) ($message->getHtmlBody()
            ?? $message->getTextBody()
            ?? '');

        $this->assertMatchesRegularExpression(
            '/>\s*(\d{6})\s*</',
            $html
        );

        preg_match('/>\s*(\d{6})\s*</', $html, $matches);

        return $matches[1];
    }

    private function createTestTables(): void
    {
        foreach ([
            'personal_access_tokens',
            'visitor_tokens',
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
    }

    private function createUser(
        ?string $email,
        string $password,
        int $status,
        ?int $avatar = 105811
    ): User {
        return User::create([
            'ip' => '10.0.0.'.(User::query()->count() + 1),
            'nickname' => '测试用户',
            'email' => $email,
            'password' => $password,
            'avatar' => $avatar,
            'status' => $status,
            'is_subscribe' => 1,
        ]);
    }
}
