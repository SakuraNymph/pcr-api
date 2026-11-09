<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * 允许批量赋值的字段
     */
    protected $fillable = [
        'ip',
        'country',
        'province',
        'city',
        'nickname',
        'avatar',
        'nickname_updated_at',
        'public_uid',
        'status',
        'is_subscribe',
        'email',
        'password',
    ];

    /**
     * 序列化时隐藏的字段
     */
    protected $hidden = [
        'password',
        'remember_token',
        '_token',
    ];

    /**
     * 字段类型转换
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'is_subscribe' => 'boolean',
            'avatar' => 'integer',
            'nickname_updated_at' => 'datetime',
            'public_uid' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function visitorTokens(): HasMany
    {
        return $this->hasMany(VisitorToken::class, 'uid');
    }

    public function guildMemberships(): HasMany
    {
        return $this->hasMany(GuildMember::class, 'uid');
    }

    public function activeGuildMembership(): HasOne
    {
        return $this->hasOne(GuildMember::class, 'uid')
            ->where('status', GuildMember::STATUS_ACTIVE);
    }

    public function ownedGuilds(): HasMany
    {
        return $this->hasMany(Guild::class, 'owner_uid');
    }

    public function activeOwnedGuild(): HasOne
    {
        return $this->hasOne(Guild::class, 'owner_uid')
            ->where('status', Guild::STATUS_ACTIVE);
    }
}
