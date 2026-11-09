<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSixHitCacheSet extends Model
{
    protected $table = 'system_six_hit_cache_sets';

    protected $fillable = [
        'year_month',
        'mode',
        'algorithm_version',
        'source_signature',
        'low_team_count',
        'high_team_count',
        'low_triple_count',
        'high_triple_count',
        'generated_at',
    ];

    protected $casts = [
        'mode' => 'integer',
        'algorithm_version' => 'integer',
        'low_team_count' => 'integer',
        'high_team_count' => 'integer',
        'low_triple_count' => 'integer',
        'high_triple_count' => 'integer',
        'generated_at' => 'datetime',
    ];
}
