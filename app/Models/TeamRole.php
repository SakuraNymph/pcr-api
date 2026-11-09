<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamRole extends Model
{
    protected $table = 'team_roles';

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'role_id',
        'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'team_id' => 'integer',
        'role_id' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 所属作业
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * 所属角色
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(GameRole::class, 'role_id');
    }
}
