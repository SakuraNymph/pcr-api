<?php

namespace App\Services;

use App\Exceptions\PasswordResetException;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class UserPasswordResetService
{
    /**
     * 向指定注册邮箱发送密码找回验证码。
     *
     * 未匹配到正式用户时也返回相同结构，避免暴露邮箱是否已注册。
     *
     * @return array{retry_after:int, expires_in:int}
     */
    public function sendCode(string $email): array
    {
        $email = $this->normalizeEmail($email);
        $emailKey = $this->emailKey($email);
        $lock = $this->acquireLock($this->sendLockKey($emailKey));

        try {
            if (Cache::has($this->sendLimitKey($emailKey))) {
                throw new PasswordResetException(
                    '验证码发送过于频繁，请稍后再试',
                    429
                );
            }

            $ttlMinutes = max(
                1,
                (int) config('profile.password_reset_code_ttl_minutes', 5)
            );
            $expiresAt = now()->addMinutes($ttlMinutes);
            $user = $this->findRegisteredUserByEmail($email);

            if ($user instanceof User) {
                $boundEmail = trim((string) $user->email);

                // 新验证码会同时作废旧验证码和旧重置凭证。
                Cache::forget($this->codeKey($emailKey));
                Cache::forget($this->activeTokenKey($user));

                $code = (string) random_int(100000, 999999);

                try {
                    $this->sendCodeEmail($boundEmail, $code, $ttlMinutes);
                } catch (\Throwable $exception) {
                    report($exception);

                    throw new PasswordResetException(
                        '验证码发送失败，请稍后重试',
                        500
                    );
                }

                Cache::put(
                    $this->codeKey($emailKey),
                    [
                        'code_hash' => hash('sha256', $code),
                        'user_id' => (int) $user->getKey(),
                        'email' => $boundEmail,
                        'attempts' => 0,
                        'expires_at' => $expiresAt->getTimestamp(),
                    ],
                    $expiresAt
                );
            }

            $interval = max(
                1,
                (int) config('profile.password_reset_send_interval_seconds', 60)
            );

            Cache::put(
                $this->sendLimitKey($emailKey),
                true,
                now()->addSeconds($interval)
            );

            return [
                'retry_after' => $interval,
                'expires_in' => max(
                    1,
                    $expiresAt->getTimestamp() - now()->getTimestamp()
                ),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * 校验指定邮箱的验证码并签发一次性重置凭证。
     *
     * @return array{reset_token:string, expires_in:int}
     */
    public function verifyCode(string $email, string $code): array
    {
        $email = $this->normalizeEmail($email);
        $emailKey = $this->emailKey($email);
        $lock = $this->acquireLock($this->codeLockKey($emailKey));

        try {
            $payload = Cache::get($this->codeKey($emailKey));

            if (! is_array($payload) || ! isset($payload['code_hash'])) {
                throw new PasswordResetException(
                    '验证码错误或已过期，请重新发送'
                );
            }

            $expiresAt = (int) ($payload['expires_at'] ?? 0);

            if ($expiresAt <= now()->getTimestamp()) {
                Cache::forget($this->codeKey($emailKey));

                throw new PasswordResetException(
                    '验证码已过期，请重新发送'
                );
            }

            $user = User::query()
                ->where('status', 1)
                ->find((int) ($payload['user_id'] ?? 0));

            if (
                ! $user instanceof User
                || $this->normalizeEmail((string) $user->email)
                    !== $this->normalizeEmail(
                        (string) ($payload['email'] ?? '')
                    )
                || $this->normalizeEmail(
                    (string) ($payload['email'] ?? '')
                ) !== $email
            ) {
                Cache::forget($this->codeKey($emailKey));

                throw new PasswordResetException(
                    '验证码已失效，请重新发送'
                );
            }

            $maxAttempts = max(
                1,
                (int) config('profile.password_reset_max_attempts', 5)
            );
            $attempts = (int) ($payload['attempts'] ?? 0);

            if ($attempts >= $maxAttempts) {
                Cache::forget($this->codeKey($emailKey));

                throw new PasswordResetException(
                    '验证码错误次数过多，请稍后重新发送',
                    429
                );
            }

            if (! hash_equals(
                (string) $payload['code_hash'],
                hash('sha256', $code)
            )) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    Cache::forget($this->codeKey($emailKey));

                    throw new PasswordResetException(
                        '验证码错误次数过多，请稍后重新发送',
                        429
                    );
                }

                $payload['attempts'] = $attempts;

                Cache::put(
                    $this->codeKey($emailKey),
                    $payload,
                    max(1, $expiresAt - now()->getTimestamp())
                );

                throw new PasswordResetException(
                    '验证码错误，请重新输入'
                );
            }

            // 验证码只能使用一次。
            Cache::forget($this->codeKey($emailKey));

            $token = Str::random(64);
            $tokenHash = hash('sha256', $token);
            $tokenTtlMinutes = max(
                1,
                (int) config('profile.password_reset_token_ttl_minutes', 10)
            );
            $tokenExpiresAt = now()->addMinutes($tokenTtlMinutes);

            Cache::forget($this->activeTokenKey($user));
            Cache::put(
                $this->tokenKey($tokenHash),
                [
                    'user_id' => (int) $user->getKey(),
                    'email' => trim((string) $user->email),
                ],
                $tokenExpiresAt
            );
            Cache::put(
                $this->activeTokenKey($user),
                $tokenHash,
                $tokenExpiresAt
            );

            return [
                'reset_token' => $token,
                'expires_in' => max(
                    1,
                    $tokenExpiresAt->getTimestamp() - now()->getTimestamp()
                ),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * 使用一次性重置凭证修改密码，并撤销其他设备的 Token。
     */
    public function resetPassword(
        string $resetToken,
        string $password,
        ?User $currentUser = null
    ): void {
        $tokenHash = hash('sha256', $resetToken);
        $lock = $this->acquireLock($this->tokenLockKey($tokenHash));

        try {
            $payload = Cache::get($this->tokenKey($tokenHash));

            if (! is_array($payload)) {
                throw new PasswordResetException(
                    '重置凭证无效或已过期，请重新验证邮箱'
                );
            }

            $user = User::query()
                ->where('status', 1)
                ->find((int) ($payload['user_id'] ?? 0));

            if (
                ! $user instanceof User
                || $this->normalizeEmail((string) $user->email)
                    !== $this->normalizeEmail(
                        (string) ($payload['email'] ?? '')
                    )
            ) {
                throw new PasswordResetException(
                    '重置凭证无效或已过期，请重新验证邮箱'
                );
            }

            $activeTokenHash = Cache::get($this->activeTokenKey($user));

            if (
                ! is_string($activeTokenHash)
                || ! hash_equals($activeTokenHash, $tokenHash)
            ) {
                throw new PasswordResetException(
                    '重置凭证无效或已过期，请重新验证邮箱'
                );
            }

            if (Hash::check($password, (string) $user->password)) {
                throw new PasswordResetException(
                    '新密码不能与原密码相同'
                );
            }

            // 在事务前消费凭证，避免并发请求重复使用同一个凭证。
            Cache::forget($this->tokenKey($tokenHash));
            Cache::forget($this->activeTokenKey($user));

            DB::transaction(function () use (
                $user,
                $password,
                $currentUser
            ): void {
                $user->password = $password;
                $user->save();

                $currentToken = $currentUser instanceof User
                    && (int) $currentUser->getKey() === (int) $user->getKey()
                    ? $currentUser->currentAccessToken()
                    : null;
                $currentTokenId = $currentToken instanceof PersonalAccessToken
                    ? $currentToken->getKey()
                    : null;

                $query = $user->tokens();

                if ($currentTokenId !== null) {
                    $query->where('id', '<>', $currentTokenId);
                }

                $query->delete();
            });
        } finally {
            $lock->release();
        }
    }

    private function findRegisteredUserByEmail(string $email): ?User
    {
        $users = User::query()
            ->where('status', 1)
            ->whereRaw(
                'LOWER(email) = ?',
                [$this->normalizeEmail($email)]
            )
            ->limit(2)
            ->get();

        if ($users->count() > 1) {
            Log::warning(
                'Password reset email matched multiple registered users',
                [
                    'user_ids' => $users->pluck('id')->all(),
                ]
            );

            return null;
        }

        return $users->first();
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function sendCodeEmail(
        string $email,
        string $code,
        int $ttlMinutes
    ): void {
        Mail::html(
            '<div style="margin:0;padding:32px 16px;background-color:#f1f5f9;'
            .'font-family:Arial,Microsoft YaHei,sans-serif;color:#334155;">'
            .'<div style="max-width:460px;margin:0 auto;background-color:#ffffff;'
            .'border-radius:12px;overflow:hidden;">'
            .'<div style="padding:28px 20px;background-color:#2563eb;'
            .'text-align:center;">'
            .'<div style="font-size:24px;font-weight:bold;color:#ffffff;">'
            .'账号安全验证'
            .'</div>'
            .'<div style="margin-top:8px;font-size:14px;color:#dbeafe;">'
            .'PCR 作业站 · 找回密码'
            .'</div>'
            .'</div>'
            .'<div style="padding:32px 24px;">'
            .'<h2 style="margin:0 0 28px;text-align:center;font-size:22px;'
            .'font-weight:bold;color:#1e293b;">重置账号密码</h2>'
            .'<p style="margin:0 0 16px;text-align:center;font-size:15px;'
            .'color:#475569;">您的密码重置验证码</p>'
            .'<div style="padding:24px 12px;background-color:#eff6ff;'
            .'border:1px solid #bfdbfe;border-radius:10px;text-align:center;">'
            .'<div style="font-size:32px;font-weight:bold;letter-spacing:8px;'
            .'line-height:1.5;color:#1d4ed8;white-space:nowrap;">'
            .e($code)
            .'</div>'
            .'</div>'
            .'<p style="margin:20px 0 28px;text-align:center;font-size:14px;'
            .'color:#64748b;">请在 '.$ttlMinutes.' 分钟内完成验证</p>'
            .'<div style="border-top:1px solid #e2e8f0;padding-top:20px;'
            .'text-align:center;font-size:12px;line-height:1.8;color:#94a3b8;">'
            .'<p style="margin:0 0 4px;">如果您没有进行密码重置操作，请忽略此邮件。</p>'
            .'<p style="margin:0;">此邮件由系统自动发送，请勿直接回复。</p>'
            .'</div>'
            .'</div>'
            .'</div>',
            function ($message) use ($email): void {
                $message->to($email)
                    ->subject('密码重置验证码');
            }
        );
    }

    private function acquireLock(string $key): Lock
    {
        $lock = Cache::lock($key, 15);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new PasswordResetException(
                '操作过于频繁，请稍后再试',
                429
            );
        }

        return $lock;
    }

    private function emailKey(string $email): string
    {
        return hash('sha256', $this->normalizeEmail($email));
    }

    private function codeKey(string $emailKey): string
    {
        return 'password_reset_code:'.$emailKey;
    }

    private function sendLimitKey(string $emailKey): string
    {
        return 'password_reset_send_limit:'.$emailKey;
    }

    private function sendLockKey(string $emailKey): string
    {
        return 'password_reset_send_lock:'.$emailKey;
    }

    private function codeLockKey(string $emailKey): string
    {
        return 'password_reset_code_lock:'.$emailKey;
    }

    private function activeTokenKey(User $user): string
    {
        return 'password_reset_active_token:'.$user->getKey();
    }

    private function tokenKey(string $tokenHash): string
    {
        return 'password_reset_token:'.$tokenHash;
    }

    private function tokenLockKey(string $tokenHash): string
    {
        return 'password_reset_token_lock:'.$tokenHash;
    }
}
