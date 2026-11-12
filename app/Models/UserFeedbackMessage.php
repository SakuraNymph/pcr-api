<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserFeedbackMessage extends Model
{
    use HasFactory;

    public const SENDER_USER = 1;

    public const SENDER_ADMIN = 2;

    public const UPDATED_AT = null;

    protected $table = 'user_feedback_messages';

    protected $fillable = [
        'feedback_id',
        'sender_type',
        'sender_id',
        'content',
        'read_at',
    ];

    protected $hidden = [
        'sender_id',
    ];

    protected $casts = [
        'feedback_id' => 'integer',
        'sender_type' => 'integer',
        'sender_id' => 'integer',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(UserFeedback::class, 'feedback_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(UserFeedbackImage::class, 'message_id');
    }
}
