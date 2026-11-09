<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterCoinShopRole extends Model
{
    protected $table = 'master_coin_shop_roles';

    protected $fillable = [
        'game_role_id',
        'type',
        'sort_order',
    ];

    protected $casts = [
        'game_role_id' => 'integer',
        'type' => 'integer',
        'sort_order' => 'integer',
    ];

    public function gameRole(): BelongsTo
    {
        return $this->belongsTo(GameRole::class, 'game_role_id');
    }
}
