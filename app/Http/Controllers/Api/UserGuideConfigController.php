<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserGuideConfig;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserGuideConfigController extends Controller
{
    use ApiResponse;

    /**
     * 获取指定新手指引状态
     *
     * true：不需要开启新手指引
     * false：用户跳过后，不再开启新手指引
     */
    public function show(Request $request)
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $validator = Validator::make($request->all(), [
            'location' => [
                'required',
                'string',
                'in:' . implode(',', UserGuideConfig::LOCATIONS),
            ],
        ]);

        if ($validator->fails()) {
            return $this->error('请求参数错误', 422, $validator->errors());
        }

        $location = $validator->validated()['location'];

        // 查询或初始化当前用户的新手指引配置。
        $config = UserGuideConfig::firstOrCreate(
            ['uid' => $uid],
            ['configuration' => UserGuideConfig::DEFAULT_CONFIGURATION]
        );

        $configuration = $config->configuration
            ?? UserGuideConfig::DEFAULT_CONFIGURATION;

        // 只返回当前指引的布尔值。
        return response()->json(
            $configuration[$location] ?? true
        );
    }

    /**
     * 更新新手指引状态或重置全部指引
     *
     * 传入具体指引名称：将对应值改为 false。
     * 传入 reset：将全部指引恢复为 true。
     */
    public function update(Request $request): JsonResponse
    {
        $uid = $this->uid();

        if ($uid === null) {
            return $this->error('请先登录', 401);
        }

        $validator = Validator::make($request->all(), [
            'location' => [
                'required',
                'string',
                'in:' . implode(',', [
                    ...UserGuideConfig::LOCATIONS,
                    'reset',
                ]),
            ],
        ]);

        if ($validator->fails()) {
            return $this->error('请求参数错误', 422, $validator->errors());
        }

        $location = $validator->validated()['location'];

        return DB::transaction(function () use ($uid, $location) {
            // 确保当前用户有配置记录。
            UserGuideConfig::firstOrCreate(
                ['uid' => $uid],
                ['configuration' => UserGuideConfig::DEFAULT_CONFIGURATION]
            );

            // 锁定当前用户的配置记录，避免并发更新互相覆盖。
            $config = UserGuideConfig::where('uid', $uid)
                ->lockForUpdate()
                ->firstOrFail();

            $configuration = $config->configuration
                ?? UserGuideConfig::DEFAULT_CONFIGURATION;

            if ($location === 'reset') {
                // 重置所有指引为默认状态。
                $configuration = UserGuideConfig::DEFAULT_CONFIGURATION;
            } else {
                // 只修改指定指引，保留其他配置。
                $configuration[$location] = false;
            }

            $config->configuration = $configuration;
            $config->save();

            return $this->success(
                null,
                $location === 'reset'
                    ? '新手指引已重置'
                    : '新手指引状态已更新'
            );
        });
    }
}
