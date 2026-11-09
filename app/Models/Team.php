<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $table = 'teams';

    protected $fillable = [
        'stage',
        'source_team_id',
        'uid',
        'boss',
        'damage',
        'team_key',
        'status',
        'mode',
        'atk_value',
        'remark',
        'import_timestamp',
    ];

    protected $casts = [
        'id' => 'integer',
        'stage' => 'integer',
        'source_team_id' => 'integer',
        'uid' => 'integer',
        'boss' => 'integer',
        'damage' => 'integer',
        'status' => 'integer',
        'mode' => 'integer',
        'atk_value' => 'integer',
        'import_timestamp' => 'integer',
    ];

    /**
     * 作业中的角色
     */
    public function teamRoles(): HasMany
    {
        return $this->hasMany(TeamRole::class, 'team_id');
    }

    /**
     * 作业中的来源链接
     */
    public function links(): HasMany
    {
        return $this->hasMany(TeamLink::class, 'team_id');
    }
}
