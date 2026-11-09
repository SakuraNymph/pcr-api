<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardStatistic extends Model
{
    protected $table = 'card_statistics';

    protected $fillable = [
        'uid',
        'account_id',
        'year',
        'data',
    ];

    protected $casts = [
        'uid' => 'integer',
        'account_id' => 'integer',
        'year' => 'integer',
        'data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(UserRolePool::class, 'account_id');
    }
}
