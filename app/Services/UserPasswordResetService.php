<?php

namespace App\Services;

use App\Exceptions\PasswordResetException;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class UserPasswordResetService
{
    /**
     * 发送密码找回验证码到当前用户绑定的邮箱。
     *
     * @return array{retry_after:int, expires_in:int}
     */
    public function sendCode(User $user): array
    {
        $email = trim((string) $user->email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PasswordResetException('当前账号未绑定有效邮箱，无法找回密码');
        }

        $lock = $this->acquireLock($this->sendLockKey($user));

        try {
            if (Cache::has($this->sendLimitKey($user))) {
                throw new PasswordResetException(
                    '验证码发送过于频繁，请稍后再试',
                    429
                );
            }

            // 新验证码会同时作废旧验证码和旧重置凭证。
            Cache::forget($this->codeKey($user));
            Cache::forget($this->activeTokenKey($user));

            $code = (string) random_int(100000, 999999);
            $ttlMinutes = max(
                1,
                (int) config('profile.password_reset_code_ttl_minutes', 5)
            );
            $expiresAt = now()->addMinutes($ttlMinutes);

            try {
                $this->sendCodeEmail($email, $code, $ttlMinutes);
            } catch (\Throwable $exception) {
                report($exception);

                throw new PasswordResetException(
                    '验证码发送失败，请稍后重试',
                    500
                );
            }

            Cache::put(
                $this->codeKey($user),
                [
                    'code_hash' => hash('sha256', $code),
                    'email' => $email,
                    'attempts' => 0,
                    'expires_at' => $expiresAt->getTimestamp(),
                ],
                $expiresAt
            );

            $interval = max(
                1,
                (int) config('profile.password_reset_send_interval_seconds', 60)
            );

            Cache::put(
                $this->sendLimitKey($user),
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
     * 校验验证码并签发一次性重置凭证。
     *
     * @return array{reset_token:string, expires_in:int}
     */
    public function verifyCode(User $user, string $code): array
    {
        $lock = $this->acquireLock($this->codeLockKey($user));

        try {
            $payload = Cache::get($this->codeKey($user));

            if (! is_array($payload) || ! isset($payload['code_hash'])) {
                throw new PasswordResetException(
                    '验证码错误或已过期，请重新发送'
                );
            }

            $expiresAt = (int) ($payload['expires_at'] ?? 0);

            if ($expiresAt <= now()->getTimestamp()) {
                Cache::forget($this->codeKey($user));

                throw new PasswordResetException(
                    '验证码已过期，请重新发送'
                );
            }

            if (($payload['email'] ?? null) !== $user->email) {
                Cache::forget($this->codeKey($user));

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
                Cache::forget($this->codeKey($user));

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
                    Cache::forget($this->codeKey($user));

                    throw new PasswordResetException(
                        '验证码错误次数过多，请稍后重新发送',
                        429
                    );
                }

                $payload['attempts'] = $attempts;

                Cache::put(
                    $this->codeKey($user),
                    $payload,
                    max(1, $expiresAt - now()->getTimestamp())
                );

                throw new PasswordResetException(
                    '验证码错误，请重新输入'
                );
            }

            // 验证码只能使用一次。
            Cache::forget($this->codeKey($user));

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
                    'email' => $user->email,
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
        User $user,
        string $resetToken,
        string $password
    ): void {
        $tokenHash = hash('sha256', $resetToken);
        $lock = $this->acquireLock($this->tokenLockKey($tokenHash));

        try {
            $activeTokenHash = Cache::get($this->activeTokenKey($user));
            $payload = Cache::get($this->tokenKey($tokenHash));

            if (
                ! is_string($activeTokenHash)
                || ! hash_equals($activeTokenHash, $tokenHash)
                || ! is_array($payload)
                || (int) ($payload['user_id'] ?? 0) !== (int) $user->getKey()
                || ($payload['email'] ?? null) !== $user->email
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

            DB::transaction(function () use ($user, $password): void {
                $user->password = $password;
                $user->save();

                $currentToken = $user->currentAccessToken();
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

    private function codeKey(User $user): string
    {
        return 'password_reset_code:'.$user->getKey();
    }

    private function sendLimitKey(User $user): string
    {
        return 'password_reset_send_limit:'.$user->getKey();
    }

    private function sendLockKey(User $user): string
    {
        return 'password_reset_send_lock:'.$user->getKey();
    }

    private function codeLockKey(User $user): string
    {
        return 'password_reset_code_lock:'.$user->getKey();
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
