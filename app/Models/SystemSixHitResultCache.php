<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSixHitResultCache extends Model
{
    protected $table = 'system_six_hit_result_caches';

    protected $fillable = [
        'cache_set_id',
        'endpoint',
        'uid',
        'account_id',
        'role_pool_hash',
        'filter_hash',
        'cache_key',
        'filters',
        'results',
        'result_count',
        'generated_at',
    ];

    protected $casts = [
        'cache_set_id' => 'integer',
        'endpoint' => 'integer',
        'uid' => 'integer',
        'account_id' => 'integer',
        'filters' => 'array',
        'results' => 'array',
        'result_count' => 'integer',
        'generated_at' => 'datetime',
    ];
}
