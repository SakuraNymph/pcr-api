<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class UserFeedback extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 0;

    public const STATUS_PROCESSING = 1;

    public const STATUS_REPLIED = 2;

    public const STATUS_CLOSED = 3;

    public const CATEGORY_USAGE = 1;

    public const CATEGORY_FEATURE = 2;

    public const CATEGORY_IMPROVEMENT = 3;

    public const CATEGORY_OTHER = 4;

    protected $table = 'user_feedback';

    protected $fillable = [
        'user_id',
        'public_uid',
        'nickname',
        'category',
        'title',
        'status',
        'client_type',
        'client_version',
        'last_message_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'public_uid' => 'integer',
        'category' => 'integer',
        'status' => 'integer',
        'last_message_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function categoryNames(): array
    {
        return [
            self::CATEGORY_USAGE => '使用问题',
            self::CATEGORY_FEATURE => '功能建议',
            self::CATEGORY_IMPROVEMENT => '改进建议',
            self::CATEGORY_OTHER => '其他',
        ];
    }

    public static function statusNames(): array
    {
        return [
            self::STATUS_PENDING => '待处理',
            self::STATUS_PROCESSING => '处理中',
            self::STATUS_REPLIED => '已回复',
            self::STATUS_CLOSED => '已关闭',
        ];
    }

    public function categoryName(): string
    {
        return self::categoryNames()[$this->category] ?? '未知分类';
    }

    public function statusName(): string
    {
        return self::statusNames()[$this->status] ?? '未知状态';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(UserFeedbackMessage::class, 'feedback_id');
    }

    public function images(): HasManyThrough
    {
        return $this->hasManyThrough(
            UserFeedbackImage::class,
            UserFeedbackMessage::class,
            'feedback_id',
            'message_id'
        );
    }
}
