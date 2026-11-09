<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildApplication extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;
    public const STATUS_WITHDRAWN = 3;
    public const STATUS_EXPIRED = 4;
    public const STATUS_INVALID = 5;

    public const VALID_DAYS = 7;

    protected $fillable = [
        'guild_id',
        'applicant_uid',
        'status',
        'expires_at',
        'handled_at',
        'handler_uid',
    ];

    protected $casts = [
        'id' => 'integer',
        'guild_id' => 'integer',
        'applicant_uid' => 'integer',
        'status' => 'integer',
        'expires_at' => 'datetime',
        'handled_at' => 'datetime',
        'handler_uid' => 'integer',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_uid');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handler_uid');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }

    public static function statusName(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => '待处理',
            self::STATUS_APPROVED => '已同意',
            self::STATUS_REJECTED => '已拒绝',
            self::STATUS_WITHDRAWN => '已撤销',
            self::STATUS_EXPIRED => '已过期',
            default => '已失效',
        };
    }
}
