<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemTripleAccountCache extends Model
{
    protected $table = 'system_triple_account_caches';

    protected $fillable = [
        'year_month',
        'mode',
        'algorithm_version',
        'source_signature',
        'uid',
        'account_id',
        'role_pool_hash',
        'filter_hash',
        'filters',
        'results',
        'result_count',
        'generated_at',
    ];

    protected $casts = [
        'mode' => 'integer',
        'algorithm_version' => 'integer',
        'uid' => 'integer',
        'account_id' => 'integer',
        'filters' => 'array',
        'results' => 'array',
        'result_count' => 'integer',
        'generated_at' => 'datetime',
    ];
}
