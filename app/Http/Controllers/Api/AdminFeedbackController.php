<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminFeedbackIndexRequest;
use App\Http\Requests\Admin\AppendAdminFeedbackMessageRequest;
use App\Http\Requests\Admin\MarkAdminFeedbackReadRequest;
use App\Http\Requests\Admin\UpdateFeedbackStatusRequest;
use App\Models\Admin;
use App\Services\FeedbackImageService;
use App\Services\FeedbackService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFeedbackController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FeedbackService $feedbackService
    ) {}

    public function index(AdminFeedbackIndexRequest $request): JsonResponse
    {
        $paginator = $this->feedbackService->paginateForAdmin($request->validated());

        return $this->success(
            $this->feedbackService->listPayload($paginator, true),
            '获取意见反馈列表成功'
        );
    }

    public function statistics(): JsonResponse
    {
        return $this->success(
            $this->feedbackService->statistics(),
            '获取意见反馈统计成功'
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();

        if (! ($admin instanceof Admin)) {
            return $this->error('当前管理员身份无效', 403);
        }

        $feedback = $this->feedbackService->findForAdmin($id);

        return $this->success(
            $this->feedbackService->detailPayload(
                $feedback,
                FeedbackImageService::VIEWER_ADMIN,
                (int) $admin->id,
                true
            ),
            '获取意见反馈详情成功'
        );
    }

    public function storeMessage(
        AppendAdminFeedbackMessageRequest $request,
        int $id
    ): JsonResponse {
        $admin = $request->user();

        if (! ($admin instanceof Admin)) {
            return $this->error('当前管理员身份无效', 403);
        }

        $content = $request->input('content');

        $payload = $this->feedbackService->appendAdminMessage(
            (int) $admin->id,
            $id,
            is_string($content) && $content !== '' ? $content : null,
            array_values($request->file('images', []))
        );

        return $this->success($payload, '回复提交成功');
    }

    public function updateStatus(
        UpdateFeedbackStatusRequest $request,
        int $id
    ): JsonResponse {
        $feedback = $this->feedbackService->updateStatus(
            $id,
            (int) $request->input('status')
        );

        return $this->success([
            'status' => (int) $feedback->status,
            'status_name' => $feedback->statusName(),
            'updated_at' => $feedback->updated_at?->format('Y-m-d H:i:s'),
        ], '反馈状态更新成功');
    }

    public function markRead(
        MarkAdminFeedbackReadRequest $request,
        int $id
    ): JsonResponse {
        $payload = $this->feedbackService->markReadForAdmin(
            $id,
            (int) $request->input('through_message_id')
        );

        return $this->success($payload, '已标记为已读');
    }
}
