<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildMember extends Model
{
    public const ROLE_OWNER = 1;
    public const ROLE_VICE = 2;
    public const ROLE_MEMBER = 3;

    public const STATUS_LEFT = 0;
    public const STATUS_ACTIVE = 1;

    protected $fillable = [
        'guild_id',
        'uid',
        'role',
        'member_slot',
        'vice_slot',
        'status',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'guild_id' => 'integer',
        'uid' => 'integer',
        'role' => 'integer',
        'member_slot' => 'integer',
        'vice_slot' => 'integer',
        'status' => 'integer',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public static function roleName(int $role): string
    {
        return match ($role) {
            self::ROLE_OWNER => '会长',
            self::ROLE_VICE => '副会长',
            default => '普通成员',
        };
    }
}
