<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRoleStatistics extends Model
{
    use HasFactory;

    protected $table = 'game_role_statistics';

    protected $fillable = [
        'game_role_id',
        'use_times',
    ];

    protected $casts = [
        'game_role_id' => 'integer',
        'use_times' => 'integer',
    ];

    public function gameRole(): BelongsTo
    {
        return $this->belongsTo(GameRole::class, 'game_role_id');
    }
}