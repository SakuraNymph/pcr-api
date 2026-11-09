<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BossService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class BossController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BossService $bossService
    ) {}

    /**
     * 获取用户端 BOSS 列表。
     *
     * GET /api/game/bosses?period=current
     * GET /api/game/bosses?period=previous
     */
    public function index(Request $request): JsonResponse
    {
        $period = $this->validatedPeriod($request);

        return $this->success(
            $this->bossService->publicSeats($period),
            '获取 BOSS 列表成功'
        );
    }

    /**
     * 获取管理端 BOSS 王位和候选。
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $period = $this->validatedPeriod($request);

        return $this->success(
            $this->bossService->adminOverview($period),
            '获取 BOSS 管理数据成功'
        );
    }

    /**
     * 切换本月或上月指定王位当前展示的 BOSS。
     */
    public function select(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'boss_id' => [
                'required',
                'integer',
            ],
            'sort' => [
                'required',
                'integer',
                'between:1,5',
            ],
            'period' => [
                'sometimes',
                'string',
                Rule::in(['current', 'previous']),
            ],
        ], [
            'boss_id.required' => '请选择 BOSS',
            'boss_id.integer' => 'BOSS ID 必须是整数',
            'sort.required' => '请选择王位',
            'sort.integer' => '王位必须是整数',
            'sort.between' => '王位必须在 1 到 5 之间',
        ]);

        try {
            $period = $validated['period'] ?? 'current';
            $seat = $this->bossService->selectBoss(
                (int) $validated['boss_id'],
                (int) $validated['sort'],
                $period
            );

            return $this->success(
                $seat,
                ($period === 'previous' ? '上月' : '本月')
                    .'BOSS 切换成功'
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('切换 BOSS 失败', [
                'exception' => $exception,
            ]);

            return $this->error('操作失败，请稍后重试', 500);
        }
    }

    private function validatedPeriod(Request $request): string
    {
        $validated = $request->validate([
            'period' => [
                'sometimes',
                'string',
                Rule::in(['current', 'previous']),
            ],
        ]);

        return $validated['period'] ?? 'current';
    }
}
