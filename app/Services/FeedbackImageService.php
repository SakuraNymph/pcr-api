<?php

namespace App\Services;

use App\Models\UserFeedbackImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class FeedbackImageService
{
    public const VIEWER_USER = 'user';

    public const VIEWER_ADMIN = 'admin';

    private const SIGNED_URL_TTL_MINUTES = 30;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_WEBP,
    ];

    /**
     * 校验图片真实格式，不能只依赖前端或扩展名。
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function assertValidImages(array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'images' => '上传的图片格式不正确',
                ]);
            }

            $realPath = $file->getRealPath();
            $mimeType = $file->getMimeType();
            $imageInfo = $realPath ? @getimagesize($realPath) : false;

            if (
                ! is_string($mimeType)
                || ! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)
                || $imageInfo === false
                || ! in_array($imageInfo[2], self::ALLOWED_IMAGE_TYPES, true)
            ) {
                throw ValidationException::withMessages([
                    'images' => '仅支持 JPG、JPEG、PNG、WEBP 格式的图片',
                ]);
            }
        }
    }

    /**
     * 写入私有磁盘，返回可直接落库的图片元数据。
     */
    public function store(UploadedFile $file, int $feedbackId, int $messageId): array
    {
        $mimeType = (string) $file->getMimeType();
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages([
                'images' => '仅支持 JPG、JPEG、PNG、WEBP 格式的图片',
            ]),
        };

        $directory = "feedback/{$feedbackId}/{$messageId}";
        $fileName = Str::uuid()->toString().'.'.$extension;
        $path = Storage::disk('local')->putFileAs($directory, $file, $fileName);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('图片保存失败');
        }

        return [
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $mimeType,
            'file_size' => (int) $file->getSize(),
        ];
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function deletePaths(array $paths): void
    {
        $paths = array_values(array_filter($paths));

        if ($paths === []) {
            return;
        }

        Storage::disk('local')->delete($paths);
    }

    public function signedUrl(
        string $viewerType,
        int $viewerId,
        int $feedbackId,
        UserFeedbackImage $image
    ): string {
        $routeName = $viewerType === self::VIEWER_ADMIN
            ? 'admin.feedback.image'
            : 'user.feedback.image';

        return URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            [
                'image' => $image->id,
                'viewer' => $this->encodeViewer($viewerType, $viewerId, $feedbackId),
            ]
        );
    }

    public function encodeViewer(string $viewerType, int $viewerId, int $feedbackId): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'type' => $viewerType,
            'id' => $viewerId,
            'feedback_id' => $feedbackId,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{v:int,type:string,id:int,feedback_id:int}|null
     */
    public function resolveViewer(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $type = $payload['type'] ?? null;
        $id = $payload['id'] ?? null;
        $feedbackId = $payload['feedback_id'] ?? null;

        if (
            (int) ($payload['v'] ?? 0) !== 1
            || ! in_array($type, [self::VIEWER_USER, self::VIEWER_ADMIN], true)
            || ! is_numeric($id)
            || ! is_numeric($feedbackId)
        ) {
            return null;
        }

        return [
            'v' => 1,
            'type' => $type,
            'id' => (int) $id,
            'feedback_id' => (int) $feedbackId,
        ];
    }

    public function fileResponse(UserFeedbackImage $image): BinaryFileResponse
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($image->path)) {
            abort(404, '图片不存在');
        }

        $headers = [
            'Content-Type' => $image->mime_type,
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $response = response()->file($disk->path($image->path), $headers);
        $response->headers->set(
            'Content-Disposition',
            (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $image->original_name !== '' ? $image->original_name : 'image'
            )
        );

        return $response;
    }
}
