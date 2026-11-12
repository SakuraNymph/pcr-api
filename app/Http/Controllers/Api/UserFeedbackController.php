<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AppendUserFeedbackMessageRequest;
use App\Http\Requests\User\MarkUserFeedbackReadRequest;
use App\Http\Requests\User\StoreUserFeedbackRequest;
use App\Models\User;
use App\Services\FeedbackImageService;
use App\Services\FeedbackService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserFeedbackController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FeedbackService $feedbackService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'filter' => ['sometimes', 'in:all,unread'],
        ]);

        $paginator = $this->feedbackService->paginateForUser(
            $user,
            $validated['filter'] ?? 'all'
        );

        return $this->success(
            $this->feedbackService->listPayload($paginator),
            '获取意见反馈列表成功'
        );
    }

    public function store(StoreUserFeedbackRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        $feedback = $this->feedbackService->createForUser(
            $user,
            $request->validated(),
            array_values($request->file('images', []))
        );

        return $this->success(['id' => (int) $feedback->id], '意见反馈提交成功');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        $feedback = $this->feedbackService->findForUser($user, $id);

        return $this->success(
            $this->feedbackService->detailPayload(
                $feedback,
                FeedbackImageService::VIEWER_USER,
                (int) $user->id
            ),
            '获取意见反馈详情成功'
        );
    }

    public function storeMessage(
        AppendUserFeedbackMessageRequest $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        $content = $request->input('content');

        $payload = $this->feedbackService->appendUserMessage(
            $user,
            $id,
            is_string($content) && $content !== '' ? $content : null,
            array_values($request->file('images', []))
        );

        return $this->success($payload, '回复提交成功');
    }

    public function markRead(
        MarkUserFeedbackReadRequest $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return $this->error('当前用户身份无效', 403);
        }

        $payload = $this->feedbackService->markReadForUser(
            $user,
            $id,
            (int) $request->input('through_message_id')
        );

        return $this->success($payload, '已标记为已读');
    }
}
