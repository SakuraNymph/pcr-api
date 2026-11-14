<?php

namespace App\Services;

use App\Models\User;
use App\Models\VisitorToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserAuthService
{
    public function __construct(
        private readonly UserProfileService $userProfileService,
        private readonly PublicUidService $publicUidService
    ) {}

    /**
     * 访客会话解析：携带有效 Visitor Token 时复用同一访客，
     * 否则创建全新访客并签发新 Token。
     *
     * IP 不参与身份识别，仅记录为新访客行的审计字段。
     */
    public function resolveVisitorSession(
        ?string $bearerToken,
        string $ip
    ): array {
        if (is_string($bearerToken) && $bearerToken !== '') {
            $user = $this->findVisitorByToken($bearerToken);

            if ($user instanceof User) {
                return [
                    'visitor' => $user->only(['id', 'status']),
                    'accessToken' => $bearerToken,
                ];
            }
        }

        $user = User::create([
            'ip' => $this->normalizeIp($ip),
            'status' => 0,
            'is_subscribe' => 1,
        ]);

        $accessToken = $this->issueVisitorToken($user);

        return [
            'visitor' => $user->only(['id', 'status']),
            'accessToken' => $accessToken,
        ];
    }

    /**
     * 按令牌查找有效访客（status = 0）。
     */
    public function findVisitorByToken(string $token): ?User
    {
        $record = VisitorToken::query()
            ->where('token_hash', VisitorToken::hashToken($token))
            ->first();

        if (! $record) {
            return null;
        }

        $user = User::query()->find($record->uid);

        if (! $user instanceof User || (int) $user->status !== 0) {
            return null;
        }

        return $user;
    }

    /**
     * 为访客签发独立 Visitor Token，数据库只存哈希。
     *
     * @return string 形如 {visitor_tokens.id}|{random_hex} 的明文令牌
     */
    public function issueVisitorToken(User $user): string
    {
        $plain = bin2hex(random_bytes(32));

        $record = VisitorToken::create([
            'uid' => $user->getKey(),
            'token_hash' => hash('sha256', $plain),
        ]);

        return $record->getKey().'|'.$plain;
    }

    /**
     * 将游客记录升级为正式用户。
     *
     * 保留原来的用户 ID，更新用户状态，
     * 并撤销游客 Token，签发正式用户 Token。
     */
    public function register(User $user, array $data, string $ip): array
    {
        $ip = $this->normalizeIp($ip);

        return DB::transaction(function () use ($user, $data, $ip) {
            $user->fill([
                'ip' => $ip,
                'nickname' => $data['nickname'] ?? $user->nickname,
                'email' => $data['email'],
                'password' => $data['password'],
                'avatar' => $this->userProfileService->defaultAvatarId(),
                'status' => 1,
                'is_subscribe' => $user->is_subscribe ?? 1,
            ]);

            $user->save();

            // 按当前发放策略分配唯一六位 UID。
            $this->publicUidService->assign($user);

            // 撤销旧凭证：新式 Visitor Token 与旧版 Sanctum Token。
            VisitorToken::query()
                ->where('uid', $user->getKey())
                ->delete();
            $user->tokens()->delete();

            $user->refresh();

            $token = $user->createToken('user-token')->plainTextToken;

            return [
                'user' => $this->userData($user),
                'accessToken' => $token,
            ];
        });
    }

    /**
     * 邮箱登录。
     *
     * 只有 status = 1 的正式用户允许登录。
     */
    public function login(
        string $email,
        string $password,
        string $ip,
        ?string $bearerToken = null
    ): ?array {
        $user = User::query()
            ->where('email', $email)
            ->where('status', 1)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        // IP 仅作审计字段更新，不参与身份识别。
        $user->ip = $this->normalizeIp($ip);
        $user->save();

        // 撤销登录请求携带的访客凭证，避免遗留访客身份。
        if (is_string($bearerToken) && $bearerToken !== '') {
            VisitorToken::query()
                ->where('token_hash', VisitorToken::hashToken($bearerToken))
                ->delete();
        }

        $token = $user->createToken('user-token')->plainTextToken;

        return [
            'user' => $this->userData($user),
            'accessToken' => $token,
        ];
    }

    /**
     * 整理返回给用户端的字段。
     *
     * 不向用户端返回 IP、密码或数据库内部 Token 字段。
     */
    public function userData(User $user): array
    {
        return $this->userProfileService->userData($user);
    }

    private function normalizeIp(string $ip): string
    {
        $packedIp = @inet_pton($ip);

        if ($packedIp === false) {
            throw ValidationException::withMessages([
                'ip' => '请求 IP 地址格式无效。',
            ]);
        }

        return inet_ntop($packedIp) ?: $ip;
    }
}
