<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TeamQueryService;
use App\Services\UserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicTeamController extends Controller
{
    /**
     * 获取用户端当月系统作业列表。
     */
    public function index(
        TeamQueryService $teamQueryService,
        UserAuthService $userAuthService,
        Request $request
    ): JsonResponse {
        // 获取当前普通用户 UID。
        // 正式用户由 Sanctum 守卫解析；游客身份由 Visitor Token 兜底解析。
        // 未携带任何有效身份时为 null，仍然可以查询系统作业。
        $uid = $this->uid();

        if ($uid === null) {
            $bearerToken = $request->bearerToken();

            if (is_string($bearerToken) && $bearerToken !== '') {
                $uid = $userAuthService
                    ->findVisitorByToken($bearerToken)
                    ?->getKey();
            }
        }

        // 通过 Service 获取系统作业。
        $data = $teamQueryService->getSystemTeams($uid);

        return response()->json([
            'status' => 0,
            'code' => 200,
            'msg' => '获取作业列表成功',
            'data' => $data,
        ]);
    }
}
