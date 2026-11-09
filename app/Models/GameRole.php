<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GameRole extends Model
{
    use HasFactory;

    protected $table = 'game_roles';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'role_id',
        'role_id_1',
        'role_id_3',
        'role_id_6',
        'element',
        'atk_type',
        'is_clan_battle',
        'name',
        'nickname',
        'search_area_width',
        'position',
        'obtain',
        'probability',
        'up_time',
        'status',
    ];

    protected $casts = [
        'id'                => 'integer',
        'role_id'           => 'integer',
        'role_id_1'         => 'integer',
        'role_id_3'         => 'integer',
        'role_id_6'         => 'integer',
        'element'           => 'integer',
        'atk_type'          => 'integer',
        'is_clan_battle'    => 'boolean',
        'search_area_width' => 'integer',
        'position'          => 'integer',
        'status'            => 'boolean',
    ];

    public function image(): HasOne
    {
        return $this->hasOne(GameRoleImage::class, 'game_role_id');
    }

    public function statistics(): HasOne
    {
        return $this->hasOne(GameRoleStatistics::class, 'game_role_id');
    }

    public function masterCoinShopRole(): HasOne
    {
        return $this->hasOne(MasterCoinShopRole::class, 'game_role_id');
    }
}
