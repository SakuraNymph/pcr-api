<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemLogController extends Controller
{
    use ApiResponse;

    /**
     * 系统日志列表
     */
    public function index(Request $request): JsonResponse
    {
        $adminUsername = $request->input('admin_username');
        $module        = $request->input('module');
        $action        = $request->input('action');
        $targetType    = $request->input('target_type');
        $result        = $request->input('result');
        $createdStart  = $request->input('created_start');
        $createdEnd    = $request->input('created_end');

        $perPage = $request->input('per_page', 20);

        $query = SystemLog::query()
            ->orderByDesc('id');

        // 管理员用户名
        if ($adminUsername !== null && $adminUsername !== '') {
            $query->where(
                'admin_username',
                'like',
                '%' . $adminUsername . '%'
            );
        }

        // 模块
        if ($module !== null && $module !== '') {
            $query->where('module', $module);
        }

        // 操作
        if ($action !== null && $action !== '') {
            $query->where('action', $action);
        }

        // 操作对象类型
        if ($targetType !== null && $targetType !== '') {
            $query->where('target_type', $targetType);
        }

        // 操作结果
        if ($result !== null && $result !== '') {
            $query->where('result', $result);
        }

        // 开始时间
        if ($createdStart !== null && $createdStart !== '') {
            $query->where('created_at', '>=', $createdStart . ' 00:00:00');
        }

        // 结束时间
        if ($createdEnd !== null && $createdEnd !== '') {
            $query->where('created_at', '<=', $createdEnd . ' 23:59:59');
        }

        $logs = $query->paginate($perPage);

        return $this->success(
            $logs,
            '获取系统日志列表成功'
        );
    }

    /**
     * 系统日志详情
     */
    public function show(SystemLog $systemLog): JsonResponse
    {
        return $this->success(
            $systemLog,
            '获取系统日志详情成功'
        );
    }
}