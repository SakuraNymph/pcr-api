<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NicknameByteLength;
use App\Services\UserAuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class UserAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected UserAuthService $userAuthService
    ) {
    }

    /**
     * 游客初始化。
     *
     * 携带有效 Visitor Token 时复用同一访客；否则创建全新访客记录，
     * 返回访客 Token，供后续注册请求使用。
     */
    public function visitor(Request $request)
    {
        $bearerToken = $request->bearerToken();

        // 已注册用户不应被重新初始化为游客。
        $currentUser = $request->user('sanctum');

        if ($currentUser instanceof User && (int) $currentUser->status === 1) {
            return $this->error('当前用户已经注册，无需初始化游客身份', 409);
        }

        // 旧版访客 Sanctum Token 复用（遗留兼容）。
        if ($currentUser instanceof User
            && (int) $currentUser->status === 0
            && is_string($bearerToken)
            && $bearerToken !== ''
        ) {
            return $this->success([
                'visitor' => $currentUser->only(['id', 'status']),
                'accessToken' => $bearerToken,
            ], '游客身份初始化成功');
        }

        $result = $this->userAuthService->resolveVisitorSession(
            $bearerToken,
            $request->ip()
        );

        return $this->success($result, '游客身份初始化成功');
    }

    /**
     * 发送注册邮箱验证码。
     */
    public function sendRegisterCode(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:255',
        ]);

        $email = $data['email'];

        // 检查邮箱是否已经注册。
        $exists = User::where('email', $email)
            ->where('status', 1)
            ->exists();

        if ($exists) {
            return $this->error('该邮箱已经注册', 422);
        }

        // 同一邮箱 60 秒内只能发送一次验证码。
        $limitKey = 'register_code_limit:' . $email;

        if (Cache::has($limitKey)) {
            return $this->error('验证码发送过于频繁，请稍后再试', 429);
        }

        // 生成 6 位数字验证码。
        $code = (string) random_int(100000, 999999);

        // 验证码缓存 5 分钟。
        $codeKey = 'register_code:' . $email;

        Cache::put($codeKey, $code, now()->addMinutes(5));

        // 设置 60 秒发送间隔。
        Cache::put($limitKey, true, now()->addSeconds(60));

        try {
            // 发送 HTML 邮件。
            Mail::html(
                '<div style="margin:0;padding:32px 16px;background-color:#f1f5f9;font-family:Arial,'
                . 'Microsoft YaHei,sans-serif;color:#334155;">'
                . '<div style="max-width:460px;margin:0 auto;background-color:#ffffff;'
                . 'border-radius:12px;overflow:hidden;">'

                // 邮件顶部。
                . '<div style="padding:28px 20px;background-color:#2563eb;text-align:center;">'
                . '<div style="font-size:24px;font-weight:bold;color:#ffffff;">账号安全验证</div>'
                . '<div style="margin-top:8px;font-size:14px;color:#dbeafe;">'
                . 'PCR 作业站 · 账号注册'
                . '</div>'
                . '</div>'

                // 邮件主体。
                . '<div style="padding:32px 24px;">'
                . '<h2 style="margin:0 0 28px;text-align:center;font-size:22px;'
                . 'font-weight:bold;color:#1e293b;">欢迎加入 PCR 作业站</h2>'

                // 验证码标题。
                . '<p style="margin:0 0 16px;text-align:center;font-size:15px;color:#475569;">'
                . '您的注册验证码'
                . '</p>'

                // 验证码区域：六位数字间距完全一致，不进行分组。
                . '<div style="padding:24px 12px;background-color:#eff6ff;'
                . 'border:1px solid #bfdbfe;border-radius:10px;text-align:center;">'
                . '<div style="font-size:32px;font-weight:bold;letter-spacing:8px;'
                . 'line-height:1.5;color:#1d4ed8;white-space:nowrap;">'
                . e($code)
                . '</div>'
                . '</div>'

                // 验证码有效期。
                . '<p style="margin:20px 0 28px;text-align:center;font-size:14px;color:#64748b;">'
                . '请在 5 分钟内完成验证'
                . '</p>'

                // 邮件底部。
                . '<div style="border-top:1px solid #e2e8f0;padding-top:20px;'
                . 'text-align:center;font-size:12px;line-height:1.8;color:#94a3b8;">'
                . '<p style="margin:0 0 4px;">如果您没有进行注册操作，请忽略此邮件。</p>'
                . '<p style="margin:0;">此邮件由系统自动发送，请勿直接回复。</p>'
                . '</div>'

                . '</div>'
                . '</div>',
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('账号注册验证码');
                }
            );
        } catch (\Throwable $e) {
            // 邮件发送失败时，清除验证码及发送限制，允许用户重新尝试。
            Cache::forget($codeKey);
            Cache::forget($limitKey);

            report($e);

            return $this->error('验证码发送失败，请稍后重试', 500);
        }

        return $this->success([], '验证码已发送，请查收邮件');
    }

    /**
     * 邮箱注册。
     *
     * 必须携带游客 Token。
     * 验证邮箱验证码后，将当前游客记录升级为正式用户。
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'nickname' => [
                'required',
                'string',
                new NicknameByteLength(1, 24),
            ],
            'email' => 'required|string|email|max:255|unique:users,email',
            'code' => 'required|digits:6',
            'password' => 'required|string|min:6|max:16',
            'password_confirmation' => 'required|string|same:password',
        ]);

        // 获取该邮箱当前缓存的验证码。
        $codeKey = 'register_code:' . $data['email'];
        $cachedCode = Cache::get($codeKey);

        // 验证码不存在、已过期或不匹配时，拒绝注册。
        if (
            ! is_string($cachedCode)
            || ! hash_equals($cachedCode, (string) $data['code'])
        ) {
            return $this->error('验证码错误或已过期', 422);
        }

        // 验证码和确认密码只用于校验，不传入注册服务。
        unset($data['code'], $data['password_confirmation']);

        $user = $request->user();

        $result = $this->userAuthService->register(
            $user,
            $data,
            $request->ip()
        );

        // 注册成功后删除验证码，防止重复使用。
        Cache::forget($codeKey);

        return $this->success($result, '注册成功');
    }

    /**
     * 用户登录。
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => 'required|string',
        ]);

        $result = $this->userAuthService->login(
            $data['email'],
            $data['password'],
            $request->ip(),
            $request->bearerToken()
        );

        if (! $result) {
            return $this->error('邮箱或密码错误', 422);
        }

        return $this->success($result, '登录成功');
    }

    /**
     * 获取当前登录用户信息。
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return $this->success(
            $this->userAuthService->userData($user),
            '获取用户信息成功'
        );
    }

    /**
     * 用户退出登录。
     *
     * 只删除当前请求使用的 Token。
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success([], '已退出登录');
    }
}
