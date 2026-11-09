<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuildMonth extends Model
{
    protected $fillable = [
        'guild_id',
        'year_month',
    ];

    protected $casts = [
        'id' => 'integer',
        'guild_id' => 'integer',
    ];

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class, 'guild_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GuildMonthMember::class, 'month_id');
    }
}
