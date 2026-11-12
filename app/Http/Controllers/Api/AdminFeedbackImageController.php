<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\UserFeedback;
use App\Models\UserFeedbackImage;
use App\Services\FeedbackImageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminFeedbackImageController extends Controller
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

        if ($viewer === null || $viewer['type'] !== FeedbackImageService::VIEWER_ADMIN) {
            return $this->error('图片访问凭证无效', 403);
        }

        $admin = Admin::query()->find($viewer['id']);

        if (! $admin instanceof Admin || (int) $admin->status !== 1) {
            return $this->error('管理员账号状态无效，无法访问图片', 403);
        }

        $isSuperAdmin = $admin->roles()
            ->where('code', 'super_admin')
            ->where('status', 1)
            ->exists();

        if (! $isSuperAdmin) {
            return $this->error('当前接口仅允许超级管理员访问', 403);
        }

        $feedback = UserFeedback::query()->find($viewer['feedback_id']);

        if (! $feedback instanceof UserFeedback) {
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
