<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guild extends Model
{
    public const STATUS_DISSOLVED = 0;
    public const STATUS_ACTIVE = 1;
    public const CAPACITY = 30;

    protected $fillable = [
        'name',
        'description',
        'owner_uid',
        'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'owner_uid' => 'integer',
        'status' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_uid');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GuildMember::class, 'guild_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', GuildMember::STATUS_ACTIVE);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(GuildInvitation::class, 'guild_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(GuildApplication::class, 'guild_id');
    }

    public function months(): HasMany
    {
        return $this->hasMany(GuildMonth::class, 'guild_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(GuildLog::class, 'guild_id');
    }
}
