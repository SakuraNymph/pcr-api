<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildInvitation extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_ACCEPTED = 1;
    public const STATUS_REJECTED = 2;
    public const STATUS_REVOKED = 3;
    public const STATUS_EXPIRED = 4;
    public const STATUS_INVALID = 5;

    public const VALID_DAYS = 7;

    protected $fillable = [
        'guild_id',
        'inviter_uid',
        'invitee_uid',
        'status',
        'expires_at',
        'handled_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'guild_id' => 'integer',
        'inviter_uid' => 'integer',
        'invitee_uid' => 'integer',
        'status' => 'integer',
        'expires_at' => 'datetime',
        'handled_at' => 'datetime',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_uid');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_uid');
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
            self::STATUS_ACCEPTED => '已接受',
            self::STATUS_REJECTED => '已拒绝',
            self::STATUS_REVOKED => '已撤销',
            self::STATUS_EXPIRED => '已过期',
            default => '已失效',
        };
    }
}
