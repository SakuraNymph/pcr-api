<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemTripleCache extends Model
{
    protected $table = 'system_triple_caches';

    protected $fillable = [
        'year_month',
        'mode',
        'team_ids',
        'team_count',
        'signature',
        'combinations',
    ];

    protected $casts = [
        'mode' => 'integer',
        'team_count' => 'integer',
        'combinations' => 'array',
    ];
}
