<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRoleImage extends Model
{
    use HasFactory;

    protected $table = 'game_role_images';

    protected $fillable = [
        'game_role_id',
        'image_id',
        'is_6_star',
        'is_download',
    ];

    protected $casts = [
        'game_role_id' => 'integer',
        'image_id' => 'integer',
        'is_6_star' => 'boolean',
        'is_download' => 'boolean',
    ];

    public function gameRole(): BelongsTo
    {
        return $this->belongsTo(GameRole::class, 'game_role_id');
    }
}