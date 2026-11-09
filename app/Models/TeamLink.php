<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamLink extends Model
{
    protected $table = 'team_links';

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'url',
        'author',
        'difficulty',
        'damage',
        'note',
        'status',
    ];

    protected $casts = [
        'id' => 'integer',
        'team_id' => 'integer',
        'difficulty' => 'integer',
        'damage' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 所属作业
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
