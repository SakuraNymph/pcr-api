<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeedback;
use App\Models\UserFeedbackMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeedbackService
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly FeedbackImageService $imageService,
        private readonly UserProfileService $userProfileService
    ) {}

    /**
     * @return LengthAwarePaginator<int, UserFeedback>
     */
    public function paginateForUser(User $user, string $filter = 'all'): LengthAwarePaginator
    {
        return UserFeedback::query()
            ->where('user_id', $user->id)
            ->when(
                $filter === 'unread',
                fn (Builder $query) => $this->whereHasUnread($query, UserFeedbackMessage::SENDER_ADMIN)
            )
            ->withCount([
                'messages as unread_count' => fn (Builder $query) => $query
                    ->where('sender_type', UserFeedbackMessage::SENDER_ADMIN)
                    ->whereNull('read_at'),
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    public function createForUser(User $user, array $data, array $files): UserFeedback
    {
        if ($user->public_uid === null) {
            throw ValidationException::withMessages([
                'feedback' => '当前账号暂无公开 UID，暂时无法提交反馈',
            ]);
        }

        $this->imageService->assertValidImages($files);

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($user, $data, $files, &$storedPaths): UserFeedback {
                $now = now();

                $feedback = UserFeedback::query()->create([
                    'user_id' => $user->id,
                    'public_uid' => (int) $user->public_uid,
                    'nickname' => $user->nickname,
                    'category' => (int) $data['category'],
                    'title' => (string) $data['title'],
                    'status' => UserFeedback::STATUS_PENDING,
                    'client_type' => $data['client_type'] ?? null,
                    'client_version' => $data['client_version'] ?? null,
                    'last_message_at' => $now,
                ]);

                $message = $this->createMessage(
                    $feedback,
                    UserFeedbackMessage::SENDER_USER,
                    (int) $user->id,
                    $data['content'] ?? null,
                    $files,
                    $storedPaths
                );

                return $feedback->fresh(['messages.images']);
            });
        } catch (\Throwable $exception) {
            $this->imageService->deletePaths($storedPaths);

            throw $exception;
        }
    }

    public function findForUser(User $user, int $feedbackId): UserFeedback
    {
        $feedback = UserFeedback::query()
            ->where('user_id', $user->id)
            ->whereKey($feedbackId)
            ->first();

        if (! $feedback instanceof UserFeedback) {
            abort(404, '意见反馈不存在');
        }

        return $feedback;
    }

    public function findForAdmin(int $feedbackId): UserFeedback
    {
        $feedback = UserFeedback::query()->whereKey($feedbackId)->first();

        if (! $feedback instanceof UserFeedback) {
            abort(404, '意见反馈不存在');
        }

        return $feedback;
    }

    public function appendUserMessage(
        User $user,
        int $feedbackId,
        ?string $content,
        array $files
    ): array {
        $feedback = $this->findForUser($user, $feedbackId);

        $this->imageService->assertValidImages($files);

        $storedPaths = [];

        try {
            $message = DB::transaction(function () use (
                $feedback,
                $user,
                $content,
                $files,
                &$storedPaths
            ): UserFeedbackMessage {
                // 用户追加消息时：待处理/处理中维持原状态，已回复/已关闭回到处理中
                if (in_array($feedback->status, [
                    UserFeedback::STATUS_REPLIED,
                    UserFeedback::STATUS_CLOSED,
                ], true)) {
                    $feedback->status = UserFeedback::STATUS_PROCESSING;
                }

                $feedback->last_message_at = now();
                $feedback->save();

                return $this->createMessage(
                    $feedback,
                    UserFeedbackMessage::SENDER_USER,
                    (int) $user->id,
                    $content,
                    $files,
                    $storedPaths
                );
            });
        } catch (\Throwable $exception) {
            $this->imageService->deletePaths($storedPaths);

            throw $exception;
        }

        $feedback->refresh();

        return [
            'message' => $this->messagePayload(
                $message->load('images'),
                $feedback,
                FeedbackImageService::VIEWER_USER,
                (int) $user->id
            ),
            'status' => (int) $feedback->status,
            'status_name' => $feedback->statusName(),
            'last_message_at' => $this->formatDateTime($feedback->last_message_at),
        ];
    }

    public function appendAdminMessage(
        int $adminId,
        int $feedbackId,
        ?string $content,
        array $files
    ): array {
        $feedback = $this->findForAdmin($feedbackId);

        $this->imageService->assertValidImages($files);

        $storedPaths = [];

        try {
            $message = DB::transaction(function () use (
                $feedback,
                $adminId,
                $content,
                $files,
                &$storedPaths
            ): UserFeedbackMessage {
                // 管理员回复不自动修改反馈状态
                $feedback->last_message_at = now();
                $feedback->save();

                return $this->createMessage(
                    $feedback,
                    UserFeedbackMessage::SENDER_ADMIN,
                    $adminId,
                    $content,
                    $files,
                    $storedPaths
                );
            });
        } catch (\Throwable $exception) {
            $this->imageService->deletePaths($storedPaths);

            throw $exception;
        }

        $feedback->refresh();

        return [
            'message' => $this->messagePayload(
                $message->load('images'),
                $feedback,
                FeedbackImageService::VIEWER_ADMIN,
                $adminId
            ),
            'status' => (int) $feedback->status,
            'status_name' => $feedback->statusName(),
            'last_message_at' => $this->formatDateTime($feedback->last_message_at),
        ];
    }

    public function markReadForUser(User $user, int $feedbackId, int $throughMessageId): array
    {
        $feedback = $this->findForUser($user, $feedbackId);

        return $this->markRead($feedback, UserFeedbackMessage::SENDER_ADMIN, $throughMessageId);
    }

    public function markReadForAdmin(int $feedbackId, int $throughMessageId): array
    {
        $feedback = $this->findForAdmin($feedbackId);

        return $this->markRead($feedback, UserFeedbackMessage::SENDER_USER, $throughMessageId);
    }

    public function updateStatus(int $feedbackId, int $status): UserFeedback
    {
        $feedback = $this->findForAdmin($feedbackId);
        $feedback->status = $status;
        $feedback->save();

        return $feedback;
    }

    /**
     * @return LengthAwarePaginator<int, UserFeedback>
     */
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        return UserFeedback::query()
            ->when(
                isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '',
                fn (Builder $query) => $query->where('status', (int) $filters['status'])
            )
            ->when(
                isset($filters['category']) && $filters['category'] !== null && $filters['category'] !== '',
                fn (Builder $query) => $query->where('category', (int) $filters['category'])
            )
            ->when(
                isset($filters['public_uid']) && $filters['public_uid'] !== null && $filters['public_uid'] !== '',
                fn (Builder $query) => $query->where('public_uid', (int) $filters['public_uid'])
            )
            ->when(
                filled($filters['nickname'] ?? null),
                fn (Builder $query) => $query->where('nickname', 'like', '%'.$filters['nickname'].'%')
            )
            ->when(
                filled($filters['title'] ?? null),
                fn (Builder $query) => $query->where('title', 'like', '%'.$filters['title'].'%')
            )
            ->when(
                filled($filters['start_date'] ?? null),
                fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['start_date'])
            )
            ->when(
                filled($filters['end_date'] ?? null),
                fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['end_date'])
            )
            ->when(
                (int) ($filters['has_unread'] ?? 0) === 1,
                fn (Builder $query) => $this->whereHasUnread($query, UserFeedbackMessage::SENDER_USER)
            )
            ->withCount([
                'messages as unread_count' => fn (Builder $query) => $query
                    ->where('sender_type', UserFeedbackMessage::SENDER_USER)
                    ->whereNull('read_at'),
            ])
            ->orderBy('status')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    public function statistics(): array
    {
        $counts = UserFeedback::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $pending = (int) ($counts[UserFeedback::STATUS_PENDING] ?? 0);
        $processing = (int) ($counts[UserFeedback::STATUS_PROCESSING] ?? 0);
        $replied = (int) ($counts[UserFeedback::STATUS_REPLIED] ?? 0);
        $closed = (int) ($counts[UserFeedback::STATUS_CLOSED] ?? 0);

        return [
            'pending' => $pending,
            'processing' => $processing,
            'replied' => $replied,
            'closed' => $closed,
            'total' => $pending + $processing + $replied + $closed,
        ];
    }

    public function listPayload(LengthAwarePaginator $paginator, bool $withUser = false): array
    {
        return [
            'items' => collect($paginator->items())
                ->map(fn (UserFeedback $feedback) => $this->listItemPayload($feedback, $withUser))
                ->values()
                ->all(),
            'page' => (int) $paginator->currentPage(),
            'per_page' => (int) $paginator->perPage(),
            'total' => (int) $paginator->total(),
            'last_page' => (int) $paginator->lastPage(),
        ];
    }

    public function detailPayload(
        UserFeedback $feedback,
        string $viewerType,
        int $viewerId,
        bool $withUser = false
    ): array {
        $feedback->load([
            'messages' => fn ($query) => $query->orderBy('id'),
            'messages.images' => fn ($query) => $query->orderBy('id'),
        ]);

        $unreadSender = $viewerType === FeedbackImageService::VIEWER_ADMIN
            ? UserFeedbackMessage::SENDER_USER
            : UserFeedbackMessage::SENDER_ADMIN;

        $unreadCount = $feedback->messages
            ->where('sender_type', $unreadSender)
            ->whereNull('read_at')
            ->count();

        $payload = [
            'id' => (int) $feedback->id,
            'category' => (int) $feedback->category,
            'category_name' => $feedback->categoryName(),
            'title' => $feedback->title,
            'status' => (int) $feedback->status,
            'status_name' => $feedback->statusName(),
            'client_type' => $feedback->client_type,
            'client_version' => $feedback->client_version,
            'created_at' => $this->formatDateTime($feedback->created_at),
            'last_message_at' => $this->formatDateTime($feedback->last_message_at),
            'unread_count' => $unreadCount,
            'messages' => $feedback->messages
                ->map(fn (UserFeedbackMessage $message) => $this->messagePayload(
                    $message,
                    $feedback,
                    $viewerType,
                    $viewerId
                ))
                ->values()
                ->all(),
        ];

        if ($withUser) {
            $payload['public_uid'] = (int) $feedback->public_uid;
            $payload['nickname'] = $feedback->nickname;
            $payload['user'] = $this->userPayload($feedback);
        }

        return $payload;
    }

    private function createMessage(
        UserFeedback $feedback,
        int $senderType,
        int $senderId,
        ?string $content,
        array $files,
        array &$storedPaths
    ): UserFeedbackMessage {
        $message = $feedback->messages()->create([
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'content' => $content,
            'read_at' => null,
        ]);

        foreach ($files as $file) {
            $metadata = $this->imageService->store($file, (int) $feedback->id, (int) $message->id);
            $storedPaths[] = $metadata['path'];
            $message->images()->create($metadata);
        }

        return $message;
    }

    private function markRead(
        UserFeedback $feedback,
        int $senderType,
        int $throughMessageId
    ): array {
        $readCount = $feedback->messages()
            ->where('sender_type', $senderType)
            ->whereNull('read_at')
            ->where('id', '<=', $throughMessageId)
            ->update(['read_at' => now()]);

        $unreadCount = $feedback->messages()
            ->where('sender_type', $senderType)
            ->whereNull('read_at')
            ->count();

        return [
            'read_count' => (int) $readCount,
            'unread_count' => (int) $unreadCount,
        ];
    }

    private function whereHasUnread(Builder $query, int $senderType): Builder
    {
        return $query->whereHas('messages', fn (Builder $messageQuery) => $messageQuery
            ->where('sender_type', $senderType)
            ->whereNull('read_at'));
    }

    private function listItemPayload(UserFeedback $feedback, bool $withUser): array
    {
        $payload = [
            'id' => (int) $feedback->id,
            'category' => (int) $feedback->category,
            'category_name' => $feedback->categoryName(),
            'title' => $feedback->title,
            'status' => (int) $feedback->status,
            'status_name' => $feedback->statusName(),
            'unread_count' => (int) ($feedback->unread_count ?? 0),
            'created_at' => $this->formatDateTime($feedback->created_at),
            'last_message_at' => $this->formatDateTime($feedback->last_message_at),
        ];

        if ($withUser) {
            $payload['public_uid'] = (int) $feedback->public_uid;
            $payload['nickname'] = $feedback->nickname;
        }

        return $payload;
    }

    private function messagePayload(
        UserFeedbackMessage $message,
        UserFeedback $feedback,
        string $viewerType,
        int $viewerId
    ): array {
        return [
            'id' => (int) $message->id,
            'sender_type' => (int) $message->sender_type,
            'content' => $message->content,
            'images' => $message->images
                ->map(fn ($image) => [
                    'id' => (int) $image->id,
                    'url' => $this->imageService->signedUrl(
                        $viewerType,
                        $viewerId,
                        (int) $feedback->id,
                        $image
                    ),
                    'original_name' => $image->original_name,
                    'mime_type' => $image->mime_type,
                    'file_size' => (int) $image->file_size,
                ])
                ->values()
                ->all(),
            'read_at' => $this->formatDateTime($message->read_at),
            'created_at' => $this->formatDateTime($message->created_at),
        ];
    }

    private function userPayload(UserFeedback $feedback): ?array
    {
        $user = $feedback->user;

        if (! $user instanceof User) {
            return null;
        }

        return [
            'public_uid' => $user->public_uid !== null ? (int) $user->public_uid : null,
            'nickname' => $user->nickname,
            'avatar_url' => $user->status === 1
                ? $this->userProfileService->avatarUrl($user->avatar !== null ? (int) $user->avatar : null)
                : null,
            'status' => (int) $user->status,
            'registered_at' => $this->formatDateTime($user->created_at),
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
