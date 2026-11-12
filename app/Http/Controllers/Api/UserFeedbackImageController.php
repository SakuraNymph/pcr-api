<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFeedback;
use App\Models\UserFeedbackImage;
use App\Services\FeedbackImageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserFeedbackImageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FeedbackImageService $imageService
    ) {}

    public function show(Request $request, int $image): BinaryFileResponse|JsonResponse
    {
        $viewer = $this->imageService->resolveViewer(
            (string) $request->query('viewer', '')
        );

        if ($viewer === null || $viewer['type'] !== FeedbackImageService::VIEWER_USER) {
            return $this->error('图片访问凭证无效', 403);
        }

        $user = User::query()->find($viewer['id']);

        if (! $user instanceof User || (int) $user->status !== 1) {
            return $this->error('账号状态无效，无法访问图片', 403);
        }

        $feedback = UserFeedback::query()->find($viewer['feedback_id']);

        if (! $feedback instanceof UserFeedback || (int) $feedback->user_id !== (int) $user->id) {
            return $this->error('无权访问该图片', 403);
        }

        $imageModel = UserFeedbackImage::query()->find($image);

        if (
            ! $imageModel instanceof UserFeedbackImage
            || (int) optional($imageModel->message)->feedback_id !== (int) $feedback->id
        ) {
            return $this->error('图片不存在', 404);
        }

        return $this->imageService->fileResponse($imageModel);
    }
}
