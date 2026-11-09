<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\VisitorToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserAuth
{
    /**
     * 用户端统一认证：Sanctum 正式用户身份优先，Visitor Token 兜底。
     *
     * 解析顺序：
     * 1. Sanctum 正式用户 Token（含测试环境的 actingAs 与旧版访客
     *    Sanctum Token 兼容路径），识别为正式用户或旧版访客；
     * 2. 未命中 Sanctum 时，再按 visitor_tokens 表验证新式 Visitor Token，
     *    仅 status = 0 的访客可被识别；
     * 3. 两种凭证都无效时返回 401。
     *
     * 正式用户身份永远优先于访客身份，避免访客凭证遮蔽正式用户身份。
     * 认证成功后通过 userResolver 注入身份，控制器与 $this->uid()
     * 无需感知认证来源。
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Sanctum 优先：正式用户 Token、旧版访客 Sanctum Token、测试 actingAs。
        $principal = Auth::guard('sanctum')->user();

        if ($principal !== null) {
            return $this->proceed($request, $next, $principal);
        }

        // 2. Visitor Token 兜底：仅识别 status = 0 的访客。
        $bearerToken = $request->bearerToken();

        if (is_string($bearerToken) && $bearerToken !== '') {
            $user = $this->resolveVisitorUser($bearerToken);

            if ($user instanceof User) {
                return $this->proceed($request, $next, $user);
            }
        }

        // 3. 两种凭证都无效。
        return $this->unauthorized();
    }

    private function resolveVisitorUser(string $bearerToken): ?User
    {
        $record = VisitorToken::query()
            ->where('token_hash', VisitorToken::hashToken($bearerToken))
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

    private function proceed(
        Request $request,
        Closure $next,
        mixed $principal
    ): Response {
        $request->setUserResolver(static fn () => $principal);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'status' => 1,
            'code' => 401,
            'msg' => '未认证或登录状态已失效',
            'data' => [],
        ], 401);
    }
}
