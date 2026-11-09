<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSixHitTriple extends Model
{
    protected $table = 'system_six_hit_triples';

    protected $fillable = [
        'cache_set_id',
        'side',
        'team_1_id',
        'team_2_id',
        'team_3_id',
        'team_ids',
        'boss_1',
        'boss_2',
        'boss_3',
        'score_tenths',
        'stage_2_count',
        'stage_3_count',
        'stage_4_count',
        'atk_positive_count',
        'atk_nonpositive_count',
    ];

    protected $casts = [
        'cache_set_id' => 'integer',
        'side' => 'integer',
        'team_1_id' => 'integer',
        'team_2_id' => 'integer',
        'team_3_id' => 'integer',
        'team_ids' => 'array',
        'boss_1' => 'integer',
        'boss_2' => 'integer',
        'boss_3' => 'integer',
        'score_tenths' => 'integer',
        'stage_2_count' => 'integer',
        'stage_3_count' => 'integer',
        'stage_4_count' => 'integer',
        'atk_positive_count' => 'integer',
        'atk_nonpositive_count' => 'integer',
    ];
}
