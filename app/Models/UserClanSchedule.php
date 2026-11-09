<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserClanSchedule extends Model
{
    /**
     * 数据表
     */
    protected $table = 'user_clan_schedules';

    /**
     * 允许批量赋值的字段
     */
    protected $fillable = [
        'uid',
        'share_code',
        'cells',
        'player_order',
    ];

    /**
     * 字段类型转换
     */
    protected $casts = [
        'id' => 'integer',
        'uid' => 'integer',
        'share_code' => 'string',
        'cells' => 'array',
        'player_order' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_clan_schedule_members',
            'schedule_id',
            'uid'
        )->withTimestamps();
    }
}
