<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuildMonthMember extends Model
{
    protected $fillable = [
        'month_id',
        'guild_id',
        'uid',
        'nickname',
        'role',
        'member_slot',
        'team_1',
        'team_1_return',
        'team_2',
        'team_2_return',
        'team_3',
        'team_3_return',
        'version',
    ];

    protected $casts = [
        'id' => 'integer',
        'month_id' => 'integer',
        'guild_id' => 'integer',
        'uid' => 'integer',
        'role' => 'integer',
        'member_slot' => 'integer',
        'team_1' => 'integer',
        'team_1_return' => 'integer',
        'team_2' => 'integer',
        'team_2_return' => 'integer',
        'team_3' => 'integer',
        'team_3_return' => 'integer',
        'version' => 'integer',
    ];

    public function month(): BelongsTo
    {
        return $this->belongsTo(GuildMonth::class, 'month_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }
}
